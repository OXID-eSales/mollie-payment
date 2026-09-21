<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Service;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieAddressDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieAmountDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieLineDto;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\Service\CheckoutPaymentService;
use OxidEsales\Payments\Mollie\Service\ModuleConfigurationServiceInterface;
use OxidEsales\Payments\Mollie\Service\MollieWebhookUrlProviderInterface;
use OxidEsales\Payments\Mollie\Service\MollieOrderDataProviderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(CheckoutPaymentService::class)]
final class CheckoutPaymentServiceTest extends TestCase
{
    public function testBuildCreatePaymentRequestUsesAmountFromContract(): void
    {
        $contract = $this->contractStub(amount: 42.5, currency: 'EUR');
        $service = $this->service();

        $request = $service->buildCreatePaymentRequest($contract, 'ideal', 'https://shop.test/return');

        self::assertSame('EUR', $request->amount->currency);
        self::assertSame(42.5, $request->amount->value);
        self::assertSame('ideal', $request->method);
        self::assertSame('https://shop.test/return', $request->redirectUrl);
    }

    public function testBuildCreatePaymentRequestUsesOrderNumberAsDescription(): void
    {
        $contract = $this->contractStub(orderNumber: '2026-00042');
        $service = $this->service();

        $request = $service->buildCreatePaymentRequest($contract, null, 'https://shop.test/return');

        self::assertSame('2026-00042', $request->description);
    }

    public function testBuildCreatePaymentRequestFallsBackToContractIdWhenNoOrderNumber(): void
    {
        $contract = $this->contractStub(orderNumber: null, id: 'contract-123');
        $service = $this->service();

        $request = $service->buildCreatePaymentRequest($contract, null, 'https://shop.test/return');

        self::assertSame('contract-123', $request->description);
    }

    public function testBuildCreatePaymentRequestSendsWebhookUrlFromConfig(): void
    {
        $contract = $this->contractStub();
        $service = $this->service(webhookUrl: 'https://shop.test/webhook');

        $request = $service->buildCreatePaymentRequest($contract, null, 'https://shop.test/return');

        self::assertSame('https://shop.test/webhook', $request->webhookUrl);
    }

    /**
     * No method pinned → Mollie's hosted page picks it, and Mollie itself drops
     * manual capture for methods that cannot hold an authorization (verified live:
     * a manual-capture payment paid via iDEAL comes back with captureMode unset).
     * So the shop's mode is passed through unchanged.
     */
    public function testBuildCreatePaymentRequestSendsManualCaptureWhenNoMethodIsPinned(): void
    {
        $contract = $this->contractStub();
        $service = $this->service(captureMode: MollieDefinitions::CAPTURE_MODE_MANUAL);

        $request = $service->buildCreatePaymentRequest($contract, null, 'https://shop.test/return');

        self::assertSame(MollieDefinitions::CAPTURE_MODE_MANUAL, $request->captureMode);
    }

    /** Card and Klarna can be authorized first, so a manual-capture shop asks for that. */
    public function testBuildCreatePaymentRequestSendsManualCaptureForACaptureCapableMethod(): void
    {
        $contract = $this->contractStub();
        $service = $this->service(captureMode: MollieDefinitions::CAPTURE_MODE_MANUAL);

        $card = $service->buildCreatePaymentRequest($contract, 'creditcard', 'https://shop.test/return');
        $klarna = $service->buildCreatePaymentRequest($contract, 'klarna', 'https://shop.test/return');

        self::assertSame(MollieDefinitions::CAPTURE_MODE_MANUAL, $card->captureMode);
        self::assertSame(MollieDefinitions::CAPTURE_MODE_MANUAL, $klarna->captureMode);
    }

    /**
     * iDEAL, PayPal, bank transfer, … settle immediately; Mollie answers 422
     * ("At least one of the provided payment methods must support captures") when
     * such a method is pinned together with captureMode=manual. A manual-capture
     * shop therefore creates these payments with automatic capture — the shopper
     * sees every method, and manual capture applies only where it exists.
     */
    public function testBuildCreatePaymentRequestFallsBackToAutomaticCaptureForAnInstantMethod(): void
    {
        $contract = $this->contractStub();
        $service = $this->service(captureMode: MollieDefinitions::CAPTURE_MODE_MANUAL);

        $ideal = $service->buildCreatePaymentRequest($contract, 'ideal', 'https://shop.test/return');
        $paypal = $service->buildCreatePaymentRequest($contract, 'paypal', 'https://shop.test/return');

        self::assertSame(MollieDefinitions::CAPTURE_MODE_AUTOMATIC, $ideal->captureMode);
        self::assertSame(MollieDefinitions::CAPTURE_MODE_AUTOMATIC, $paypal->captureMode);
    }

    /** A card token pins creditcard, so the manual request survives the token path too. */
    public function testBuildCreatePaymentRequestKeepsManualCaptureOnTheCardTokenPath(): void
    {
        $contract = $this->contractStub();
        $service = $this->service(captureMode: MollieDefinitions::CAPTURE_MODE_MANUAL);

        $request = $service->buildCreatePaymentRequest($contract, null, 'https://shop.test/return', 'tkn_123');

        self::assertSame(MollieDefinitions::CAPTURE_MODE_MANUAL, $request->captureMode);
    }

    public function testBuildCreatePaymentRequestNeverAsksForManualCaptureOnAnAutomaticShop(): void
    {
        $contract = $this->contractStub();
        $service = $this->service(captureMode: MollieDefinitions::CAPTURE_MODE_AUTOMATIC);

        $request = $service->buildCreatePaymentRequest($contract, 'creditcard', 'https://shop.test/return');

        self::assertSame(MollieDefinitions::CAPTURE_MODE_AUTOMATIC, $request->captureMode);
    }

    public function testBuildCreatePaymentRequestStoresContractIdInMetadata(): void
    {
        $contract = $this->contractStub(id: 'contract-999');
        $service = $this->service();

        $request = $service->buildCreatePaymentRequest($contract, null, 'https://shop.test/return');

        self::assertSame('contract-999', $request->metadata['contract_id'] ?? null);
    }

    public function testBuildCreatePaymentRequestPinsCardTokenToCreditcard(): void
    {
        $contract = $this->contractStub();
        $service = $this->service();

        // Even if the caller passes a different method, a card token forces creditcard.
        $request = $service->buildCreatePaymentRequest($contract, 'ideal', 'https://shop.test/return', 'tkn_abc123');

        self::assertSame('creditcard', $request->method);
        self::assertSame('tkn_abc123', $request->cardToken);
    }

    public function testBuildCreatePaymentRequestLeavesCardTokenNullForRedirectFlow(): void
    {
        $contract = $this->contractStub();
        $service = $this->service();

        $request = $service->buildCreatePaymentRequest($contract, null, 'https://shop.test/return');

        self::assertNull($request->cardToken);
        self::assertNull($request->method);
    }

    public function testBuildCreatePaymentRequestTreatsBlankCardTokenAsRedirectFlow(): void
    {
        $contract = $this->contractStub();
        $service = $this->service();

        $request = $service->buildCreatePaymentRequest($contract, 'ideal', 'https://shop.test/return', '');

        self::assertNull($request->cardToken);
        self::assertSame('ideal', $request->method);
    }

    public function testBuildCreatePaymentRequestPopulatesAddressAndLinesForPayLaterMethod(): void
    {
        $contract = $this->contractStub();

        $address = new MollieAddressDto('Marc', 'Muster', 'm@x.test', 'Street 1', '12345', 'City', 'DE');
        $lines = [new MollieLineDto('Item', 1, new MollieAmountDto('EUR', 10.0), new MollieAmountDto('EUR', 10.0), 19.0, new MollieAmountDto('EUR', 1.6))];

        $orderData = $this->createMock(MollieOrderDataProviderInterface::class);
        $orderData->method('billingAddress')->willReturn($address);
        $orderData->method('lines')->with('EUR', 10.0)->willReturn($lines);

        $request = $this->service(orderData: $orderData)
            ->buildCreatePaymentRequest($contract, 'klarna', 'https://shop.test/return');

        self::assertSame($address, $request->billingAddress);
        self::assertSame($address, $request->shippingAddress);
        self::assertSame($lines, $request->lines);
    }

    public function testBuildCreatePaymentRequestOmitsOrderDataForNonPayLaterMethods(): void
    {
        $contract = $this->contractStub();

        $orderData = $this->createMock(MollieOrderDataProviderInterface::class);
        $orderData->expects(self::never())->method('billingAddress');

        $request = $this->service(orderData: $orderData)
            ->buildCreatePaymentRequest($contract, 'ideal', 'https://shop.test/return');

        self::assertNull($request->billingAddress);
        self::assertSame([], $request->lines);
    }

    public function testBuildCreatePaymentRequestSkipsOrderDataWhenAddressIncomplete(): void
    {
        $contract = $this->contractStub();

        // Missing email/country → incomplete → must not send partial data (avoid a 422).
        $incomplete = new MollieAddressDto('Marc', 'Muster', '', 'Street 1', '12345', 'City', '');
        $orderData = $this->createMock(MollieOrderDataProviderInterface::class);
        $orderData->method('billingAddress')->willReturn($incomplete);
        $orderData->expects(self::never())->method('lines');

        $request = $this->service(orderData: $orderData)
            ->buildCreatePaymentRequest($contract, 'klarna', 'https://shop.test/return');

        self::assertNull($request->billingAddress);
        self::assertSame([], $request->lines);
    }

    private function service(
        string $webhookUrl = 'https://shop.test/webhook',
        string $captureMode = MollieDefinitions::CAPTURE_MODE_AUTOMATIC,
        ?MollieOrderDataProviderInterface $orderData = null,
    ): CheckoutPaymentService {
        $config = $this->createMock(ModuleConfigurationServiceInterface::class);
        $config->method('getCaptureMode')->willReturn($captureMode);

        // Sprint 11 Story 7 (F20): the effective webhook URL now comes from a dedicated provider —
        // deriving it used to sit in ModuleConfigurationService and reach into Registry::getConfig().
        $webhookUrlProvider = $this->createMock(MollieWebhookUrlProviderInterface::class);
        $webhookUrlProvider->method('getWebhookUrl')->willReturn($webhookUrl);

        return new CheckoutPaymentService(
            $config,
            $orderData ?? $this->createMock(MollieOrderDataProviderInterface::class),
            $webhookUrlProvider,
            new NullLogger(),
        );
    }

    private function contractStub(
        float $amount = 10.0,
        string $currency = 'EUR',
        ?string $orderNumber = '2026-00001',
        string $id = 'contract-abc',
    ): PaymentContractInterface {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getAmount')->willReturn($amount);
        $contract->method('getCurrency')->willReturn($currency);
        $contract->method('getId')->willReturn($id);
        $contract->method('getMetadata')->willReturnCallback(
            static fn (string $key): mixed => $key === 'order_number' ? $orderNumber : null,
        );

        return $contract;
    }
}
