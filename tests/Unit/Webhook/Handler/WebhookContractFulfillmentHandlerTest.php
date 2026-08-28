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
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Repository\TransactionRepositoryInterface;
use OxidEsales\PaymentBase\Service\ContractFulfillmentServiceInterface;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\Service\ContractLinkedOrderUpdaterInterface;
use OxidEsales\Payments\Mollie\Service\TransactionAuditRecorder;
use OxidEsales\Payments\Mollie\Webhook\Handler\FulfillmentOutcome;
use OxidEsales\Payments\Mollie\Webhook\Handler\WebhookContractFulfillmentHandler;
use PHPUnit\Framework\Attributes\CoversClass;
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
    private WebhookContractFulfillmentHandler $handler;

    protected function setUp(): void
    {
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->contractFulfillmentService = $this->createMock(ContractFulfillmentServiceInterface::class);
        $this->orderUpdater = $this->createMock(ContractLinkedOrderUpdaterInterface::class);
        $this->transactionRepository = $this->createMock(TransactionRepositoryInterface::class);
        $shopAdapter = $this->createMock(ShopAdapterInterface::class);
        $shopAdapter->method('getShopId')->willReturn('1');
        $this->auditRecorder = new TransactionAuditRecorder($this->transactionRepository, $shopAdapter);

        $this->handler = new WebhookContractFulfillmentHandler(
            $this->contractRepository,
            $this->contractFulfillmentService,
            $this->orderUpdater,
            $this->auditRecorder,
            new NullLogger(),
        );
    }

    // --- handlePaymentPaid ---

    public function testHandlePaymentPaid_ReturnsNullWhenContractNotFound(): void
    {
        $this->contractRepository->method('findByProviderOrderId')->willReturn(null);

        self::assertSame(FulfillmentOutcome::ContractNotFound, $this->handler->handlePaymentPaid('tr_missing'));
    }

    public function testHandlePaymentPaid_IsNoOpWhenAlreadyFulfilled(): void
    {
        $contract = $this->contractWithState(fulfilled: true);
        $this->contractRepository->method('findByProviderOrderId')->willReturn($contract);

        $contract->expects(self::never())->method('transitionToPending');
        $this->contractRepository->expects(self::never())->method('save');
        $this->contractFulfillmentService->expects(self::never())->method('fulfill');

        self::assertSame(FulfillmentOutcome::NoOp, $this->handler->handlePaymentPaid('tr_paid'));
    }

    public function testHandlePaymentPaid_ClimbsLadderAndFulfillsAndRecordsTransaction(): void
    {
        $contract = $this->contractWithState(fulfilled: false);
        $contract->method('getOrderId')->willReturn('order-1');
        $contract->method('getAmount')->willReturn(19.99);
        $this->contractRepository->method('findByProviderOrderId')->willReturn($contract);

        $contract->expects(self::once())->method('transitionToPending');
        $contract->expects(self::once())
            ->method('fulfillCondition')
            ->with(ContractCondition::TYPE_PAYMENT_AUTHORIZED);
        $contract->expects(self::once())->method('commitToOrder')->with('order-1');
        $this->contractRepository->expects(self::once())->method('save')->with($contract);

        $this->contractFulfillmentService->expects(self::once())
            ->method('fulfill')
            ->with($contract)
            ->willReturn(true);

        $this->expectTransactionRecorded(MollieDefinitions::TRANSACTION_TYPE_CAPTURE, MollieDefinitions::TRANSACTION_STATUS_COMPLETED, 19.99);

        self::assertSame(FulfillmentOutcome::Acted, $this->handler->handlePaymentPaid('tr_paid'));
    }

    public function testHandlePaymentPaid_SwallowsDomainExceptionsFromLadderSteps(): void
    {
        $contract = $this->contractWithState(fulfilled: false);
        $contract->method('getOrderId')->willReturn('order-1');
        $contract->method('transitionToPending')->willThrowException(new DomainException('already past pending'));
        $contract->method('fulfillCondition')->willThrowException(new DomainException('already fulfilled condition'));
        $contract->method('commitToOrder')->willThrowException(new DomainException('not ready'));
        $this->contractRepository->method('findByProviderOrderId')->willReturn($contract);

        $this->contractFulfillmentService->method('fulfill')->willReturn(false);

        $this->transactionRepository->expects(self::never())->method('save');

        self::assertSame(FulfillmentOutcome::Failed, $this->handler->handlePaymentPaid('tr_paid'));
    }

    public function testHandlePaymentPaid_DoesNotRecordTransactionWhenFulfillmentServiceDeclines(): void
    {
        $contract = $this->contractWithState(fulfilled: false);
        $contract->method('getOrderId')->willReturn(null);
        $this->contractRepository->method('findByProviderOrderId')->willReturn($contract);

        $this->contractFulfillmentService->method('fulfill')->willReturn(false);

        $this->transactionRepository->expects(self::never())->method('save');

        self::assertSame(FulfillmentOutcome::Failed, $this->handler->handlePaymentPaid('tr_paid'));
    }

    // --- handlePaymentFailed ---

    public function testHandlePaymentFailed_ReturnsNullWhenContractNotFound(): void
    {
        $this->contractRepository->method('findByProviderOrderId')->willReturn(null);

        self::assertSame(FulfillmentOutcome::ContractNotFound, $this->handler->handlePaymentFailed('tr_missing', 'declined'));
    }

    public function testHandlePaymentFailed_IsNoOpWhenAlreadyTerminal(): void
    {
        $contract = $this->contractWithState(terminal: true);
        $this->contractRepository->method('findByProviderOrderId')->willReturn($contract);

        $contract->expects(self::never())->method('fail');

        self::assertSame(FulfillmentOutcome::NoOp, $this->handler->handlePaymentFailed('tr_failed', 'declined'));
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

        self::assertSame(FulfillmentOutcome::Acted, $this->handler->handlePaymentFailed('tr_failed', 'declined'));
    }

    public function testHandlePaymentFailed_DoesNotMirrorOrderWhenOrderIdMissing(): void
    {
        $contract = $this->contractWithState(terminal: false);
        $contract->method('getOrderId')->willReturn(null);
        $this->contractRepository->method('findByProviderOrderId')->willReturn($contract);

        $this->orderUpdater->expects(self::never())->method('markFailed');

        self::assertSame(FulfillmentOutcome::Acted, $this->handler->handlePaymentFailed('tr_failed', 'declined'));
    }

    // --- handlePaymentExpired ---

    public function testHandlePaymentExpired_ReturnsNullWhenContractNotFound(): void
    {
        $this->contractRepository->method('findByProviderOrderId')->willReturn(null);

        self::assertSame(FulfillmentOutcome::ContractNotFound, $this->handler->handlePaymentExpired('tr_missing'));
    }

    public function testHandlePaymentExpired_IsNoOpWhenAlreadyTerminal(): void
    {
        $contract = $this->contractWithState(terminal: true);
        $this->contractRepository->method('findByProviderOrderId')->willReturn($contract);

        $contract->expects(self::never())->method('expire');

        self::assertSame(FulfillmentOutcome::NoOp, $this->handler->handlePaymentExpired('tr_expired'));
    }

    public function testHandlePaymentExpired_ExpiresContractAndRecordsTransaction(): void
    {
        $contract = $this->contractWithState(terminal: false);
        $this->contractRepository->method('findByProviderOrderId')->willReturn($contract);

        $contract->expects(self::once())->method('expire');
        $this->contractRepository->expects(self::once())->method('save')->with($contract);
        $this->expectTransactionRecorded(MollieDefinitions::TRANSACTION_TYPE_EXPIRATION, MollieDefinitions::TRANSACTION_STATUS_FAILED, 0.0);

        self::assertSame(FulfillmentOutcome::Acted, $this->handler->handlePaymentExpired('tr_expired'));
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

        self::assertSame(FulfillmentOutcome::NoOp, $this->handler->handlePaymentExpired('tr_committed'));
    }

    // --- handlePaymentCanceled ---

    public function testHandlePaymentCanceled_ReturnsNullWhenContractNotFound(): void
    {
        $this->contractRepository->method('findByProviderOrderId')->willReturn(null);

        self::assertSame(FulfillmentOutcome::ContractNotFound, $this->handler->handlePaymentCanceled('tr_missing', 'customer_canceled'));
    }

    public function testHandlePaymentCanceled_IsNoOpWhenAlreadyTerminal(): void
    {
        $contract = $this->contractWithState(terminal: true);
        $this->contractRepository->method('findByProviderOrderId')->willReturn($contract);

        $contract->expects(self::never())->method('cancel');

        self::assertSame(FulfillmentOutcome::NoOp, $this->handler->handlePaymentCanceled('tr_canceled', 'customer_canceled'));
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

        self::assertSame(FulfillmentOutcome::Acted, $this->handler->handlePaymentCanceled('tr_canceled', 'customer_canceled'));
    }

    // --- handlePaymentAuthorized ---

    public function testHandlePaymentAuthorized_ReturnsNullWhenContractNotFound(): void
    {
        $this->contractRepository->method('findByProviderOrderId')->willReturn(null);

        self::assertSame(FulfillmentOutcome::ContractNotFound, $this->handler->handlePaymentAuthorized('tr_missing'));
    }

    public function testHandlePaymentAuthorized_IsNoOpWhenAlreadyAuthorized(): void
    {
        $contract = $this->contractWithState(notFinished: false, pending: false);
        $this->contractRepository->method('findByProviderOrderId')->willReturn($contract);

        $contract->expects(self::never())->method('transitionToPending');
        $contract->expects(self::never())->method('authorize');
        $this->contractRepository->expects(self::never())->method('save');

        self::assertSame(FulfillmentOutcome::NoOp, $this->handler->handlePaymentAuthorized('tr_authorized'));
    }

    public function testHandlePaymentAuthorized_IsNoOpWhenPastAuthorizationOrTerminal(): void
    {
        // READY_TO_COMMIT / COMMITTED / FULFILLED / CANCELLED / EXPIRED / FAILED — none of these
        // are NOT_FINISHED or PENDING, so the contract is left untouched either way.
        $contract = $this->contractWithState(notFinished: false, pending: false);
        $this->contractRepository->method('findByProviderOrderId')->willReturn($contract);

        $contract->expects(self::never())->method('authorize');
        $this->contractRepository->expects(self::never())->method('save');

        self::assertSame(FulfillmentOutcome::NoOp, $this->handler->handlePaymentAuthorized('tr_captured'));
    }

    public function testHandlePaymentAuthorized_FromNotFinished_AuthorizesContractAndRecordsTransaction(): void
    {
        $contract = $this->contractWithState(notFinished: true, pending: false);
        $contract->method('getAmount')->willReturn(42.5);
        $this->contractRepository->method('findByProviderOrderId')->willReturn($contract);

        $contract->expects(self::once())->method('transitionToPending');
        $contract->expects(self::once())->method('authorize');
        $this->contractRepository->expects(self::once())->method('save')->with($contract);

        $this->expectTransactionRecorded(MollieDefinitions::TRANSACTION_TYPE_AUTHORIZATION, MollieDefinitions::TRANSACTION_STATUS_COMPLETED, 42.5);

        self::assertSame(FulfillmentOutcome::Acted, $this->handler->handlePaymentAuthorized('tr_authorized'));
    }

    public function testHandlePaymentAuthorized_FromPending_SwallowsTransitionToPendingDomainException(): void
    {
        $contract = $this->contractWithState(notFinished: false, pending: true);
        $contract->method('getAmount')->willReturn(10.0);
        $contract->method('transitionToPending')->willThrowException(new DomainException('already past NOT_FINISHED'));
        $this->contractRepository->method('findByProviderOrderId')->willReturn($contract);

        $contract->expects(self::once())->method('authorize');
        $this->contractRepository->expects(self::once())->method('save')->with($contract);

        self::assertSame(FulfillmentOutcome::Acted, $this->handler->handlePaymentAuthorized('tr_authorized'));
    }

    public function testHandlePaymentAuthorized_SwallowsDomainExceptionFromAuthorizeStep(): void
    {
        $contract = $this->contractWithState(notFinished: true, pending: false);
        $contract->method('authorize')->willThrowException(new DomainException('unexpected concurrent state change'));
        $this->contractRepository->method('findByProviderOrderId')->willReturn($contract);

        $this->contractRepository->expects(self::once())->method('save')->with($contract);
        $this->transactionRepository->expects(self::once())->method('save');

        self::assertSame(FulfillmentOutcome::Acted, $this->handler->handlePaymentAuthorized('tr_authorized'));
    }

    public function testHandlePaymentAuthorized_DoesNotFulfillOrTouchOxpaid(): void
    {
        $contract = $this->contractWithState(notFinished: true, pending: false);
        $contract->method('getAmount')->willReturn(10.0);
        $this->contractRepository->method('findByProviderOrderId')->willReturn($contract);

        $this->contractFulfillmentService->expects(self::never())->method('fulfill');
        $this->orderUpdater->expects(self::never())->method('markFailed');
        $this->orderUpdater->expects(self::never())->method('markCancelled');
        $contract->expects(self::never())->method('fulfill');
        $contract->expects(self::never())->method('commitToOrder');

        self::assertSame(FulfillmentOutcome::Acted, $this->handler->handlePaymentAuthorized('tr_authorized'));
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
