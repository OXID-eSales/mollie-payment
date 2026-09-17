<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Service;

use OxidEsales\PaymentBase\Contract\ContractState;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Service\StockRestorationServiceInterface;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieAmountDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MolliePaymentDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieRefundDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\RefundRequest;
use OxidEsales\Payments\Mollie\Adapter\MolliePaymentsAdapterInterface;
use OxidEsales\Payments\Mollie\Adapter\MollieRefundAdapterInterface;
use OxidEsales\Payments\Mollie\Service\ContractRefundRecorder;
use OxidEsales\Payments\Mollie\Service\RefundService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(RefundService::class)]
final class RefundServiceTest extends TestCase
{
    private MolliePaymentsAdapterInterface&MockObject $paymentsAdapter;
    private MollieRefundAdapterInterface&MockObject $refundAdapter;
    private ContractRepositoryInterface&MockObject $contractRepository;
    private StockRestorationServiceInterface&MockObject $stockRestorationService;
    private ContractRefundRecorder $refundRecorder;
    private RefundService $service;

    protected function setUp(): void
    {
        $this->paymentsAdapter = $this->createMock(MolliePaymentsAdapterInterface::class);
        $this->refundAdapter = $this->createMock(MollieRefundAdapterInterface::class);
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->stockRestorationService = $this->createMock(StockRestorationServiceInterface::class);
        $this->refundRecorder = new ContractRefundRecorder($this->contractRepository, new NullLogger());

        $this->service = new RefundService(
            $this->paymentsAdapter,
            $this->refundAdapter,
            $this->refundRecorder,
            $this->stockRestorationService,
            new NullLogger(),
        );
    }

    public function testRefund_Full_CallsAdapterAndAddsRefundedAmount(): void
    {
        $contract = $this->fulfilledContract('tr_123', '5');
        $this->paymentsAdapter->method('getPayment')->with('tr_123')->willReturn(
            $this->payment('tr_123', 100.0, 0.0),
        );

        $this->refundAdapter->expects(self::once())
            ->method('createRefund')
            ->with(self::callback(function (RefundRequest $request): bool {
                self::assertSame('tr_123', $request->paymentId);
                self::assertSame(100.0, $request->amount->value);
                return true;
            }))
            ->willReturn($this->refundDto('re_1', 'tr_123', 100.0));

        $contract->expects(self::once())->method('addRefundedAmount')->with(100.0);
        $this->contractRepository->expects(self::once())->method('save')->with($contract);

        $dto = $this->service->refund($contract);

        self::assertSame('re_1', $dto->id);
    }

    public function testRefund_Full_OnPartiallyCapturedPayment_RefundsOnlyWhatWasCaptured(): void
    {
        // Same ceiling the admin panel shows: a "refund everything" click on a payment that was
        // authorized for 100.00 but captured for 60.00 must ask Mollie for 60.00, not 100.00.
        $contract = $this->fulfilledContract('tr_123', '5');
        $this->paymentsAdapter->method('getPayment')->with('tr_123')->willReturn(
            $this->payment('tr_123', 100.0, 0.0, captured: 60.0),
        );

        $this->refundAdapter->expects(self::once())
            ->method('createRefund')
            ->with(self::callback(static fn (RefundRequest $request): bool => $request->amount->value === 60.0))
            ->willReturn($this->refundDto('re_1', 'tr_123', 60.0));

        $this->service->refund($contract);
    }

    public function testRefund_ExceedingCapturedAmount_ThrowsEvenIfWithinAuthorizedAmount(): void
    {
        $contract = $this->fulfilledContract('tr_123', '5');
        $this->paymentsAdapter->method('getPayment')->willReturn(
            $this->payment('tr_123', 100.0, 0.0, captured: 60.0),
        );

        $this->refundAdapter->expects(self::never())->method('createRefund');

        $this->expectException(\InvalidArgumentException::class);

        $this->service->refund($contract, 80.0);
    }

    public function testRefund_Partial_AccumulatesAcrossClicks(): void
    {
        $contract = $this->fulfilledContract('tr_123', '5');
        $this->paymentsAdapter->method('getPayment')->willReturn(
            $this->payment('tr_123', 100.0, 20.0),
        );
        $this->refundAdapter->method('createRefund')->willReturn(
            $this->refundDto('re_2', 'tr_123', 30.0),
        );

        $contract->expects(self::once())->method('addRefundedAmount')->with(30.0);

        $dto = $this->service->refund($contract, 30.0);

        self::assertSame(30.0, $dto->amount->value);
    }

    public function testRefund_ExceedingRefundable_ThrowsException(): void
    {
        $contract = $this->fulfilledContract('tr_123', '5');
        $this->paymentsAdapter->method('getPayment')->willReturn(
            $this->payment('tr_123', 100.0, 90.0),
        );

        $this->refundAdapter->expects(self::never())->method('createRefund');

        $this->expectException(\InvalidArgumentException::class);

        $this->service->refund($contract, 50.0);
    }

    public function testRefund_WhenContractNotFulfilled_Rejected(): void
    {
        $state = $this->createMock(ContractState::class);
        $state->method('isFulfilled')->willReturn(false);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getState')->willReturn($state);
        $contract->method('getId')->willReturn('5');

        $this->refundAdapter->expects(self::never())->method('createRefund');

        $this->expectException(\DomainException::class);

        $this->service->refund($contract, 10.0);
    }

    public function testRefund_NoProviderOrderId_Rejected(): void
    {
        $state = $this->createMock(ContractState::class);
        $state->method('isFulfilled')->willReturn(true);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getState')->willReturn($state);
        $contract->method('getProviderOrderId')->willReturn(null);
        $contract->method('getId')->willReturn('5');

        $this->expectException(\DomainException::class);

        $this->service->refund($contract);
    }

    // =========================================================================
    // Story 1: Stock Restoration on Admin Refund
    // =========================================================================

    public function testRefund_Successful_RestoresStockForOrder(): void
    {
        $contract = $this->fulfilledContractWithOrderId('tr_123', '5', 'order_42');

        $this->paymentsAdapter->method('getPayment')->with('tr_123')->willReturn(
            $this->payment('tr_123', 100.0, 0.0),
        );

        $this->refundAdapter->method('createRefund')->willReturn(
            $this->refundDto('re_1', 'tr_123', 100.0),
        );

        // Expect stock restoration to be called with the order ID
        $this->stockRestorationService->expects(self::once())
            ->method('restoreStockForOrder')
            ->with('order_42')
            ->willReturn(3);

        $this->service->refund($contract);
    }

    public function testRefund_WhenRefundFails_DoesNotRestoreStock(): void
    {
        $contract = $this->fulfilledContractWithOrderId('tr_123', '5', 'order_42');

        $this->paymentsAdapter->method('getPayment')->willReturn(
            $this->payment('tr_123', 100.0, 90.0),
        );

        // Exceeds refundable - should not call stock restoration
        $this->stockRestorationService->expects(self::never())
            ->method('restoreStockForOrder');

        $this->expectException(\InvalidArgumentException::class);

        $this->service->refund($contract, 50.0);
    }

    public function testRefund_WithOrderIdNull_DoesNotRestoreStock(): void
    {
        $state = $this->createMock(ContractState::class);
        $state->method('isFulfilled')->willReturn(true);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getState')->willReturn($state);
        $contract->method('getProviderOrderId')->willReturn('tr_123');
        $contract->method('getId')->willReturn('5');
        $contract->method('getOrderId')->willReturn(null); // No order linked

        $this->paymentsAdapter->method('getPayment')->willReturn(
            $this->payment('tr_123', 100.0, 0.0),
        );

        $this->refundAdapter->method('createRefund')->willReturn(
            $this->refundDto('re_1', 'tr_123', 100.0),
        );

        // Stock restoration should not be called when orderId is null
        $this->stockRestorationService->expects(self::never())
            ->method('restoreStockForOrder');

        $dto = $this->service->refund($contract);

        self::assertSame('re_1', $dto->id);
    }

    private function fulfilledContractWithOrderId(string $providerOrderId, string $id, string $orderId): PaymentContractInterface&MockObject
    {
        $state = $this->createMock(ContractState::class);
        $state->method('isFulfilled')->willReturn(true);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getState')->willReturn($state);
        $contract->method('getProviderOrderId')->willReturn($providerOrderId);
        $contract->method('getId')->willReturn($id);
        $contract->method('getOrderId')->willReturn($orderId);

        return $contract;
    }

    private function fulfilledContract(string $providerOrderId, string $id): PaymentContractInterface&MockObject
    {
        $state = $this->createMock(ContractState::class);
        $state->method('isFulfilled')->willReturn(true);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getState')->willReturn($state);
        $contract->method('getProviderOrderId')->willReturn($providerOrderId);
        $contract->method('getId')->willReturn($id);

        return $contract;
    }

    private function payment(
        string $id,
        float $amount,
        float $amountRefunded,
        ?float $captured = null,
    ): MolliePaymentDto {
        return new MolliePaymentDto(
            id: $id,
            status: 'paid',
            amount: MollieAmountDto::fromComponents('EUR', $amount),
            amountRefunded: $amountRefunded,
            amountCaptured: $captured,
        );
    }

    private function refundDto(string $id, string $paymentId, float $amount): MollieRefundDto
    {
        return new MollieRefundDto($id, $paymentId, MollieAmountDto::fromComponents('EUR', $amount), 'pending');
    }
}
