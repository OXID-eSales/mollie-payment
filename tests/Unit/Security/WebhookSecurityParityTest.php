<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Security;

use OxidEsales\PaymentBase\Repository\WebhookLogRepositoryInterface;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieAmountDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MolliePaymentDto;
use OxidEsales\Payments\Mollie\Adapter\Exception\MollieAdapterException;
use OxidEsales\Payments\Mollie\Adapter\MollieStatusMapper;
use OxidEsales\Payments\Mollie\Adapter\MollieWebhookAdapterInterface;
use OxidEsales\Payments\Mollie\Controller\Webhook\WebhookHttpsGuard;
use OxidEsales\Payments\Mollie\Controller\Webhook\WebhookIpAllowlistGuard;
use OxidEsales\Payments\Mollie\Controller\Webhook\WebhookPayloadSizeGuard;
use OxidEsales\Payments\Mollie\Controller\Webhook\WebhookRateLimitGuard;
use OxidEsales\Payments\Mollie\Controller\Webhook\WebhookRequest;
use OxidEsales\Payments\Mollie\Service\ContractTokenService;
use OxidEsales\Payments\Mollie\Service\ModuleConfigurationServiceInterface;
use OxidEsales\Payments\Mollie\Webhook\MollieWebhookProcessor;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Sprint 8 Story 1 — security parity sweep, webhook-facing findings.
 *
 * See `docs/security/f-matrix.md` for the full PayPal F1–F25 mapping. This file collects the
 * findings whose surface is the Mollie webhook endpoint; each test method is named after the
 * F-item it locks in, mirroring PayPal's `tests/Unit/Security/F*.php` shape.
 */
#[Group('security')]
final class WebhookSecurityParityTest extends TestCase
{
    #[Group('F1')]
    public function testF1_WebhookHttpsEnforced(): void
    {
        $guard = new WebhookHttpsGuard(allowHttpForDevelopment: false);

        $result = $guard->check(new WebhookRequest('http', '1.2.3.4', 10, 'id=tr_x'));

        self::assertFalse($result->ok);
    }

    #[Group('F2')]
    public function testF2_PayloadSizeCapped(): void
    {
        $guard = new WebhookPayloadSizeGuard(maxBytes: 10);

        $result = $guard->check(new WebhookRequest('https', '1.2.3.4', 100, str_repeat('x', 100)));

        self::assertFalse($result->ok);
        self::assertSame(413, $result->httpStatus);
    }

    /**
     * F3 — source IP allowlist. Not wired into the default guard chain (Mollie publishes no
     * fixed webhook source ranges — see {@see WebhookIpAllowlistGuard}'s docblock), but the guard
     * class itself must behave correctly for merchants who opt in via services.yaml.
     */
    #[Group('F3')]
    public function testF3_IpAllowlistGuardRejectsUnlistedSource(): void
    {
        $guard = new WebhookIpAllowlistGuard(['173.0.80.0/20']);

        $result = $guard->check(new WebhookRequest('https', '8.8.8.8', 10, 'id=tr_x'));

        self::assertFalse($result->ok);
        self::assertSame(403, $result->httpStatus);
    }

    #[Group('F17')]
    public function testF17_RateLimited(): void
    {
        $guard = new WebhookRateLimitGuard(burst: 1.0, refillRatePerSecond: 0.0);
        $request = new WebhookRequest('https', '5.5.5.5', 10, 'id=tr_x');

        $guard->check($request);
        $result = $guard->check($request);

        self::assertFalse($result->ok);
        self::assertSame(429, $result->httpStatus);
    }

    /**
     * F18 — webhook auth bypass. Mollie sends no signature; verification IS the re-fetch (see
     * {@see MollieWebhookProcessor} docblock). A payment id that Mollie's API doesn't recognise
     * as ours (wrong id, wrong merchant, replay of a stale/forged id) must fail verification —
     * and, critically, must never reach the idempotency claim, i.e. never touch a contract.
     */
    #[Group('F18')]
    public function testF18_VerificationByApiFetch_NotForgeable(): void
    {
        $logRepository = $this->createMock(WebhookLogRepositoryInterface::class);
        $logRepository->expects(self::never())->method('claimEvent');

        $webhookAdapter = $this->createMock(MollieWebhookAdapterInterface::class);
        $webhookAdapter->method('fetchByWebhookId')->willThrowException(
            new MollieAdapterException('not found'),
        );

        $processor = new MollieWebhookProcessor(
            $logRepository,
            new NullLogger(),
            $webhookAdapter,
            new MollieStatusMapper(),
            [],
        );

        $result = $processor->process(new \OxidEsales\PaymentBase\Webhook\WebhookRequest(
            payload: 'id=tr_forged',
            signature: '',
            remoteIp: '1.2.3.4',
            receivedAt: new \DateTimeImmutable(),
        ));

        self::assertFalse($result->isSuccess());
    }

    /**
     * F18 (positive path) — a payment Mollie's API does confirm belongs to us is processed
     * normally, proving the guard above isn't simply rejecting everything.
     */
    #[Group('F18')]
    public function testF18_GenuinePaymentIsAccepted(): void
    {
        $logRepository = $this->createMock(WebhookLogRepositoryInterface::class);
        $logRepository->method('claimEvent')->willReturn(true);

        $webhookAdapter = $this->createMock(MollieWebhookAdapterInterface::class);
        $webhookAdapter->method('fetchByWebhookId')->willReturn(new MolliePaymentDto(
            id: 'tr_real',
            status: 'paid',
            amount: new MollieAmountDto('EUR', 10.0),
        ));

        $processor = new MollieWebhookProcessor(
            $logRepository,
            new NullLogger(),
            $webhookAdapter,
            new MollieStatusMapper(),
            [],
        );

        $result = $processor->process(new \OxidEsales\PaymentBase\Webhook\WebhookRequest(
            payload: 'id=tr_real',
            signature: '',
            remoteIp: '1.2.3.4',
            receivedAt: new \DateTimeImmutable(),
        ));

        // No handler is registered for "paid" in this test, so the outcome is "skipped", not a
        // failure — the point here is that verification succeeded and processing was attempted.
        self::assertNotSame('signature_invalid', $result->action);
    }

    /**
     * F20 — session fixation on the return URL. Mollie's exact analog is the HMAC-secured
     * contract token embedded in the redirectUrl (see {@see ContractTokenService}): a tampered
     * token (wrong contract id, flipped character) must never validate.
     */
    #[Group('F20')]
    public function testF20_ReturnTokenTamperRejected(): void
    {
        $config = $this->createMock(ModuleConfigurationServiceInterface::class);
        $config->method('getApiKey')->willReturn('test_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        $tokenService = new ContractTokenService($config);

        $token = $tokenService->generateToken('contract-real');

        self::assertTrue($tokenService->validateToken($token, 'contract-real'));
        self::assertFalse($tokenService->validateToken($token, 'contract-injected'));

        $tamperedToken = substr($token, 0, -1) . (str_ends_with($token, 'a') ? 'b' : 'a');
        self::assertFalse($tokenService->validateToken($tamperedToken, 'contract-real'));
    }

    /**
     * F24 — webhook payload schema validation. Mollie's payload is a single `id` field; a
     * missing or empty id must be rejected before any API call is attempted.
     */
    #[Group('F24')]
    public function testF24_MalformedWebhookPayloadRejected(): void
    {
        $webhookAdapter = $this->createMock(MollieWebhookAdapterInterface::class);
        $webhookAdapter->expects(self::never())->method('fetchByWebhookId');

        $processor = new MollieWebhookProcessor(
            $this->createMock(WebhookLogRepositoryInterface::class),
            new NullLogger(),
            $webhookAdapter,
            new MollieStatusMapper(),
            [],
        );

        $result = $processor->process(new \OxidEsales\PaymentBase\Webhook\WebhookRequest(
            payload: '',
            signature: '',
            remoteIp: '1.2.3.4',
            receivedAt: new \DateTimeImmutable(),
        ));

        self::assertFalse($result->isSuccess());
        self::assertSame('signature_invalid', $result->action);
    }
}
