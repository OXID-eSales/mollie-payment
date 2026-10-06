<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Webhook\Handler;

use OxidEsales\PaymentBase\Adapter\ShopAdapterInterface;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Repository\TransactionRepositoryInterface;
use OxidEsales\PaymentBase\Service\Commit\CommitOutcome;
use OxidEsales\PaymentBase\Service\Commit\ContractCommitServiceInterface;
use OxidEsales\PaymentBase\Service\Commit\PaymentConfirmation;
use OxidEsales\PaymentBase\Service\ContractFulfillmentServiceInterface;
use OxidEsales\Payments\Mollie\Service\ContractLinkedOrderUpdaterInterface;
use OxidEsales\Payments\Mollie\Service\TransactionAuditRecorder;
use OxidEsales\Payments\Mollie\Webhook\Handler\FulfillmentOutcome;
use OxidEsales\Payments\Mollie\Webhook\Handler\WebhookContractFulfillmentHandler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * GRAPH-QL / MS3 — `authorized` ends the order. With manual capture the payment
 * comes back authorized, not paid; the open contract is committed through
 * payment-base's ContractCommitService with requiresCapture (order committed,
 * not paid), instead of parking it in AUTHORIZED with a NOT_FINISHED order.
 */
final class WebhookContractFulfillmentHandlerAuthorizedCommitTest extends TestCase
{
    private ContractRepositoryInterface&MockObject $contracts;
    private TransactionRepositoryInterface&MockObject $transactions;
    private ContractCommitServiceInterface&MockObject $commit;

    protected function setUp(): void
    {
        $this->contracts = $this->createMock(ContractRepositoryInterface::class);
        $this->transactions = $this->createMock(TransactionRepositoryInterface::class);
        $this->commit = $this->createMock(ContractCommitServiceInterface::class);
    }

    public function testAnAuthorizationCommitsThePendingContractWithRequiresCaptureAndRecordsIt(): void
    {
        $contract = $this->pendingContract();
        $this->contracts->method('findByProviderOrderId')->with('tr_1')->willReturn($contract);
        $this->commit->expects($this->once())->method('commit')
            ->with($this->callback(function (PaymentConfirmation $c): bool {
                self::assertSame('ctr-1', $c->contractId);
                self::assertSame('mollie', $c->providerName);
                self::assertSame('tr_1', $c->authorizationId);
                self::assertSame('tr_1', $c->providerOrderId);
                self::assertSame(30.9, $c->amount);
                self::assertSame('EUR', $c->currency);
                self::assertTrue($c->requiresCapture, 'authorized, not captured: the order must not be marked paid');
                self::assertSame('webhook', $c->source);
                self::assertSame('tr_1', $c->extraContext['molliePaymentId']);

                return true;
            }))
            ->willReturn(CommitOutcome::committed('order-1'));
        $this->contracts->expects($this->never())->method('save'); // the commit service saved its own copy
        $this->transactions->expects($this->once())->method('save');

        self::assertSame(FulfillmentOutcome::Acted, $this->handler()->handlePaymentAuthorized('tr_1'));
    }

    public function testARefusedCommitIsReportedAsFailedSoMollieRetries(): void
    {
        $this->contracts->method('findByProviderOrderId')->willReturn($this->pendingContract());
        $this->commit->method('commit')->willReturn(CommitOutcome::refused('amount_mismatch'));
        $this->transactions->expects($this->never())->method('save');

        self::assertSame(FulfillmentOutcome::Failed, $this->handler()->handlePaymentAuthorized('tr_1'));
    }

    public function testAPendingCommitIsANoOpUntilTheOtherConditionCloses(): void
    {
        $this->contracts->method('findByProviderOrderId')->willReturn($this->pendingContract());
        $this->commit->method('commit')->willReturn(CommitOutcome::pending());

        self::assertSame(FulfillmentOutcome::NoOp, $this->handler()->handlePaymentAuthorized('tr_1'));
    }

    public function testAnAlreadyCommittedContractIsLeftAlone(): void
    {
        $contract = $this->pendingContract();
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);
        $contract->commitToOrder('order-1');
        $this->contracts->method('findByProviderOrderId')->willReturn($contract);
        $this->commit->expects($this->never())->method('commit');

        self::assertSame(FulfillmentOutcome::NoOp, $this->handler()->handlePaymentAuthorized('tr_1'));
    }

    public function testWithoutACommitServiceTheContractIsOnlyAuthorizedAsBefore(): void
    {
        $contract = $this->pendingContract();
        $this->contracts->method('findByProviderOrderId')->willReturn($contract);
        $this->contracts->expects($this->once())->method('save')->with($contract);

        self::assertSame(FulfillmentOutcome::Acted, $this->handler(withCommit: false)->handlePaymentAuthorized('tr_1'));
        self::assertTrue($contract->getState()->isAuthorized());
    }

    private function handler(bool $withCommit = true): WebhookContractFulfillmentHandler
    {
        $shopAdapter = $this->createMock(ShopAdapterInterface::class);
        $shopAdapter->method('getShopId')->willReturn('1');

        return new WebhookContractFulfillmentHandler(
            $this->contracts,
            $this->createMock(ContractFulfillmentServiceInterface::class),
            $this->createMock(ContractLinkedOrderUpdaterInterface::class),
            new TransactionAuditRecorder($this->transactions, $shopAdapter),
            new NullLogger(),
            $withCommit ? $this->commit : null,
        );
    }

    private function pendingContract(): PaymentContract
    {
        $contract = new PaymentContract(1, 'user-1', BasketSnapshot::fromArray([
            'items' => [], 'discounts' => [], 'totalGross' => 30.9, 'totalNet' => 25.97, 'totalVat' => 4.93, 'currency' => 'EUR',
        ]), 'ctr-1');
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order-1');
        $contract->transitionToPending();
        $contract->setProvider('mollie', 'tr_1');

        return $contract;
    }
}
