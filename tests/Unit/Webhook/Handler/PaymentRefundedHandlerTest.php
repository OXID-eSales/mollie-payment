<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Webhook\Handler;

use OxidEsales\PaymentBase\Contract\ContractState;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Webhook\WebhookEvent;
use OxidEsales\Payments\Mollie\Service\ContractRefundRecorder;
use OxidEsales\Payments\Mollie\Webhook\Handler\PaymentRefundedHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(PaymentRefundedHandler::class)]
final class PaymentRefundedHandlerTest extends TestCase
{
    private ContractRepositoryInterface&MockObject $contractRepository;
    private PaymentRefundedHandler $handler;

    protected function setUp(): void
    {
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->handler = new PaymentRefundedHandler(
            $this->contractRepository,
            new ContractRefundRecorder($this->contractRepository, new NullLogger()),
        );
    }

    public function testHandledStatuses_ReturnsRefunded(): void
    {
        self::assertSame(['refunded'], $this->handler->handledStatuses());
    }

    public function testRefunded_AddsRefundedAmountFromApi(): void
    {
        $contract = $this->fulfilledContractWithRefundedAmount(0.0);
        $this->contractRepository->method('findByProviderOrderId')->with('tr_refund')->willReturn($contract);

        $contract->expects(self::once())->method('addRefundedAmount')->with(30.0);
        $this->contractRepository->expects(self::once())->method('save')->with($contract);

        $outcome = $this->handler->handle($this->event('tr_refund', 30.0));

        self::assertTrue($outcome->result->isSuccess());
        self::assertSame('refund_recorded', $outcome->result->action);
        self::assertSame('contract-1', $outcome->contractId);
    }

    public function testRefunded_PartialThenFull_Accumulates(): void
    {
        // First delivery: nothing recorded yet, Mollie reports amountRefunded=30 (partial).
        $firstRepository = $this->createMock(ContractRepositoryInterface::class);
        $contractBeforeAnyRefund = $this->fulfilledContractWithRefundedAmount(0.0);
        $firstRepository->method('findByProviderOrderId')->willReturn($contractBeforeAnyRefund);
        $contractBeforeAnyRefund->expects(self::once())->method('addRefundedAmount')->with(30.0);

        $firstHandler = new PaymentRefundedHandler($firstRepository, new ContractRefundRecorder($firstRepository, new NullLogger()));
        $firstHandler->handle($this->event('tr_refund', 30.0));

        // Second delivery: contract now reflects the first recording (refundedAmount=30),
        // Mollie reports the CUMULATIVE amountRefunded=50 — only the 20 delta must be recorded.
        $secondRepository = $this->createMock(ContractRepositoryInterface::class);
        $contractAfterPartialRefund = $this->fulfilledContractWithRefundedAmount(30.0);
        $secondRepository->method('findByProviderOrderId')->willReturn($contractAfterPartialRefund);
        $contractAfterPartialRefund->expects(self::once())->method('addRefundedAmount')->with(20.0);

        $secondHandler = new PaymentRefundedHandler($secondRepository, new ContractRefundRecorder($secondRepository, new NullLogger()));
        $outcome = $secondHandler->handle($this->event('tr_refund', 50.0));

        self::assertSame('refund_recorded', $outcome->result->action);
    }

    public function testRefunded_WhenNoNewAmount_IsSkipped(): void
    {
        $contract = $this->fulfilledContractWithRefundedAmount(30.0);
        $this->contractRepository->method('findByProviderOrderId')->willReturn($contract);

        $contract->expects(self::never())->method('addRefundedAmount');

        $outcome = $this->handler->handle($this->event('tr_refund', 30.0));

        self::assertSame('skipped', $outcome->result->action);
    }

    public function testHandle_WhenContractNotFound_IsSkipped(): void
    {
        $this->contractRepository->method('findByProviderOrderId')->willReturn(null);

        $outcome = $this->handler->handle($this->event('tr_missing', 10.0));

        self::assertSame('skipped', $outcome->result->action);
    }

    public function testHandle_WhenPaymentIdMissing_ReturnsFailure(): void
    {
        $outcome = $this->handler->handle(new WebhookEvent(id: 'x', type: 'refunded', data: [], created: time()));

        self::assertTrue($outcome->result->isFailure());
    }

    private function fulfilledContractWithRefundedAmount(float $refundedAmount): PaymentContractInterface&MockObject
    {
        $state = $this->createMock(ContractState::class);
        $state->method('isFulfilled')->willReturn(true);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('contract-1');
        $contract->method('getState')->willReturn($state);
        $contract->method('getRefundedAmount')->willReturn($refundedAmount);

        return $contract;
    }

    private function event(string $paymentId, float $amountRefunded): WebhookEvent
    {
        return new WebhookEvent(
            id: $paymentId,
            type: 'refunded',
            data: ['object' => ['id' => $paymentId, 'amountRefunded' => $amountRefunded]],
            created: time(),
        );
    }
}
