<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Service;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\Service\CheckoutPaymentService;
use OxidEsales\Payments\Mollie\Service\ModuleConfigurationServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

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

    public function testBuildCreatePaymentRequestSendsCaptureModeFromConfig(): void
    {
        $contract = $this->contractStub();
        $service = $this->service(captureMode: MollieDefinitions::CAPTURE_MODE_MANUAL);

        $request = $service->buildCreatePaymentRequest($contract, null, 'https://shop.test/return');

        self::assertSame(MollieDefinitions::CAPTURE_MODE_MANUAL, $request->captureMode);
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

    private function service(
        string $webhookUrl = 'https://shop.test/webhook',
        string $captureMode = MollieDefinitions::CAPTURE_MODE_AUTOMATIC,
    ): CheckoutPaymentService {
        $config = $this->createMock(ModuleConfigurationServiceInterface::class);
        $config->method('getWebhookUrl')->willReturn($webhookUrl);
        $config->method('getCaptureMode')->willReturn($captureMode);

        return new CheckoutPaymentService($config);
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
