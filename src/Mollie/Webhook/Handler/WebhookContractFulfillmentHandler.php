<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Webhook\Handler;

use DomainException;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Service\ContractFulfillmentServiceInterface;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\Service\ContractLinkedOrderUpdaterInterface;
use OxidEsales\Payments\Mollie\Service\TransactionAuditRecorder;
use Psr\Log\LoggerInterface;

/**
 * Default {@see WebhookContractFulfillmentHandlerInterface} implementation.
 *
 * The `paid` path is Mollie's one genuinely non-trivial ladder: unlike Stripe/PayPal, nothing
 * else in this module wires the shared PaymentAuthorizedEvent → ContractCommitmentHandler chain
 * (the return controller never dispatches it — Sprint 4 left commit/fulfill entirely to this
 * sprint). So the webhook is not just a backstop, it is the ONLY path that climbs the contract
 * from PENDING to COMMITTED, then hands off to the shared
 * {@see ContractFulfillmentServiceInterface} for the final COMMITTED → FULFILLED step (which
 * also dispatches ContractFulfilledEvent, driving payment-base's OrderPaymentCompletedHandler to
 * stamp OXPAID).
 *
 * Every step uses a named transition on the contract — never setState() — wrapped so a step that
 * doesn't apply (already past it, or its precondition isn't met yet) is skipped rather than
 * propagating a DomainException. This makes the ladder safe to re-run on every delivery, including
 * out-of-order or duplicate ones. Sprint 11 Story 8 (F8) made those skips *logged*: the catch
 * accepts every DomainException, including ones that are not benign, and it used to leave no trace
 * at all.
 */
final class WebhookContractFulfillmentHandler implements WebhookContractFulfillmentHandlerInterface
{
    public function __construct(
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly ContractFulfillmentServiceInterface $contractFulfillmentService,
        private readonly ContractLinkedOrderUpdaterInterface $orderUpdater,
        private readonly TransactionAuditRecorder $auditRecorder,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handlePaymentPaid(string $providerOrderId): FulfillmentOutcome
    {
        $contract = $this->contractRepository->findByProviderOrderId($providerOrderId);
        if ($contract === null) {
            return FulfillmentOutcome::ContractNotFound;
        }

        if ($contract->getState()->isFulfilled()) {
            return FulfillmentOutcome::NoOp;
        }

        $this->advanceToCommitted($contract);
        $this->contractRepository->save($contract);

        $fulfilled = $this->contractFulfillmentService->fulfill($contract);
        if (!$fulfilled) {
            // Sprint 11 Story 1 (F2): this is NOT the same as "already fulfilled". The ladder did
            // not complete, so Mollie must be told to come back — previously both returned `false`
            // and both were answered 200.
            $this->logger->warning('[WebhookContractFulfillmentHandler] fulfilment did not complete', [
                'contractId' => $contract->getId(),
                'providerOrderId' => $providerOrderId,
                'state' => $contract->getStateValue(),
            ]);

            return FulfillmentOutcome::Failed;
        }

        $this->auditRecorder->record(
            $contract,
            MollieDefinitions::TRANSACTION_TYPE_CAPTURE,
            MollieDefinitions::TRANSACTION_STATUS_COMPLETED,
            $contract->getAmount(),
        );

        return FulfillmentOutcome::Acted;
    }

    public function handlePaymentFailed(string $providerOrderId, string $reason): FulfillmentOutcome
    {
        $contract = $this->contractRepository->findByProviderOrderId($providerOrderId);
        if ($contract === null) {
            return FulfillmentOutcome::ContractNotFound;
        }

        if ($contract->getState()->isTerminal()) {
            return FulfillmentOutcome::NoOp;
        }

        $contract->fail($reason);
        $this->contractRepository->save($contract);
        $this->mirrorOnLinkedOrder(
            $contract,
            fn (string $orderId) => $this->orderUpdater->markFailed($orderId, $reason)
        );
        $this->auditRecorder->record(
            $contract,
            MollieDefinitions::TRANSACTION_TYPE_FAILURE,
            MollieDefinitions::TRANSACTION_STATUS_FAILED,
            0.0,
        );

        return FulfillmentOutcome::Acted;
    }

    public function handlePaymentExpired(string $providerOrderId): FulfillmentOutcome
    {
        $contract = $this->contractRepository->findByProviderOrderId($providerOrderId);
        if ($contract === null) {
            return FulfillmentOutcome::ContractNotFound;
        }

        if ($contract->getState()->isTerminal()) {
            return FulfillmentOutcome::NoOp;
        }

        // STRP-168: `committed` is not terminal, so a late or duplicate expiry
        // for a contract whose payment has already been taken used to reach
        // expire() and rewrite settled history. payment-base now refuses that
        // transition outright, so acting on it would throw out of the webhook
        // and Mollie would retry forever. Skip it.
        if ($contract->getState()->isCommitted()) {
            return FulfillmentOutcome::NoOp;
        }

        $contract->expire();
        $this->contractRepository->save($contract);
        $this->auditRecorder->record(
            $contract,
            MollieDefinitions::TRANSACTION_TYPE_EXPIRATION,
            MollieDefinitions::TRANSACTION_STATUS_FAILED,
            0.0,
        );

        return FulfillmentOutcome::Acted;
    }

    public function handlePaymentCanceled(string $providerOrderId, string $reason): FulfillmentOutcome
    {
        $contract = $this->contractRepository->findByProviderOrderId($providerOrderId);
        if ($contract === null) {
            return FulfillmentOutcome::ContractNotFound;
        }

        if ($contract->getState()->isTerminal()) {
            return FulfillmentOutcome::NoOp;
        }

        $contract->cancel($reason);
        $this->contractRepository->save($contract);
        $this->mirrorOnLinkedOrder($contract, fn (string $orderId) => $this->orderUpdater->markCancelled($orderId));
        $this->auditRecorder->record(
            $contract,
            MollieDefinitions::TRANSACTION_TYPE_CANCELLATION,
            MollieDefinitions::TRANSACTION_STATUS_FAILED,
            0.0,
        );

        return FulfillmentOutcome::Acted;
    }

    /**
     * Handle a Mollie `authorized` status: advances the contract from NOT_FINISHED/PENDING to
     * AUTHORIZED — the state {@see \OxidEsales\Payments\Mollie\Service\CaptureService} requires
     * before an admin capture can run.
     *
     * A contract that is anything other than NOT_FINISHED or PENDING is left untouched and this
     * is reported as a no-op: it is either already AUTHORIZED (re-delivered webhook) or has
     * already advanced past it (e.g. captured, committed, fulfilled, or terminal). Either way,
     * attempting the ladder would be a wasted (and harmless, thanks to {@see attemptTransition})
     * no-op, so it is skipped outright to avoid recording a spurious audit transaction.
     */
    public function handlePaymentAuthorized(string $providerOrderId): FulfillmentOutcome
    {
        $contract = $this->contractRepository->findByProviderOrderId($providerOrderId);
        if ($contract === null) {
            return FulfillmentOutcome::ContractNotFound;
        }

        if (!$contract->getState()->isNotFinished() && !$contract->getState()->isPending()) {
            return FulfillmentOutcome::NoOp;
        }

        $this->advanceToAuthorized($contract);
        $this->contractRepository->save($contract);
        $this->auditRecorder->record(
            $contract,
            MollieDefinitions::TRANSACTION_TYPE_AUTHORIZATION,
            MollieDefinitions::TRANSACTION_STATUS_COMPLETED,
            $contract->getAmount(),
        );

        return FulfillmentOutcome::Acted;
    }

    /**
     * NOT_FINISHED → PENDING → AUTHORIZED, each step a named transition wrapped so an
     * inapplicable step (already advanced past it) is a silent no-op instead of propagating —
     * mirrors {@see advanceToCommitted()}.
     */
    private function advanceToAuthorized(PaymentContractInterface $contract): void
    {
        $this->attemptTransition('transitionToPending', $contract, static fn () => $contract->transitionToPending());
        $this->attemptTransition('authorize', $contract, static fn () => $contract->authorize());
    }

    /**
     * Climb the contract ladder as far as its current state allows: PENDING → READY_TO_COMMIT
     * (via the `payment_authorized` condition) → COMMITTED. Each step is a named transition,
     * wrapped so an inapplicable step (already advanced past it, or precondition unmet) is a
     * silent no-op instead of propagating.
     */
    private function advanceToCommitted(PaymentContractInterface $contract): void
    {
        $this->attemptTransition('transitionToPending', $contract, static fn () => $contract->transitionToPending());
        $this->attemptTransition(
            'fulfillCondition',
            $contract,
            static fn () => $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED)
        );

        $orderId = $contract->getOrderId();
        if ($orderId === null || $orderId === '') {
            // Sprint 11 Story 8 (F8): the one case where "no order id" is a real problem, and it
            // used to be skipped in silence. The contract cannot reach COMMITTED, so fulfil() below
            // will fail and the delivery is now reported as retry-worthy rather than as a 200.
            $this->logger->warning(
                '[WebhookContractFulfillmentHandler] cannot commit: contract has no linked order id',
                ['contractId' => $contract->getId(), 'state' => $contract->getStateValue()],
            );

            return;
        }

        $this->attemptTransition('commitToOrder', $contract, static fn () => $contract->commitToOrder($orderId));
    }

    /**
     * Run one named transition, tolerating an inapplicable step.
     *
     * The catch is deliberately broad — the contract state machine signals "not applicable" with a
     * DomainException and the ladder must survive out-of-order deliveries. What it is NOT allowed to
     * do is stay silent: a DomainException raised for a reason other than "already past this step"
     * looks identical here, and before Sprint 11 nothing recorded it (F8).
     */
    private function attemptTransition(string $name, PaymentContractInterface $contract, callable $transition): void
    {
        try {
            $transition();
        } catch (DomainException $e) {
            $this->logger->warning('[WebhookContractFulfillmentHandler] transition not applied', [
                'transition' => $name,
                'contractId' => $contract->getId(),
                'state' => $contract->getStateValue(),
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param callable(string): void $mirror
     */
    private function mirrorOnLinkedOrder(PaymentContractInterface $contract, callable $mirror): void
    {
        $orderId = $contract->getOrderId();
        if ($orderId === null || $orderId === '') {
            return;
        }

        $mirror($orderId);
    }
}
