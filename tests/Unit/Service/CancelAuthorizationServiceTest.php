<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Service;

use DomainException;
use OxidEsales\PaymentBase\Adapter\ShopAdapterInterface;
use OxidEsales\PaymentBase\Contract\ContractState;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Contract\Transaction;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Repository\TransactionRepositoryInterface;
use OxidEsales\Payments\Mollie\Adapter\MolliePaymentsAdapterInterface;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\Service\AuthorizationReleaseMarker;
use OxidEsales\Payments\Mollie\Service\CancelAuthorizationService;
use OxidEsales\Payments\Mollie\Service\ContractLinkedOrderUpdaterInterface;
use OxidEsales\Payments\Mollie\Service\TransactionAuditRecorder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(CancelAuthorizationService::class)]
final class CancelAuthorizationServiceTest extends TestCase
{
    private MolliePaymentsAdapterInterface&MockObject $paymentsAdapter;
    private ContractRepositoryInterface&MockObject $contractRepository;
    private ContractLinkedOrderUpdaterInterface&MockObject $orderUpdater;
    private TransactionRepositoryInterface&MockObject $transactions;
    private CancelAuthorizationService $service;

    protected function setUp(): void
    {
        $this->paymentsAdapter = $this->createMock(MolliePaymentsAdapterInterface::class);
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->orderUpdater = $this->createMock(ContractLinkedOrderUpdaterInterface::class);
        $this->transactions = $this->createMock(TransactionRepositoryInterface::class);
        $shop = $this->createMock(ShopAdapterInterface::class);
        $shop->method('getShopId')->willReturn('1');

        $this->service = new CancelAuthorizationService(
            $this->paymentsAdapter,
            $this->contractRepository,
            $this->orderUpdater,
            new TransactionAuditRecorder($this->transactions, $shop),
        );
    }

    // ---- full cancel: nothing captured yet ----

    public function testCancel_UncapturedAuthorization_ReleasesTheHold_CancelsContract(): void
    {
        $contract = $this->contract(ContractState::authorized(), 'tr_123', 'order-1', captured: null);

        $this->paymentsAdapter->expects(self::once())->method('releaseAuthorization')->with('tr_123');
        $this->paymentsAdapter->expects(self::never())->method('cancelPayment');
        $contract->expects(self::once())->method('cancel')->with('mollie_authorization_canceled');
        $this->contractRepository->expects(self::once())->method('save')->with($contract);
        $this->orderUpdater->expects(self::once())->method('markCancelled')->with('order-1');
        $this->transactions->expects(self::never())->method('save');

        $this->service->cancel($contract);
    }

    public function testCancel_UsesGivenReason(): void
    {
        $contract = $this->contract(ContractState::authorized(), 'tr_123', 'order-1', captured: null);

        $contract->expects(self::once())->method('cancel')->with('fraud_suspected');

        $this->service->cancel($contract, 'fraud_suspected');
    }

    public function testCancel_CommittedContract_CancelsAuthorization(): void
    {
        // Manual-capture order committed by the shared return chain while the Mollie payment is
        // still an uncaptured `authorized` hold: the admin may still void it.
        $contract = $this->contract(ContractState::committed(), 'tr_c', 'order-c', captured: 0.0);

        $this->paymentsAdapter->expects(self::once())->method('releaseAuthorization')->with('tr_c');
        $contract->expects(self::once())->method('cancel');
        $this->contractRepository->expects(self::once())->method('save')->with($contract);
        $this->orderUpdater->expects(self::once())->method('markCancelled')->with('order-c');

        $this->service->cancel($contract);
    }

    // ---- partial cancel: a partial capture went through, the remainder is released ----

    public function testCancel_AfterPartialCapture_ReleasesOnlyTheRemainder_KeepsTheContract(): void
    {
        $contract = $this->contract(ContractState::fulfilled(), 'tr_p', 'order-p', captured: 40.0, amount: 100.0);

        $this->paymentsAdapter->expects(self::once())->method('releaseAuthorization')->with('tr_p');
        $contract->expects(self::never())->method('cancel');
        $this->orderUpdater->expects(self::never())->method('markCancelled');
        $contract->expects(self::once())->method('setMetadata')->with(
            AuthorizationReleaseMarker::METADATA_KEY,
            self::callback(static fn (array $marker): bool => $marker['amount'] === 60.0
                && $marker['reason'] === 'requested_by_customer'
                && is_string($marker['releasedAt'])),
        );
        $this->contractRepository->expects(self::once())->method('save')->with($contract);
        $this->transactions->expects(self::once())->method('save')->with(
            self::callback(static fn (Transaction $tx): bool => $tx->getType() === MollieDefinitions::TRANSACTION_TYPE_AUTHORIZATION_RELEASE
                && $tx->getAmount() === 60.0
                && $tx->getContractId() === '5'),
        );

        $this->service->cancel($contract, 'requested_by_customer');
    }

    public function testCancel_FulfilledWithoutAnyCapture_Rejected(): void
    {
        // Settled by an instant method (iDEAL, …): there is no hold to release, only a refund.
        $contract = $this->contract(ContractState::fulfilled(), 'tr_f', 'order-f', captured: null);

        $this->paymentsAdapter->expects(self::never())->method('releaseAuthorization');
        $contract->expects(self::never())->method('cancel');

        $this->expectException(DomainException::class);

        $this->service->cancel($contract);
    }

    public function testCancel_WhenNotInCancellableState_Rejected(): void
    {
        $contract = $this->contract(ContractState::pending(), 'tr_x', 'order-x', captured: null);

        $this->paymentsAdapter->expects(self::never())->method('releaseAuthorization');
        $contract->expects(self::never())->method('cancel');

        $this->expectException(DomainException::class);

        $this->service->cancel($contract);
    }

    public function testCancel_WhenAlreadyCancelled_IsIdempotentNoOp(): void
    {
        $contract = $this->contract(ContractState::cancelled(), 'tr_x', 'order-x', captured: null);

        $this->paymentsAdapter->expects(self::never())->method('releaseAuthorization');
        $contract->expects(self::never())->method('cancel');

        $this->service->cancel($contract);
    }

    public function testCancel_WhenRemainderAlreadyReleased_IsIdempotentNoOp(): void
    {
        $contract = $this->contract(ContractState::fulfilled(), 'tr_p', 'order-p', captured: 40.0, amount: 100.0);
        $contract->method('getMetadata')->with(AuthorizationReleaseMarker::METADATA_KEY)
            ->willReturn(['amount' => 60.0, 'reason' => null, 'releasedAt' => '2026-10-01T12:00:00+00:00']);

        $this->paymentsAdapter->expects(self::never())->method('releaseAuthorization');
        $this->transactions->expects(self::never())->method('save');

        $this->service->cancel($contract);
    }

    public function testCancel_WithoutProviderOrderId_Rejected(): void
    {
        $contract = $this->contract(ContractState::authorized(), '', 'order-1', captured: null);

        $this->paymentsAdapter->expects(self::never())->method('releaseAuthorization');

        $this->expectException(DomainException::class);

        $this->service->cancel($contract);
    }

    private function contract(
        ContractState $state,
        string $providerOrderId,
        string $orderId,
        ?float $captured,
        float $amount = 100.0,
    ): PaymentContractInterface&MockObject {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getState')->willReturn($state);
        $contract->method('getProviderOrderId')->willReturn($providerOrderId);
        $contract->method('getOrderId')->willReturn($orderId);
        $contract->method('getId')->willReturn('5');
        $contract->method('getCapturedAmount')->willReturn($captured);
        $contract->method('getAmount')->willReturn($amount);
        $contract->method('getCurrency')->willReturn('EUR');

        return $contract;
    }
}
