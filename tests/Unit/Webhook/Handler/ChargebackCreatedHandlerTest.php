<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Webhook\Handler;

use OxidEsales\PaymentBase\Adapter\ShopAdapterInterface;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Contract\Transaction;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Repository\TransactionRepositoryInterface;
use OxidEsales\PaymentBase\Webhook\WebhookEvent;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\Service\TransactionAuditRecorder;
use OxidEsales\Payments\Mollie\Webhook\Handler\ChargebackCreatedHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChargebackCreatedHandler::class)]
final class ChargebackCreatedHandlerTest extends TestCase
{
    private ContractRepositoryInterface&MockObject $contractRepository;
    private TransactionRepositoryInterface&MockObject $transactionRepository;
    private ChargebackCreatedHandler $handler;

    protected function setUp(): void
    {
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->transactionRepository = $this->createMock(TransactionRepositoryInterface::class);
        $shopAdapter = $this->createMock(ShopAdapterInterface::class);
        $shopAdapter->method('getShopId')->willReturn('1');

        $this->handler = new ChargebackCreatedHandler(
            $this->contractRepository,
            new TransactionAuditRecorder($this->transactionRepository, $shopAdapter),
        );
    }

    public function testHandledStatuses_ReturnsChargedback(): void
    {
        self::assertSame(['chargedback'], $this->handler->handledStatuses());
    }

    public function testChargeback_RecordedOnContract(): void
    {
        $contract = $this->contractStub();
        $this->contractRepository->method('findByProviderOrderId')->with('tr_cb')->willReturn($contract);

        $this->transactionRepository->expects(self::once())
            ->method('save')
            ->with(self::callback(function (Transaction $transaction): bool {
                self::assertSame(MollieDefinitions::TRANSACTION_TYPE_CHARGEBACK, $transaction->getType());
                self::assertSame(MollieDefinitions::TRANSACTION_STATUS_COMPLETED, $transaction->getStatus());
                self::assertSame(15.0, $transaction->getAmount());
                return true;
            }));

        $outcome = $this->handler->handle($this->event('tr_cb', 15.0));

        self::assertTrue($outcome->result->isSuccess());
        self::assertSame('chargeback_recorded', $outcome->result->action);
        self::assertSame('contract-1', $outcome->contractId);
    }

    public function testChargeback_DoesNotReverseContractState(): void
    {
        $contract = $this->contractStub();
        $contract->expects(self::never())->method('fail');
        $contract->expects(self::never())->method('cancel');
        $this->contractRepository->method('findByProviderOrderId')->willReturn($contract);

        $this->handler->handle($this->event('tr_cb', 15.0));
    }

    public function testHandle_WhenContractNotFound_IsSkipped(): void
    {
        $this->contractRepository->method('findByProviderOrderId')->willReturn(null);
        $this->transactionRepository->expects(self::never())->method('save');

        $outcome = $this->handler->handle($this->event('tr_missing', 15.0));

        self::assertSame('skipped', $outcome->result->action);
    }

    public function testHandle_WhenPaymentIdMissing_ReturnsFailure(): void
    {
        $this->transactionRepository->expects(self::never())->method('save');

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
