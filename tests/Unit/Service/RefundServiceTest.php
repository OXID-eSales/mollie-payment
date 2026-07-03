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

#[CoversClass(RefundService::class)]
final class RefundServiceTest extends TestCase
{
    private MolliePaymentsAdapterInterface&MockObject $paymentsAdapter;
    private MollieRefundAdapterInterface&MockObject $refundAdapter;
    private ContractRepositoryInterface&MockObject $contractRepository;
    private ContractRefundRecorder $refundRecorder;
    private RefundService $service;

    protected function setUp(): void
    {
        $this->paymentsAdapter = $this->createMock(MolliePaymentsAdapterInterface::class);
        $this->refundAdapter = $this->createMock(MollieRefundAdapterInterface::class);
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->refundRecorder = new ContractRefundRecorder($this->contractRepository);

        $this->service = new RefundService(
            $this->paymentsAdapter,
            $this->refundAdapter,
            $this->refundRecorder,
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

    private function payment(string $id, float $amount, float $amountRefunded): MolliePaymentDto
    {
        return new MolliePaymentDto(
            id: $id,
            status: 'paid',
            amount: MollieAmountDto::fromComponents('EUR', $amount),
            amountRefunded: $amountRefunded,
        );
    }

    private function refundDto(string $id, string $paymentId, float $amount): MollieRefundDto
    {
        return new MollieRefundDto($id, $paymentId, MollieAmountDto::fromComponents('EUR', $amount), 'pending');
    }
}
