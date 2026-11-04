<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Service\Return;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use OxidEsales\PaymentBase\Return\ReturnResolution;
use OxidEsales\PaymentBase\Service\Commit\ConfirmedAmount;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieAmountDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MolliePaymentDto;
use OxidEsales\Payments\Mollie\Adapter\Exception\MollieAdapterException;
use OxidEsales\Payments\Mollie\Adapter\MolliePaymentsAdapterInterface;
use OxidEsales\Payments\Mollie\Adapter\MollieStatusMapper;
use OxidEsales\Payments\Mollie\Service\ModuleConfigurationServiceInterface;
use OxidEsales\Payments\Mollie\Service\Return\MollieReturnResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(MollieReturnResolver::class)]
final class MollieReturnResolverTest extends TestCase
{
    public function testResolveWithMissingProviderOrderIdFails(): void
    {
        $contract = $this->contractStub(providerOrderId: null);
        [$resolver] = $this->resolver();

        $resolution = $resolver->resolve($contract, new EventContext());

        self::assertFalse($resolution->isSuccessful());
        self::assertSame('missing_provider_payment_id', $resolution->errorCode);
    }

    public function testResolvePaidStatusReturnsReadyToCommit(): void
    {
        $contract = $this->contractStub();
        [$resolver, $adapter] = $this->resolver();
        $adapter->method('getPayment')->willReturn($this->paymentDto('paid'));

        $resolution = $resolver->resolve($contract, new EventContext());

        self::assertTrue($resolution->isSuccessful());
        self::assertSame(ReturnResolution::OUTCOME_READY_TO_COMMIT, $resolution->outcome);
        self::assertSame('tr_1', $resolution->authorizationId);
        self::assertFalse($resolution->requiresCapture);
    }

    public function testResolve_ContextCarriesAnotherContractIdFromTheUrl_ReadsTheContractOnly(): void
    {
        $contract = $this->contractStub();
        [$resolver, $adapter] = $this->resolver();
        $adapter->expects($this->once())->method('getPayment')->with('tr_1')->willReturn($this->paymentDto('paid'));

        $resolution = $resolver->resolve($contract, new EventContext(['contract_id' => 'other-contract']));

        self::assertSame(ReturnResolution::OUTCOME_READY_TO_COMMIT, $resolution->outcome);
    }

    public function testResolveAuthorizedStatusReturnsAuthorizedRequiringCapture(): void
    {
        $contract = $this->contractStub();
        [$resolver, $adapter] = $this->resolver();
        $adapter->method('getPayment')->willReturn($this->paymentDto('authorized'));

        $resolution = $resolver->resolve($contract, new EventContext());

        self::assertTrue($resolution->isSuccessful());
        self::assertSame(ReturnResolution::OUTCOME_AUTHORIZED, $resolution->outcome);
        self::assertTrue($resolution->requiresCapture);
    }

    public function testResolveWhenStillOpenLeavesContractPendingForWebhook(): void
    {
        $contract = $this->contractStub();
        [$resolver, $adapter] = $this->resolver();
        $adapter->method('getPayment')->willReturn($this->paymentDto('open'));

        $resolution = $resolver->resolve($contract, new EventContext());

        self::assertFalse($resolution->isSuccessful());
        self::assertSame(ReturnResolution::OUTCOME_PENDING, $resolution->outcome);
    }

    public function testResolveCanceledStatusFails(): void
    {
        $contract = $this->contractStub();
        [$resolver, $adapter] = $this->resolver();
        $adapter->method('getPayment')->willReturn($this->paymentDto('canceled'));

        $resolution = $resolver->resolve($contract, new EventContext());

        self::assertFalse($resolution->isSuccessful());
        self::assertSame('payment_canceled', $resolution->errorCode);
    }

    public function testResolveOnAdapterExceptionFails(): void
    {
        $contract = $this->contractStub();
        [$resolver, $adapter] = $this->resolver();
        $adapter->method('getPayment')->willThrowException(new MollieAdapterException('network error'));

        $resolution = $resolver->resolve($contract, new EventContext());

        self::assertFalse($resolution->isSuccessful());
        self::assertSame('get_payment_failed', $resolution->errorCode);
    }

    public function testResolveIsIdempotentForRepeatedPaidStatus(): void
    {
        $contract = $this->contractStub();
        [$resolver, $adapter] = $this->resolver();
        $adapter->method('getPayment')->willReturn($this->paymentDto('paid'));

        $first = $resolver->resolve($contract, new EventContext());
        $second = $resolver->resolve($contract, new EventContext());

        self::assertEquals($first, $second);
    }

    public function testReportsMolliesAmountNotTheContracts(): void
    {
        $contract = $this->contractStub();
        [$resolver, $adapter] = $this->resolver();
        $adapter->method('getPayment')->willReturn($this->paymentDto('paid', 10.0));

        $resolution = $resolver->resolve($contract, new EventContext());

        self::assertSame(10.0, $resolution->amount, 'the figure is what Mollie holds, not the contract total');
        self::assertSame('EUR', $resolution->currency);
        self::assertFalse(ConfirmedAmount::matches($contract, $resolution->amount, $resolution->currency));
    }

    public function testAnAuthorizedPaymentReportsMolliesAmountToo(): void
    {
        [$resolver, $adapter] = $this->resolver();
        $adapter->method('getPayment')->willReturn($this->paymentDto('authorized', 10.0));

        $resolution = $resolver->resolve($this->contractStub(), new EventContext());

        self::assertSame(10.0, $resolution->amount);
    }

    public function testCurrencyMismatchIsRefused(): void
    {
        $contract = $this->contractStub();
        [$resolver, $adapter] = $this->resolver();
        $adapter->method('getPayment')->willReturn($this->paymentDto('paid', 25.0, 'USD'));

        $resolution = $resolver->resolve($contract, new EventContext());

        self::assertSame('USD', $resolution->currency);
        self::assertFalse(ConfirmedAmount::matches($contract, $resolution->amount, $resolution->currency));
    }

    public function testAPaymentWithoutAmountOrCurrencyIsRefusedWithoutFallingBackToTheContract(): void
    {
        [$resolver, $adapter] = $this->resolver();
        $adapter->method('getPayment')->willReturn(new MolliePaymentDto(
            id: 'tr_1',
            status: 'paid',
            amount: MollieAmountDto::fromArray([]),
        ));

        $resolution = $resolver->resolve($this->contractStub(), new EventContext());

        self::assertFalse($resolution->isSuccessful());
        self::assertSame('provider_amount_missing', $resolution->errorCode);
        self::assertSame('tr_1', $resolution->providerOrderId);
    }

    /**
     * @return array{0: MollieReturnResolver, 1: MolliePaymentsAdapterInterface&MockObject}
     */
    private function resolver(): array
    {
        $adapter = $this->createMock(MolliePaymentsAdapterInterface::class);
        $resolver = new MollieReturnResolver(
            $adapter,
            new MollieStatusMapper(),
            $this->createMock(ModuleConfigurationServiceInterface::class),
            new NullLogger(),
        );

        return [$resolver, $adapter];
    }

    private function contractStub(?string $providerOrderId = 'tr_1'): PaymentContractInterface
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn($providerOrderId);
        $contract->method('getAmount')->willReturn(25.0);
        $contract->method('getCurrency')->willReturn('EUR');

        return $contract;
    }

    private function paymentDto(string $status, float $amount = 25.0, string $currency = 'EUR'): MolliePaymentDto
    {
        return new MolliePaymentDto(
            id: 'tr_1',
            status: $status,
            amount: MollieAmountDto::fromComponents($currency, $amount),
        );
    }
}
