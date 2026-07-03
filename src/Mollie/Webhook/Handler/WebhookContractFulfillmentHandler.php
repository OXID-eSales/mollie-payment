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
 * doesn't apply (already past it, or its precondition isn't met yet) is silently skipped rather
 * than propagating a DomainException. This makes the ladder safe to re-run on every delivery,
 * including out-of-order or duplicate ones.
 */
final class WebhookContractFulfillmentHandler implements WebhookContractFulfillmentHandlerInterface
{
    public function __construct(
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly ContractFulfillmentServiceInterface $contractFulfillmentService,
        private readonly ContractLinkedOrderUpdaterInterface $orderUpdater,
        private readonly TransactionAuditRecorder $auditRecorder,
    ) {
    }

    public function handlePaymentPaid(string $providerOrderId): ?bool
    {
        $contract = $this->contractRepository->findByProviderOrderId($providerOrderId);
        if ($contract === null) {
            return null;
        }

        if ($contract->getState()->isFulfilled()) {
            return false;
        }

        $this->advanceToCommitted($contract);
        $this->contractRepository->save($contract);

        $fulfilled = $this->contractFulfillmentService->fulfill($contract);
        if ($fulfilled) {
            $this->auditRecorder->record(
                $contract,
                MollieDefinitions::TRANSACTION_TYPE_CAPTURE,
                MollieDefinitions::TRANSACTION_STATUS_COMPLETED,
                $contract->getAmount(),
            );
        }

        return $fulfilled;
    }

    public function handlePaymentFailed(string $providerOrderId, string $reason): ?bool
    {
        $contract = $this->contractRepository->findByProviderOrderId($providerOrderId);
        if ($contract === null) {
            return null;
        }

        if ($contract->getState()->isTerminal()) {
            return false;
        }

        $contract->fail($reason);
        $this->contractRepository->save($contract);
        $this->mirrorOnLinkedOrder($contract, fn (string $orderId) => $this->orderUpdater->markFailed($orderId, $reason));
        $this->auditRecorder->record(
            $contract,
            MollieDefinitions::TRANSACTION_TYPE_FAILURE,
            MollieDefinitions::TRANSACTION_STATUS_FAILED,
            0.0,
        );

        return true;
    }

    public function handlePaymentExpired(string $providerOrderId): ?bool
    {
        $contract = $this->contractRepository->findByProviderOrderId($providerOrderId);
        if ($contract === null) {
            return null;
        }

        if ($contract->getState()->isTerminal()) {
            return false;
        }

        $contract->expire();
        $this->contractRepository->save($contract);
        $this->auditRecorder->record(
            $contract,
            MollieDefinitions::TRANSACTION_TYPE_EXPIRATION,
            MollieDefinitions::TRANSACTION_STATUS_FAILED,
            0.0,
        );

        return true;
    }

    public function handlePaymentCanceled(string $providerOrderId, string $reason): ?bool
    {
        $contract = $this->contractRepository->findByProviderOrderId($providerOrderId);
        if ($contract === null) {
            return null;
        }

        if ($contract->getState()->isTerminal()) {
            return false;
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

        return true;
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
    public function handlePaymentAuthorized(string $providerOrderId): ?bool
    {
        $contract = $this->contractRepository->findByProviderOrderId($providerOrderId);
        if ($contract === null) {
            return null;
        }

        if (!$contract->getState()->isNotFinished() && !$contract->getState()->isPending()) {
            return false;
        }

        $this->advanceToAuthorized($contract);
        $this->contractRepository->save($contract);
        $this->auditRecorder->record(
            $contract,
            MollieDefinitions::TRANSACTION_TYPE_AUTHORIZATION,
            MollieDefinitions::TRANSACTION_STATUS_COMPLETED,
            $contract->getAmount(),
        );

        return true;
    }

    /**
     * NOT_FINISHED → PENDING → AUTHORIZED, each step a named transition wrapped so an
     * inapplicable step (already advanced past it) is a silent no-op instead of propagating —
     * mirrors {@see advanceToCommitted()}.
     */
    private function advanceToAuthorized(PaymentContractInterface $contract): void
    {
        $this->attemptTransition(static fn () => $contract->transitionToPending());
        $this->attemptTransition(static fn () => $contract->authorize());
    }

    /**
     * Climb the contract ladder as far as its current state allows: PENDING → READY_TO_COMMIT
     * (via the `payment_authorized` condition) → COMMITTED. Each step is a named transition,
     * wrapped so an inapplicable step (already advanced past it, or precondition unmet) is a
     * silent no-op instead of propagating.
     */
    private function advanceToCommitted(PaymentContractInterface $contract): void
    {
        $this->attemptTransition(static fn () => $contract->transitionToPending());
        $this->attemptTransition(static fn () => $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));

        $orderId = $contract->getOrderId();
        if ($orderId !== null && $orderId !== '') {
            $this->attemptTransition(static fn () => $contract->commitToOrder($orderId));
        }
    }

    private function attemptTransition(callable $transition): void
    {
        try {
            $transition();
        } catch (DomainException) {
            // Idempotent: either the contract already advanced past this step, or this step's
            // precondition isn't met yet — safe to continue the ladder either way.
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
