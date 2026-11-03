<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Webhook\Handler;

use DomainException;
use OxidEsales\PaymentBase\Adapter\ShopAdapterInterface;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\ContractState;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Contract\Transaction;
use OxidEsales\PaymentBase\Payment\CaptureBooking;
use OxidEsales\PaymentBase\Payment\ProviderCaptureLedgerInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Repository\StaleContractException;
use OxidEsales\PaymentBase\Repository\TransactionRepositoryInterface;
use OxidEsales\PaymentBase\Service\ContractFulfillmentServiceInterface;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\Service\ContractLinkedOrderUpdaterInterface;
use OxidEsales\Payments\Mollie\Service\MollieLatePaymentRefunder;
use OxidEsales\Payments\Mollie\Service\TransactionAuditRecorder;
use OxidEsales\Payments\Mollie\Webhook\Handler\FulfillmentOutcome;
use OxidEsales\Payments\Mollie\Webhook\Handler\FulfillmentResult;
use OxidEsales\Payments\Mollie\Webhook\Handler\WebhookContractFulfillmentHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use OxidEsales\PaymentBase\Service\Commit\CommitOutcome;
use OxidEsales\PaymentBase\Service\Commit\ContractCommitServiceInterface;
use OxidEsales\Payments\Mollie\Tests\Unit\Webhook\Handler\Support\CommittingContractCommit;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(WebhookContractFulfillmentHandler::class)]
final class WebhookContractFulfillmentHandlerTest extends TestCase
{
    private ContractRepositoryInterface&MockObject $contractRepository;
    private ContractFulfillmentServiceInterface&MockObject $contractFulfillmentService;
    private ContractLinkedOrderUpdaterInterface&MockObject $orderUpdater;
    private TransactionRepositoryInterface&MockObject $transactionRepository;
    private TransactionAuditRecorder $auditRecorder;
    private MollieLatePaymentRefunder&MockObject $lateMoney;
    private ProviderCaptureLedgerInterface&MockObject $captures;
    private CommittingContractCommit $commit;
    private WebhookContractFulfillmentHandler $handler;

    protected function setUp(): void
    {
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->lateMoney = $this->createMock(MollieLatePaymentRefunder::class);
        $this->captures = $this->createMock(ProviderCaptureLedgerInterface::class);
        $this->captures->method('book')->willReturn(CaptureBooking::booked(10.0));
        $this->contractFulfillmentService = $this->createMock(ContractFulfillmentServiceInterface::class);
        $this->orderUpdater = $this->createMock(ContractLinkedOrderUpdaterInterface::class);
        $this->transactionRepository = $this->createMock(TransactionRepositoryInterface::class);
        $shopAdapter = $this->createMock(ShopAdapterInterface::class);
        $shopAdapter->method('getShopId')->willReturn('1');
        $this->auditRecorder = new TransactionAuditRecorder($this->transactionRepository, $shopAdapter);

        $this->commit = new CommittingContractCommit($this->contractRepository);
        $this->handler = $this->handlerWith($this->commit);
    }

    // --- handlePaymentPaid ---

    public function testHandlePaymentPaid_ReturnsNullWhenContractNotFound(): void
    {
        $this->contractRepository->method('findByProviderOrderId')->willReturn(null);

        self::assertSame(FulfillmentOutcome::ContractNotFound, $this->handler->handlePaymentPaid('tr_missing')->outcome);
    }

    public function testHandlePaymentPaid_PaymentOfReplacedAttempt_IsGivenBackAndAnsweredNoOp(): void
    {
        $this->contractRepository->method('findByProviderOrderId')->willReturn(null);
        $this->lateMoney->expects($this->once())->method('settleIfReplaced')->with('tr_old')->willReturn('ctr-1');

        $result = $this->handler->handlePaymentPaid('tr_old');

        self::assertSame(FulfillmentOutcome::NoOp, $result->outcome);
        self::assertSame('ctr-1', $result->contractId);
    }

    public function testHandlePaymentAuthorized_PaymentOfReplacedAttempt_IsGivenBack(): void
    {
        $this->contractRepository->method('findByProviderOrderId')->willReturn(null);
        $this->lateMoney->expects($this->once())->method('settleIfReplaced')->with('tr_old')->willReturn('ctr-1');

        self::assertSame(FulfillmentOutcome::NoOp, $this->handler->handlePaymentAuthorized('tr_old')->outcome);
    }

    public function testHandlePaymentPaid_IsNoOpWhenAlreadyFulfilled(): void
    {
        $contract = $this->contractWithState(fulfilled: true);
        $this->contractRepository->method('findByProviderOrderId')->willReturn($contract);

        $contract->expects(self::never())->method('transitionToPending');
        $this->contractRepository->expects(self::never())->method('save');
        $this->contractFulfillmentService->expects(self::never())->method('fulfill');

        self::assertSame(FulfillmentOutcome::NoOp, $this->handler->handlePaymentPaid('tr_paid')->outcome);
    }

    /**
     * VR-20 R6 — `paid` on an open contract commits through payment-base's ContractCommitService
     * (amount / currency / state checks, one path for every provider) instead of climbing a
     * hand-written ladder of its own.
     */
    public function testHandlePaymentPaid_CommitsThroughTheCommitServiceThenFulfills(): void
    {
        $contract = $this->contractWithState(fulfilled: false);
        $contract->method('getOrderId')->willReturn('order-1');
        $contract->method('getAmount')->willReturn(19.99);
        $contract->method('getCurrency')->willReturn('EUR');
        $this->contractRepository->method('findByProviderOrderId')->willReturn($contract);
        $this->contractRepository->expects(self::once())->method('save')->with($contract);

        $this->contractFulfillmentService->expects(self::once())
            ->method('fulfill')
            ->with($contract)
            ->willReturn(true);

        self::assertSame(FulfillmentOutcome::Acted, $this->handler->handlePaymentPaid('tr_paid')->outcome);
        self::assertCount(1, $this->commit->confirmations);
        $confirmation = $this->commit->confirmations[0];
        self::assertSame(['contract-1', 'mollie', 'tr_paid', 19.99, 'EUR', false], [
            $confirmation->contractId,
            $confirmation->providerName,
            $confirmation->providerOrderId,
            $confirmation->amount,
            $confirmation->currency,
            $confirmation->requiresCapture,
        ]);
    }

    public function testHandlePaymentPaid_WhenTheCommitIsRefused_AsksMollieToRetryAndFulfilsNothing(): void
    {
        $contract = $this->contractWithState(fulfilled: false);
        $this->contractRepository->method('findByProviderOrderId')->willReturn($contract);
        $commit = $this->createMock(ContractCommitServiceInterface::class);
        $commit->method('commit')->willReturn(CommitOutcome::refused('amount_mismatch'));

        $this->contractFulfillmentService->expects(self::never())->method('fulfill');
        $this->transactionRepository->expects(self::never())->method('save');

        self::assertSame(FulfillmentOutcome::Failed, $this->handlerWith($commit)->handlePaymentPaid('tr_paid')->outcome);
    }

    public function testHandlePaymentPaid_OnAnAlreadyCommittedContract_DoesNotCommitAgain(): void
    {
        // manual capture: `authorized` committed the contract, the capture's `paid` only fulfils it
        $contract = $this->committedContract();
        $this->contractRepository->method('findByProviderOrderId')->willReturn($contract);
        $this->contractFulfillmentService->method('fulfill')->willReturn(true);

        self::assertSame(FulfillmentOutcome::Acted, $this->handler->handlePaymentPaid('tr_1')->outcome);
        self::assertSame([], $this->commit->confirmations);
    }

    public function testHandlePaymentPaid_DoesNotRecordTransactionWhenFulfillmentServiceDeclines(): void
    {
        $contract = $this->contractWithState(fulfilled: false);
        $contract->method('getOrderId')->willReturn(null);
        $this->contractRepository->method('findByProviderOrderId')->willReturn($contract);

        $this->contractFulfillmentService->method('fulfill')->willReturn(false);

        $this->transactionRepository->expects(self::never())->method('save');

        self::assertSame(FulfillmentOutcome::Failed, $this->handler->handlePaymentPaid('tr_paid')->outcome);
    }

    // --- handlePaymentFailed ---

    // ── MOL-17: the webhook races the shopper's return leg on the contract row ───────────────────

    public function testHandlePaymentPaid_RetriesOnceOnAFreshCopyWhenTheContractWasStale(): void
    {
        $stale = $this->capturedCommittedContract();
        $fresh = $this->capturedCommittedContract();
        $this->contractRepository->method('findByProviderOrderId')
            ->willReturnOnConsecutiveCalls($stale, $fresh);
        $saves = 0;
        $this->contractRepository->method('save')->willReturnCallback(function (PaymentContractInterface $c) use (&$saves, $stale): void {
            $saves++;
            if ($c === $stale) {
                throw new StaleContractException('c-1', 2, 3);
            }
        });
        $this->contractFulfillmentService->method('fulfill')->with($fresh)->willReturn(true);

        $outcome = $this->handler->handlePaymentPaid('tr_1');

        self::assertSame(FulfillmentOutcome::Acted, $outcome->outcome);
        self::assertSame(2, $saves, 'the stale save, then the fresh one');
    }

    public function testHandlePaymentPaid_GivesUpAfterASecondStaleRefusal(): void
    {
        $this->contractRepository->method('findByProviderOrderId')->willReturnCallback(fn () => $this->capturedCommittedContract());
        $this->contractRepository->method('save')->willThrowException(new StaleContractException('c-1', 2, 3));
        $this->contractFulfillmentService->expects(self::never())->method('fulfill');
        $this->transactionRepository->expects(self::never())->method('save');

        self::assertSame(FulfillmentOutcome::Failed, $this->handler->handlePaymentPaid('tr_1')->outcome);
    }

    public function testHandlePaymentFailed_AlsoRetriesOnAStaleSave(): void
    {
        $stale = $this->pendingContract();
        $fresh = $this->pendingContract();
        $this->contractRepository->method('findByProviderOrderId')->willReturnOnConsecutiveCalls($stale, $fresh);
        $this->contractRepository->method('save')->willReturnCallback(function (PaymentContractInterface $c) use ($stale): void {
            if ($c === $stale) {
                throw new StaleContractException('c-1', 1, 2);
            }
        });

        self::assertSame(FulfillmentOutcome::Acted, $this->handler->handlePaymentFailed('tr_1', 'declined')->outcome);
    }

    /**
     * VR-22 A6 - an automatically captured payment is booked by payment-base's capture ledger under the Mollie
     * payment id, like an admin capture is booked under its capture id.
     */
    public function testHandlePaymentPaid_BooksTheAutomaticCaptureThroughTheLedgerOnceTheContractIsCommitted(): void
    {
        $contract = $this->pendingContract();
        $this->contractRepository->method('findByProviderOrderId')->willReturn($contract);
        $this->contractFulfillmentService->method('fulfill')->willReturn(true);
        $this->captures->expects(self::once())->method('book')->with('c-1', 'tr_1', 10.0, 'EUR', 'webhook')
            ->willReturn(CaptureBooking::booked(10.0));

        $this->handler->handlePaymentPaid('tr_1');
    }

    public function testHandlePaymentPaid_AfterAnAdminCapture_BooksNothingAndOnlyFulfils(): void
    {
        $contract = $this->committedContract();
        $contract->setCapturedAmount(4.0);   // a manual partial capture, booked by the admin action
        $this->contractRepository->method('findByProviderOrderId')->willReturn($contract);
        $this->contractFulfillmentService->expects(self::once())->method('fulfill')->willReturn(true);
        $this->captures->expects(self::never())->method('book');

        $this->handler->handlePaymentPaid('tr_1');

        self::assertSame(4.0, $contract->getCapturedAmount());
    }

    /** A manual capture was booked already, so the paid webhook books nothing and only fulfils. */
    private function capturedCommittedContract(): PaymentContractInterface
    {
        $contract = $this->committedContract();
        $contract->setCapturedAmount(4.0);

        return $contract;
    }

    private function committedContract(): PaymentContractInterface
    {
        $contract = $this->pendingContract();
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);
        $contract->commitToOrder('order-1');

        return $contract;
    }

    private function handlerWith(ContractCommitServiceInterface $commit): WebhookContractFulfillmentHandler
    {
        return new WebhookContractFulfillmentHandler(
            $this->contractRepository,
            $this->contractFulfillmentService,
            $this->orderUpdater,
            $this->auditRecorder,
            new NullLogger(),
            $this->lateMoney,
            $commit,
            $this->captures,
        );
    }

    private function pendingContract(): PaymentContractInterface
    {
        $contract = new \OxidEsales\PaymentBase\Contract\PaymentContract(1, 'user-1', \OxidEsales\PaymentBase\Contract\BasketSnapshot::fromArray([
            'items' => [], 'discounts' => [], 'totalGross' => 10.0, 'totalNet' => 8.4, 'totalVat' => 1.6, 'currency' => 'EUR',
        ]), 'c-1');
        $contract->addCondition(ContractCondition::paymentAuthorized());
        $contract->transitionToNotFinished('order-1');
        $contract->transitionToPending();

        return $contract;
    }

    public function testHandlePaymentFailed_ReturnsNullWhenContractNotFound(): void
    {
        $this->contractRepository->method('findByProviderOrderId')->willReturn(null);

        self::assertSame(FulfillmentOutcome::ContractNotFound, $this->handler->handlePaymentFailed('tr_missing', 'declined')->outcome);
    }

    public function testHandlePaymentFailed_IsNoOpWhenAlreadyTerminal(): void
    {
        $contract = $this->contractWithState(terminal: true);
        $this->contractRepository->method('findByProviderOrderId')->willReturn($contract);

        $contract->expects(self::never())->method('fail');

        self::assertSame(FulfillmentOutcome::NoOp, $this->handler->handlePaymentFailed('tr_failed', 'declined')->outcome);
    }

    public function testHandlePaymentFailed_FailsContractAndMirrorsOrderAndRecordsTransaction(): void
    {
        $contract = $this->contractWithState(terminal: false);
        $contract->method('getOrderId')->willReturn('order-2');
        $this->contractRepository->method('findByProviderOrderId')->willReturn($contract);

        $contract->expects(self::once())->method('fail')->with('declined');
        $this->contractRepository->expects(self::once())->method('save')->with($contract);
        $this->orderUpdater->expects(self::once())->method('markFailed')->with('order-2', 'declined');
        $this->expectTransactionRecorded(MollieDefinitions::TRANSACTION_TYPE_FAILURE, MollieDefinitions::TRANSACTION_STATUS_FAILED, 0.0);

        self::assertSame(FulfillmentOutcome::Acted, $this->handler->handlePaymentFailed('tr_failed', 'declined')->outcome);
    }

    public function testHandlePaymentFailed_DoesNotMirrorOrderWhenOrderIdMissing(): void
    {
        $contract = $this->contractWithState(terminal: false);
        $contract->method('getOrderId')->willReturn(null);
        $this->contractRepository->method('findByProviderOrderId')->willReturn($contract);

        $this->orderUpdater->expects(self::never())->method('markFailed');

        self::assertSame(FulfillmentOutcome::Acted, $this->handler->handlePaymentFailed('tr_failed', 'declined')->outcome);
    }

    // --- handlePaymentExpired ---

    public function testHandlePaymentExpired_ReturnsNullWhenContractNotFound(): void
    {
        $this->contractRepository->method('findByProviderOrderId')->willReturn(null);

        self::assertSame(FulfillmentOutcome::ContractNotFound, $this->handler->handlePaymentExpired('tr_missing')->outcome);
    }

    public function testHandlePaymentExpired_IsNoOpWhenAlreadyTerminal(): void
    {
        $contract = $this->contractWithState(terminal: true);
        $this->contractRepository->method('findByProviderOrderId')->willReturn($contract);

        $contract->expects(self::never())->method('expire');

        self::assertSame(FulfillmentOutcome::NoOp, $this->handler->handlePaymentExpired('tr_expired')->outcome);
    }

    public function testHandlePaymentExpired_ExpiresContractAndRecordsTransaction(): void
    {
        $contract = $this->contractWithState(terminal: false);
        $this->contractRepository->method('findByProviderOrderId')->willReturn($contract);

        $contract->expects(self::once())->method('expire');
        $this->contractRepository->expects(self::once())->method('save')->with($contract);
        $this->expectTransactionRecorded(MollieDefinitions::TRANSACTION_TYPE_EXPIRATION, MollieDefinitions::TRANSACTION_STATUS_FAILED, 0.0);

        self::assertSame(FulfillmentOutcome::Acted, $this->handler->handlePaymentExpired('tr_expired')->outcome);
    }

    /**
     * STRP-168. `committed` is not a terminal state, so the guard above let a
     * contract whose payment had already been taken reach expire(). That used
     * to silently rewrite settled payment history; since payment-base hardened
     * the state machine it throws instead, which out of a webhook means a 500
     * and a provider retrying forever. Either way the answer is to skip it.
     */
    public function testHandlePaymentExpired_IsNoOpWhenThePaymentWasAlreadyTaken(): void
    {
        $contract = $this->contractWithState(committed: true);
        $this->contractRepository->method('findByProviderOrderId')->willReturn($contract);

        $contract->expects(self::never())->method('expire');
        $this->contractRepository->expects(self::never())->method('save');

        self::assertSame(FulfillmentOutcome::NoOp, $this->handler->handlePaymentExpired('tr_committed')->outcome);
    }

    // --- handlePaymentCanceled ---

    public function testHandlePaymentCanceled_ReturnsNullWhenContractNotFound(): void
    {
        $this->contractRepository->method('findByProviderOrderId')->willReturn(null);

        self::assertSame(FulfillmentOutcome::ContractNotFound, $this->handler->handlePaymentCanceled('tr_missing', 'customer_canceled')->outcome);
    }

    public function testHandlePaymentCanceled_IsNoOpWhenAlreadyTerminal(): void
    {
        $contract = $this->contractWithState(terminal: true);
        $this->contractRepository->method('findByProviderOrderId')->willReturn($contract);

        $contract->expects(self::never())->method('cancel');

        self::assertSame(FulfillmentOutcome::NoOp, $this->handler->handlePaymentCanceled('tr_canceled', 'customer_canceled')->outcome);
    }

    public function testHandlePaymentCanceled_CancelsContractAndMirrorsOrderAndRecordsTransaction(): void
    {
        $contract = $this->contractWithState(terminal: false);
        $contract->method('getOrderId')->willReturn('order-3');
        $this->contractRepository->method('findByProviderOrderId')->willReturn($contract);

        $contract->expects(self::once())->method('cancel')->with('customer_canceled');
        $this->contractRepository->expects(self::once())->method('save')->with($contract);
        $this->orderUpdater->expects(self::once())->method('markCancelled')->with('order-3');
        $this->expectTransactionRecorded(MollieDefinitions::TRANSACTION_TYPE_CANCELLATION, MollieDefinitions::TRANSACTION_STATUS_FAILED, 0.0);

        self::assertSame(FulfillmentOutcome::Acted, $this->handler->handlePaymentCanceled('tr_canceled', 'customer_canceled')->outcome);
    }

    // --- handlePaymentAuthorized ---

    public function testHandlePaymentAuthorized_ReturnsNullWhenContractNotFound(): void
    {
        $this->contractRepository->method('findByProviderOrderId')->willReturn(null);

        self::assertSame(FulfillmentOutcome::ContractNotFound, $this->handler->handlePaymentAuthorized('tr_missing')->outcome);
    }

    public function testHandlePaymentAuthorized_IsNoOpWhenAlreadyAuthorized(): void
    {
        $contract = $this->contractWithState(notFinished: false, pending: false);
        $this->contractRepository->method('findByProviderOrderId')->willReturn($contract);

        $contract->expects(self::never())->method('transitionToPending');
        $contract->expects(self::never())->method('authorize');
        $this->contractRepository->expects(self::never())->method('save');

        self::assertSame(FulfillmentOutcome::NoOp, $this->handler->handlePaymentAuthorized('tr_authorized')->outcome);
    }

    public function testHandlePaymentAuthorized_IsNoOpWhenPastAuthorizationOrTerminal(): void
    {
        // READY_TO_COMMIT / COMMITTED / FULFILLED / CANCELLED / EXPIRED / FAILED — none of these
        // are NOT_FINISHED or PENDING, so the contract is left untouched either way.
        $contract = $this->contractWithState(notFinished: false, pending: false);
        $this->contractRepository->method('findByProviderOrderId')->willReturn($contract);

        $contract->expects(self::never())->method('authorize');
        $this->contractRepository->expects(self::never())->method('save');

        self::assertSame(FulfillmentOutcome::NoOp, $this->handler->handlePaymentAuthorized('tr_captured')->outcome);
    }

    private function contractWithState(
        bool $fulfilled = false,
        bool $terminal = false,
        bool $notFinished = false,
        bool $pending = false,
        bool $committed = false,
    ): PaymentContractInterface&MockObject {
        $state = $this->createMock(ContractState::class);
        $state->method('isFulfilled')->willReturn($fulfilled);
        $state->method('isTerminal')->willReturn($terminal);
        $state->method('isNotFinished')->willReturn($notFinished);
        $state->method('isPending')->willReturn($pending);
        $state->method('isCommitted')->willReturn($committed);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getState')->willReturn($state);
        $contract->method('getId')->willReturn('contract-1');
        $contract->method('getCurrency')->willReturn('EUR');
        $contract->method('getProviderOrderId')->willReturn('tr_1');

        return $contract;
    }

    private function expectTransactionRecorded(string $type, string $status, float $amount): void
    {
        $this->transactionRepository->expects(self::once())
            ->method('save')
            ->with(self::callback(function (Transaction $transaction) use ($type, $status, $amount): bool {
                self::assertSame($type, $transaction->getType());
                self::assertSame($status, $transaction->getStatus());
                self::assertSame($amount, $transaction->getAmount());
                return true;
            }));
    }
}
