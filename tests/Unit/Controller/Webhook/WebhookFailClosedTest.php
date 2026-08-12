<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Controller\Webhook;

use OxidEsales\PaymentBase\Repository\WebhookLogRepositoryInterface;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieAmountDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MolliePaymentDto;
use OxidEsales\Payments\Mollie\Adapter\MollieStatusMapper;
use OxidEsales\Payments\Mollie\Adapter\MollieWebhookAdapterInterface;
use OxidEsales\Payments\Mollie\Controller\Webhook\WebhookController;
use OxidEsales\Payments\Mollie\Controller\Webhook\WebhookGuardResult;
use OxidEsales\Payments\Mollie\Controller\Webhook\WebhookRequest;
use OxidEsales\Payments\Mollie\Controller\Webhook\WebhookRequestGuardInterface;
use OxidEsales\Payments\Mollie\Webhook\MollieWebhookProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Sprint 11 Stories 5 + 6 (F3, F5, F6) — the webhook endpoint's guards must fail closed.
 *
 * Three separate fallbacks converged on this controller:
 *
 * - **F3** `$guardResult = $this->getGuard()?->check(...)` followed by `if ($guardResult !== null
 *   && !$guardResult->ok)`. An unbuildable guard service made `$guardResult` null and the whole
 *   chain — HTTPS, payload size, rate limit, IP allowlist — silently evaporated while the endpoint
 *   kept processing. Three lines below, a missing *processor* was fail-closed with a 500.
 * - **F5** `X-Forwarded-Proto: https` was trusted unconditionally, so any client could tell the
 *   HTTPS guard that a plaintext request was encrypted.
 * - **F6** the rate-limit guard kept token buckets in per-request memory and could never fire, so
 *   the exposure it was aiming at (an unauthenticated POST costing us a Mollie API round-trip) was
 *   never actually closed. A stateless id precheck closes it instead.
 */
#[CoversClass(WebhookController::class)]
#[Group('F3')]
final class WebhookFailClosedTest extends TestCase
{
    public function testUnavailableGuardChainIsRejectedInsteadOfBypassed(): void
    {
        $controller = new TestableWebhookController(
            processor: $this->processorThatMustNotRun(),
            testGuard: null,
            testPaymentId: 'tr_x',
        );

        try {
            $controller->render();
            self::fail('Expected render() to terminate via sendResponse()');
        } catch (WebhookResponseSent $response) {
            self::assertSame(503, $response->statusCode, 'an unbuildable guard chain must not be a bypass');
            self::assertSame('guard_unavailable', $response->action);
        }
    }

    public function testPassingGuardChainStillProcesses(): void
    {
        $guard = $this->createMock(WebhookRequestGuardInterface::class);
        $guard->method('check')->willReturn(WebhookGuardResult::pass());

        $controller = new TestableWebhookController(
            processor: $this->processor(),
            testGuard: $guard,
            testPaymentId: 'tr_paid',
        );

        try {
            $controller->render();
            self::fail('Expected render() to terminate via sendResponse()');
        } catch (WebhookResponseSent $response) {
            self::assertSame(200, $response->statusCode);
        }
    }

    // --- F5: proxy header trust ---

    public function testForwardedProtoIsIgnoredWhenProxyTrustIsDisabled(): void
    {
        $scheme = TestableWebhookController::schemeFor(
            server: ['HTTP_X_FORWARDED_PROTO' => 'https'],
            trustProxyHeaders: false,
        );

        self::assertSame(
            'http',
            $scheme,
            'a shop that terminates TLS at the origin can harden this, and then the header must not '
            . 'satisfy the guard',
        );
    }

    public function testForwardedProtoIsHonouredWhenProxyTrustIsEnabled(): void
    {
        $scheme = TestableWebhookController::schemeFor(
            server: ['HTTP_X_FORWARDED_PROTO' => 'https'],
            trustProxyHeaders: true,
        );

        self::assertSame('https', $scheme);
    }

    /**
     * Shipped as `false`, reversed after running it against the real deployment: behind a
     * TLS-terminating proxy (Cloudflare here) the origin sees `HTTPS` unset and port 80, so trust-off
     * rejected EVERY genuine Mollie delivery with `400 tls_required` — reintroducing the silent
     * "order never finalizes" failure that F1/F2 exist to prevent, in exchange for a transport check
     * whose value is thin on an endpoint that is unauthenticated by design and verified by an API
     * re-fetch. See WebhookController::TRUST_PROXY_HEADERS_DEFAULT.
     */
    public function testProxyTrustDefaultsToOn_SoProxiedShopsKeepReceivingWebhooks(): void
    {
        self::assertTrue(
            WebhookController::TRUST_PROXY_HEADERS_DEFAULT,
            'a reverse-proxied shop must keep receiving Mollie webhooks',
        );
    }

    public function testRealTlsIsStillDetectedWithoutProxyTrust(): void
    {
        self::assertSame(
            'https',
            TestableWebhookController::schemeFor(server: ['HTTPS' => 'on'], trustProxyHeaders: false),
        );
    }

    // --- F6: stateless id precheck before the API round-trip ---

    #[DataProvider('implausibleIds')]
    public function testImplausiblePaymentIdIsRejectedBeforeAnyApiCall(string $raw): void
    {
        $webhookAdapter = $this->createMock(MollieWebhookAdapterInterface::class);
        $webhookAdapter->expects(self::never())
            ->method('fetchByWebhookId');

        $controller = new TestableWebhookController(
            processor: $this->processorWith($webhookAdapter),
            testGuard: $this->passingGuard(),
            testPaymentId: $raw,
        );

        try {
            $controller->render();
            self::fail('Expected render() to terminate via sendResponse()');
        } catch (WebhookResponseSent $response) {
            self::assertSame(400, $response->statusCode);
            self::assertSame('missing_id', $response->action);
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function implausibleIds(): array
    {
        return [
            'no tr_ prefix' => ['abc123'],
            'wrong prefix' => ['pi_3NqL2k'],
            'prefix only' => ['tr_'],
            'path traversal' => ['tr_../../etc/passwd'],
            'sql-ish' => ["tr_1' OR '1'='1"],
            'too long' => ['tr_' . str_repeat('a', 200)],
            'whitespace' => ['tr_ab cd'],
        ];
    }

    public function testPlausibleIdStillReachesTheApi(): void
    {
        $controller = new TestableWebhookController(
            processor: $this->processor(),
            testGuard: $this->passingGuard(),
            testPaymentId: 'tr_WDqYK6vllg',
        );

        try {
            $controller->render();
            self::fail('Expected render() to terminate via sendResponse()');
        } catch (WebhookResponseSent $response) {
            self::assertNotSame('missing_id', $response->action);
        }
    }

    private function passingGuard(): WebhookRequestGuardInterface
    {
        $guard = $this->createMock(WebhookRequestGuardInterface::class);
        $guard->method('check')->willReturn(WebhookGuardResult::pass());

        return $guard;
    }

    private function processorThatMustNotRun(): MollieWebhookProcessor
    {
        $webhookAdapter = $this->createMock(MollieWebhookAdapterInterface::class);
        $webhookAdapter->expects(self::never())->method('fetchByWebhookId');

        return $this->processorWith($webhookAdapter);
    }

    private function processor(): MollieWebhookProcessor
    {
        $webhookAdapter = $this->createMock(MollieWebhookAdapterInterface::class);
        $webhookAdapter->method('fetchByWebhookId')->willReturn(new MolliePaymentDto(
            id: 'tr_paid',
            status: 'paid',
            amount: new MollieAmountDto('EUR', 10.0),
        ));

        return $this->processorWith($webhookAdapter);
    }

    private function processorWith(MollieWebhookAdapterInterface $webhookAdapter): MollieWebhookProcessor
    {
        $logRepository = $this->createMock(WebhookLogRepositoryInterface::class);
        $logRepository->method('claimEvent')->willReturn(true);

        return new MollieWebhookProcessor(
            $logRepository,
            new NullLogger(),
            $webhookAdapter,
            new MollieStatusMapper(),
            [],
        );
    }
}
