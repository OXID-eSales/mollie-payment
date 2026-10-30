<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Service;

use OxidEsales\PaymentBase\Adapter\ShopAdapterInterface;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Contract\Transaction;
use OxidEsales\PaymentBase\Repository\TransactionRepositoryInterface;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\Service\TransactionAuditRecorder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TransactionAuditRecorder::class)]
final class TransactionAuditRecorderTest extends TestCase
{
    public function testRecord_SavesTransactionWithContractAndProviderData(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('contract-1');
        $contract->method('getOrderId')->willReturn('order-1');
        $contract->method('getProviderOrderId')->willReturn('tr_123');
        $contract->method('getCurrency')->willReturn('EUR');

        $shopAdapter = $this->createMock(ShopAdapterInterface::class);
        $shopAdapter->method('getShopId')->willReturn('1');

        $transactionRepository = $this->createMock(TransactionRepositoryInterface::class);
        $transactionRepository->expects(self::once())
            ->method('save')
            ->with(self::callback(function (Transaction $transaction): bool {
                self::assertSame('order-1', $transaction->getOrderId());
                self::assertSame('contract-1', $transaction->getContractId());
                self::assertSame(MollieDefinitions::PROVIDER_NAME, $transaction->getProvider());
                self::assertSame('tr_123', $transaction->getProviderOrderId());
                self::assertSame(MollieDefinitions::TRANSACTION_TYPE_CAPTURE, $transaction->getType());
                self::assertSame(MollieDefinitions::TRANSACTION_STATUS_COMPLETED, $transaction->getStatus());
                self::assertSame(19.99, $transaction->getAmount());
                self::assertSame('EUR', $transaction->getCurrency());
                return true;
            }));

        $recorder = new TransactionAuditRecorder($transactionRepository, $shopAdapter);

        $recorder->record(
            $contract,
            MollieDefinitions::TRANSACTION_TYPE_CAPTURE,
            MollieDefinitions::TRANSACTION_STATUS_COMPLETED,
            19.99,
        );
    }

    public function testRecord_KeepsTheProvidersTransactionIdAsTheReference_OnceOnly(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getCurrency')->willReturn('EUR');
        $shopAdapter = $this->createMock(ShopAdapterInterface::class);
        $shopAdapter->method('getShopId')->willReturn('1');
        $saved = [];
        $transactionRepository = $this->createMock(TransactionRepositoryInterface::class);
        $transactionRepository->method('findByProviderTransactionId')
            ->willReturnCallback(static fn (string $id) => $id === 'cpt_known' ? new Transaction('x', 1, 'o', null, 'mollie', 'capture', 'completed', 1.0, 'EUR') : null);
        $transactionRepository->method('save')->willReturnCallback(static function (Transaction $transaction) use (&$saved): void {
            $saved[] = $transaction;
        });
        $recorder = new TransactionAuditRecorder($transactionRepository, $shopAdapter);

        $recorder->record($contract, 'capture', 'completed', 10.0, 'cpt_new');
        $recorder->record($contract, 'capture', 'completed', 10.0, 'cpt_known');

        self::assertCount(1, $saved);
        self::assertSame('cpt_new', $saved[0]->getTransactionId());
    }

    public function testRecord_FallsBackToEmptyOrderIdWhenContractHasNone(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('contract-2');
        $contract->method('getOrderId')->willReturn(null);
        $contract->method('getProviderOrderId')->willReturn(null);
        $contract->method('getCurrency')->willReturn('EUR');

        $shopAdapter = $this->createMock(ShopAdapterInterface::class);
        $shopAdapter->method('getShopId')->willReturn('1');

        $transactionRepository = $this->createMock(TransactionRepositoryInterface::class);
        $transactionRepository->expects(self::once())
            ->method('save')
            ->with(self::callback(function (Transaction $transaction): bool {
                self::assertSame('', $transaction->getOrderId());
                return true;
            }));

        $recorder = new TransactionAuditRecorder($transactionRepository, $shopAdapter);

        $recorder->record($contract, MollieDefinitions::TRANSACTION_TYPE_CHARGEBACK, MollieDefinitions::TRANSACTION_STATUS_COMPLETED, 5.0);
    }
}
