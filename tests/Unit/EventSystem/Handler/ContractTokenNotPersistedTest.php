<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\EventSystem\Handler;

use OxidEsales\PaymentBase\Adapter\ShopAdapterInterface;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Service\TokenServiceInterface;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieAmountDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MolliePaymentDto;
use OxidEsales\Payments\Mollie\Adapter\MolliePaymentsAdapterInterface;
use OxidEsales\Payments\Mollie\EventSystem\Event\MollieCheckoutSessionRequestEvent;
use OxidEsales\Payments\Mollie\EventSystem\Handler\MollieCheckoutSessionHandler;
use OxidEsales\Payments\Mollie\Service\CheckoutPaymentServiceInterface;
use OxidEsales\Payments\Mollie\Adapter\Dto\CreatePaymentRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 11 Story 10 (F18) — the return URL carries a live `contract_token`, so it must not be
 * persisted into the contract as if it were Mollie's checkout URL.
 *
 * `setProvider(..., $payment->checkoutUrl ?? $redirectUrl)` and the matching `setMetadata()` wrote
 * our OWN return URL — complete with the HMAC bearer token that authorises the return leg — into the
 * contract's provider-redirect column and into `OXMETADATA` whenever Mollie returned no checkout URL
 * (the inline-card-cleared-without-3DS path). The fallback was also redundant: `resolveDestination()`
 * had already computed the correct destination, `setProvider()`'s third parameter is nullable, and
 * nothing in the module ever reads either value back.
 */
#[CoversClass(MollieCheckoutSessionHandler::class)]
#[Group('F18')]
final class ContractTokenNotPersistedTest extends TestCase
{
    private const TOKEN = 'tok_secret_bearer_value';

    public function testNoCheckoutUrlMeansNoTokenBearingUrlIsPersisted(): void
    {
        $persisted = [];

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('contract-1');
        $contract->method('setProvider')->willReturnCallback(
            function (string $provider, string $providerOrderId, ?string $redirectUrl) use (&$persisted): void {
                $persisted['provider_redirect_url'] = $redirectUrl;
            },
        );
        $contract->method('setMetadata')->willReturnCallback(
            function (string $key, mixed $value) use (&$persisted): void {
                $persisted[$key] = $value;
            },
        );

        // Inline card that cleared without 3DS: Mollie returns no checkoutUrl.
        $this->handle($contract, checkoutUrl: null, cardToken: 'tkn_card');

        foreach ($persisted as $key => $value) {
            self::assertIsNotStringContainingToken($value, $key);
        }
    }

    public function testARealMollieCheckoutUrlIsStillPersisted(): void
    {
        $persisted = [];

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('contract-1');
        $contract->method('setProvider')->willReturnCallback(
            function (string $provider, string $providerOrderId, ?string $redirectUrl) use (&$persisted): void {
                $persisted['provider_redirect_url'] = $redirectUrl;
            },
        );
        $contract->method('setMetadata')->willReturnCallback(
            function (string $key, mixed $value) use (&$persisted): void {
                $persisted[$key] = $value;
            },
        );

        $this->handle($contract, checkoutUrl: 'https://www.mollie.com/checkout/tr_abc', cardToken: null);

        self::assertSame('https://www.mollie.com/checkout/tr_abc', $persisted['provider_redirect_url']);
        self::assertSame('https://www.mollie.com/checkout/tr_abc', $persisted['mollie_checkout_url']);
    }

    private static function assertIsNotStringContainingToken(mixed $value, string $key): void
    {
        if (!is_string($value)) {
            self::assertTrue(true);
            return;
        }

        self::assertStringNotContainsString(
            self::TOKEN,
            $value,
            sprintf('the contract token must not be persisted (found in "%s")', $key),
        );
        self::assertStringNotContainsString('contract_token=', $value, sprintf('in "%s"', $key));
    }

    private function handle(PaymentContractInterface $contract, ?string $checkoutUrl, ?string $cardToken): void
    {
        $checkoutPaymentService = $this->createMock(CheckoutPaymentServiceInterface::class);
        $checkoutPaymentService->method('buildCreatePaymentRequest')->willReturn(new CreatePaymentRequest(
            amount: new MollieAmountDto('EUR', 10.0),
            description: 'order',
            redirectUrl: 'https://shop.test/return',
        ));

        $paymentsAdapter = $this->createMock(MolliePaymentsAdapterInterface::class);
        $paymentsAdapter->method('createPayment')->willReturn(new MolliePaymentDto(
            id: 'tr_abc',
            status: 'open',
            amount: new MollieAmountDto('EUR', 10.0),
            checkoutUrl: $checkoutUrl,
        ));

        $tokenService = $this->createMock(TokenServiceInterface::class);
        $tokenService->method('generateToken')->willReturn(self::TOKEN);

        $shopAdapter = $this->createMock(ShopAdapterInterface::class);
        $shopAdapter->method('getShopUrl')->willReturn('https://shop.test');

        $handler = new MollieCheckoutSessionHandler(
            $checkoutPaymentService,
            $paymentsAdapter,
            $this->createMock(ContractRepositoryInterface::class),
            $tokenService,
            $shopAdapter,
        );

        $context = new EventContext(['cardToken' => $cardToken]);
        $context->setContract($contract);

        $handler->handle(new MollieCheckoutSessionRequestEvent($context));
    }
}
