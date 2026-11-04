<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Webhook\Handler;

use OxidEsales\PaymentBase\AtRisk\AtRiskReason;
use OxidEsales\PaymentBase\AtRisk\PaymentAtRiskFlagInterface;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Refund\ChargebackBooking;
use OxidEsales\PaymentBase\Refund\ProviderChargebackLedgerInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Webhook\WebhookEvent;
use OxidEsales\Payments\Mollie\Webhook\Handler\ChargebackCreatedHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * VR-21 S-M6: Mollie reports the cumulative `amountChargedBack` with every status webhook and has no
 * chargeback id. The handler hands that total to payment-base's chargeback ledger, which books the
 * change; before, every delivery recorded the whole total again.
 */
#[CoversClass(ChargebackCreatedHandler::class)]
final class ChargebackCreatedHandlerTest extends TestCase
{
    private ContractRepositoryInterface&MockObject $contractRepository;
    private ProviderChargebackLedgerInterface&MockObject $ledger;
    private PaymentAtRiskFlagInterface&MockObject $atRisk;
    private ChargebackCreatedHandler $handler;

    protected function setUp(): void
    {
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->ledger = $this->createMock(ProviderChargebackLedgerInterface::class);

        $this->atRisk = $this->createMock(PaymentAtRiskFlagInterface::class);

        $this->handler = new ChargebackCreatedHandler($this->contractRepository, $this->ledger, $this->atRisk);
    }

    public function testHandledStatuses_ReturnsChargedback(): void
    {
        self::assertSame(['chargedback'], $this->handler->handledStatuses());
    }

    public function testTheCumulativeTotalIsHandedToTheLedgerWithoutAChargebackId(): void
    {
        $this->contractRepository->method('findByProviderOrderId')->with('tr_cb')->willReturn($this->contractStub());
        $this->ledger->expects(self::once())->method('book')
            ->with('contract-1', null, 15.0, 'EUR', 'webhook')
            ->willReturn(new ChargebackBooking(true, 15.0, 15.0));

        $outcome = $this->handler->handle($this->event('tr_cb', 15.0));

        self::assertTrue($outcome->result->isSuccess());
        self::assertSame('chargeback_recorded', $outcome->result->action);
        self::assertSame('contract-1', $outcome->contractId);
    }

    public function testAReplayTheLedgerBooksNothingFor_IsSkippedHonestly(): void
    {
        $this->contractRepository->method('findByProviderOrderId')->willReturn($this->contractStub());
        $this->ledger->method('book')->willReturn(new ChargebackBooking(false, 0.0, 15.0));

        $outcome = $this->handler->handle($this->event('tr_cb', 15.0));

        self::assertSame('skipped', $outcome->result->action);
        self::assertSame('contract-1', $outcome->contractId);
    }

    /**
     * VR-26 M2 (#2): a chargeback is money leaving the merchant, so the merchant is told - once. The flag is keyed by
     * the payment id, so a later delta on the same payment does not notify again.
     */
    public function testChargebackRaisesAtRiskOnce(): void
    {
        $contract = $this->contractStub();
        $this->contractRepository->method('findByProviderOrderId')->willReturn($contract);
        $this->ledger->method('book')->willReturn(new ChargebackBooking(true, 15.0, 15.0));
        $this->atRisk->expects(self::once())->method('raise')
            ->with($contract, AtRiskReason::Chargeback, 'tr_cb')
            ->willReturn(true);

        $this->handler->handle($this->event('tr_cb', 15.0));
    }

    public function testAReplayRaisesNothing(): void
    {
        $this->contractRepository->method('findByProviderOrderId')->willReturn($this->contractStub());
        $this->ledger->method('book')->willReturn(new ChargebackBooking(false, 0.0, 15.0));
        $this->atRisk->expects(self::never())->method('raise');

        $this->handler->handle($this->event('tr_cb', 15.0));
    }

    public function testAReversalOfAChargebackRaisesNothing(): void
    {
        $this->contractRepository->method('findByProviderOrderId')->willReturn($this->contractStub());
        $this->ledger->method('book')->willReturn(new ChargebackBooking(true, -5.0, 10.0));
        $this->atRisk->expects(self::never())->method('raise');

        $this->handler->handle($this->event('tr_cb', 10.0));
    }

    public function testChargeback_DoesNotReverseContractState(): void
    {
        $contract = $this->contractStub();
        $contract->expects(self::never())->method('fail');
        $contract->expects(self::never())->method('cancel');
        $this->contractRepository->method('findByProviderOrderId')->willReturn($contract);
        $this->ledger->method('book')->willReturn(new ChargebackBooking(true, 15.0, 15.0));

        $this->handler->handle($this->event('tr_cb', 15.0));
    }

    public function testHandle_WhenContractNotFound_IsSkipped(): void
    {
        $this->contractRepository->method('findByProviderOrderId')->willReturn(null);
        $this->ledger->expects(self::never())->method('book');

        $outcome = $this->handler->handle($this->event('tr_missing', 15.0));

        self::assertSame('skipped', $outcome->result->action);
    }

    public function testHandle_WhenPaymentIdMissing_ReturnsFailure(): void
    {
        $this->ledger->expects(self::never())->method('book');

        $outcome = $this->handler->handle(new WebhookEvent(id: 'x', type: 'chargedback', data: [], created: time()));

        self::assertTrue($outcome->result->isFailure());
    }

    private function contractStub(): PaymentContractInterface&MockObject
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('contract-1');
        $contract->method('getCurrency')->willReturn('EUR');
        $contract->method('getProviderOrderId')->willReturn('tr_cb');

        return $contract;
    }

    private function event(string $paymentId, float $amountChargedBack): WebhookEvent
    {
        return new WebhookEvent(
            id: $paymentId,
            type: 'chargedback',
            data: ['object' => ['id' => $paymentId, 'amountChargedBack' => $amountChargedBack]],
            created: time(),
        );
    }
}
