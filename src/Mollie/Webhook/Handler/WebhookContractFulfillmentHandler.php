<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Webhook\Handler;

use OxidEsales\PaymentBase\AtRisk\AtRiskReason;
use OxidEsales\PaymentBase\AtRisk\PaymentAtRiskFlagInterface;
use OxidEsales\PaymentBase\Contract\ContractTerminationServiceInterface;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Contract\TerminationOutcome;
use OxidEsales\PaymentBase\LateMoney\LateMoneyResolverInterface;
use OxidEsales\PaymentBase\Payment\ProviderCaptureLedgerInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Repository\ReplacedAttemptFinderInterface;
use OxidEsales\PaymentBase\Repository\StaleContractException;
use OxidEsales\PaymentBase\Service\Commit\CommitOutcome;
use OxidEsales\PaymentBase\Service\Commit\ContractCommitServiceInterface;
use OxidEsales\PaymentBase\Service\Commit\PaymentConfirmation;
use OxidEsales\PaymentBase\Service\ContractFulfillmentServiceInterface;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\Service\TransactionAuditRecorder;
use Psr\Log\LoggerInterface;

/**
 * Default {@see WebhookContractFulfillmentHandlerInterface} implementation.
 *
 * The `paid` path commits through payment-base's {@see ContractCommitServiceInterface} (VR-20 R6),
 * the same chain Stripe and PayPal use: PaymentAuthorizedEvent → PaymentAuthorizedEventHandler →
 * ContractReadyToCommitEvent → ContractCommitmentHandler. Mollie's return controller commits
 * nothing, so this webhook is the one path from PENDING to COMMITTED. A refusal (closed contract,
 * amount off the snapshot) or a chain that leaves the contract open fails the delivery so Mollie
 * retries. Then the shared {@see ContractFulfillmentServiceInterface} takes COMMITTED → FULFILLED,
 * which dispatches ContractFulfilledEvent and stamps OXPAID through OrderPaymentCompletedHandler.
 */
final class WebhookContractFulfillmentHandler implements WebhookContractFulfillmentHandlerInterface
{
    public function __construct(
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly ContractFulfillmentServiceInterface $contractFulfillmentService,
        // payment-base's one cancel/fail/expire path: the contract ends and the linked order follows.
        private readonly ContractTerminationServiceInterface $termination,
        private readonly TransactionAuditRecorder $auditRecorder,
        private readonly LoggerInterface $logger,
        // VR-23 T2: money on a contract that can no longer take it (cancelled, expired, failed, or a payment of an
        // attempt it replaced) goes back through payment-base's one rule; an expired authorization on a committed
        // contract flags the order "payment at risk".
        private readonly LateMoneyResolverInterface $lateMoney,
        private readonly PaymentAtRiskFlagInterface $atRisk,
        private readonly ReplacedAttemptFinderInterface $replacedAttempts,
        // VR-20 R6: required - the one commit path for `authorized` and `paid` (amount, currency
        // and state checks in payment-base); no hand-written ladder beside it.
        private readonly ContractCommitServiceInterface $contractCommit,
        // VR-22 A6: an automatically captured payment is booked like an admin capture, by payment-base's ledger.
        private readonly ProviderCaptureLedgerInterface $captures,
    ) {
    }

    public function handlePaymentAuthorized(string $providerOrderId): FulfillmentResult
    {
        return $this->attemptTwice(fn () => $this->doPaymentAuthorized($providerOrderId));
    }

    public function handlePaymentPaid(string $providerOrderId): FulfillmentResult
    {
        return $this->attemptTwice(fn () => $this->doPaymentPaid($providerOrderId));
    }

    public function handlePaymentFailed(string $providerOrderId, string $reason): FulfillmentResult
    {
        return $this->attemptTwice(fn () => $this->doPaymentFailed($providerOrderId, $reason));
    }

    public function handlePaymentExpired(string $providerOrderId): FulfillmentResult
    {
        return $this->attemptTwice(fn () => $this->doPaymentExpired($providerOrderId));
    }

    public function handlePaymentCanceled(string $providerOrderId, string $reason): FulfillmentResult
    {
        return $this->attemptTwice(fn () => $this->doPaymentCanceled($providerOrderId, $reason));
    }

    /**
     * MOL-17: the webhook and the shopper's return leg race on the contract. When a save here is refused
     * because the return leg moved the row on meanwhile, the whole step is run once more - it re-loads
     * the contract, so the ladder continues from the state the return leg left. A second refusal is
     * answered Failed (non-200), and Mollie comes back later with a fresh delivery.
     *
     * @param callable(): FulfillmentResult $step
     */
    private function attemptTwice(callable $step): FulfillmentResult
    {
        try {
            return $step();
        } catch (StaleContractException $first) {
            $this->logger->info(
                '[WebhookContractFulfillmentHandler] contract changed under the webhook; retrying on a fresh copy',
                ['contractId' => $first->contractId],
            );
        }

        try {
            return $step();
        } catch (StaleContractException $second) {
            $this->logger->warning(
                '[WebhookContractFulfillmentHandler] contract changed twice; asking Mollie to retry',
                ['contractId' => $second->contractId, 'error' => $second->getMessage()],
            );

            return FulfillmentResult::failed($second->contractId);
        }
    }

    /**
     * OPC-175 / S2g: a payment no contract names now may be one of a replaced attempt.
     */
    private function lateMoneyOfReplacedAttempt(string $providerOrderId): FulfillmentResult
    {
        $contract = $this->replacedAttempts->findByReplacedAttempt($providerOrderId);
        if ($contract === null) {
            return FulfillmentResult::contractNotFound();
        }

        return $this->giveLateMoneyBack($contract, $providerOrderId);
    }

    /**
     * VR-23 T2 (S-H3): `paid` or `authorized` for a contract the shop closed without payment (cancelled by the
     * stale-order cleanup or an admin, expired, failed). The resolver refunds / releases once and records it; a
     * refusal by Mollie is flagged by the resolver, not retried: Mollie is answered 200 either way, instead of a 5xx
     * until it gives up with the money taken and no order.
     */
    private function giveLateMoneyBack(PaymentContractInterface $contract, string $providerOrderId): FulfillmentResult
    {
        $this->lateMoney->resolve($contract, $providerOrderId);

        return FulfillmentResult::noOp((string) $contract->getId());
    }

    private function isClosedWithoutPayment(PaymentContractInterface $contract): bool
    {
        return $contract->getState()->isTerminal() && !$contract->getState()->isFulfilled();
    }

    private function doPaymentPaid(string $providerOrderId): FulfillmentResult
    {
        $contract = $this->contractRepository->findByProviderOrderId($providerOrderId);
        if ($contract === null) {
            return $this->lateMoneyOfReplacedAttempt($providerOrderId);
        }

        if ($contract->getState()->isFulfilled()) {
            return FulfillmentResult::noOp((string) $contract->getId());
        }
        if ($this->isClosedWithoutPayment($contract)) {
            return $this->giveLateMoneyBack($contract, $providerOrderId);
        }

        if (!$contract->getState()->isCommitted()) {
            $refusal = $this->commitPaid($contract, $providerOrderId);
            if ($refusal !== null) {
                return $refusal;
            }
            // The commit service saved its own copy; continue on the committed row.
            $contract = $this->contractRepository->findByProviderOrderId($providerOrderId) ?? $contract;
        }

        $contract = $this->bookAutomaticCapture($contract, $providerOrderId);
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

            return FulfillmentResult::failed((string) $contract->getId());
        }

        return FulfillmentResult::acted((string) $contract->getId());
    }

    private function doPaymentFailed(string $providerOrderId, string $reason): FulfillmentResult
    {
        $contract = $this->contractRepository->findByProviderOrderId($providerOrderId);
        if ($contract === null) {
            return FulfillmentResult::contractNotFound();
        }

        if ($this->termination->fail($contract, $reason) === TerminationOutcome::AlreadyTerminal) {
            return FulfillmentResult::noOp((string) $contract->getId());
        }

        $this->auditRecorder->record(
            $contract,
            MollieDefinitions::TRANSACTION_TYPE_FAILURE,
            MollieDefinitions::TRANSACTION_STATUS_FAILED,
            0.0,
        );

        return FulfillmentResult::acted((string) $contract->getId());
    }

    private function doPaymentExpired(string $providerOrderId): FulfillmentResult
    {
        $contract = $this->contractRepository->findByProviderOrderId($providerOrderId);
        if ($contract === null) {
            return FulfillmentResult::contractNotFound();
        }

        $outcome = $this->termination->expire($contract);
        if ($outcome === TerminationOutcome::AlreadyTerminal) {
            return FulfillmentResult::noOp((string) $contract->getId());
        }

        // STRP-168: `committed` is not terminal, and payment-base refuses expire() there, so acting on the
        // contract would throw out of the webhook and Mollie would retry forever. VR-23 T2 (S-M1): but an
        // authorization that expires on a committed manual-capture contract means the money will never be
        // captured while the order looks valid. Flag it; the merchant decides.
        if ($outcome === TerminationOutcome::Committed) {
            $this->atRisk->raise($contract, AtRiskReason::AuthorizationExpired, $providerOrderId);

            return FulfillmentResult::acted((string) $contract->getId());
        }

        $this->auditRecorder->record(
            $contract,
            MollieDefinitions::TRANSACTION_TYPE_EXPIRATION,
            MollieDefinitions::TRANSACTION_STATUS_FAILED,
            0.0,
        );

        return FulfillmentResult::acted((string) $contract->getId());
    }

    private function doPaymentCanceled(string $providerOrderId, string $reason): FulfillmentResult
    {
        $contract = $this->contractRepository->findByProviderOrderId($providerOrderId);
        if ($contract === null) {
            return FulfillmentResult::contractNotFound();
        }

        if ($this->termination->cancel($contract, $reason) === TerminationOutcome::AlreadyTerminal) {
            return FulfillmentResult::noOp((string) $contract->getId());
        }

        $this->auditRecorder->record(
            $contract,
            MollieDefinitions::TRANSACTION_TYPE_CANCELLATION,
            MollieDefinitions::TRANSACTION_STATUS_FAILED,
            0.0,
        );

        return FulfillmentResult::acted((string) $contract->getId());
    }

    /**
     * Handle a Mollie `authorized` status: commits the open contract (see {@see commitAuthorization()}), the
     * state an admin capture or void of the authorization starts from.
     *
     * A contract that is anything other than NOT_FINISHED or PENDING is left untouched and this
     * is reported as a no-op: it is either already AUTHORIZED (re-delivered webhook) or has
     * already advanced past it (e.g. captured, committed, fulfilled, or terminal). Either way,
     * attempting the ladder would be a wasted no-op, so it is skipped outright to avoid recording a
     * spurious audit transaction.
     */
    private function doPaymentAuthorized(string $providerOrderId): FulfillmentResult
    {
        $contract = $this->contractRepository->findByProviderOrderId($providerOrderId);
        if ($contract === null) {
            return $this->lateMoneyOfReplacedAttempt($providerOrderId);
        }
        if ($this->isClosedWithoutPayment($contract)) {
            return $this->giveLateMoneyBack($contract, $providerOrderId);
        }

        if (!$contract->getState()->isNotFinished() && !$contract->getState()->isPending()) {
            return FulfillmentResult::noOp((string) $contract->getId());
        }

        return $this->commitAuthorization($contract, $providerOrderId);
    }

    /**
     * VR-20 R6: `paid` on a contract that is not committed yet (no `authorized` webhook before it -
     * the usual automatic-capture case) commits through payment-base's ContractCommitService, like
     * `authorized` does. A refusal (amount mismatch, closed contract) or an open second condition
     * answers Failed: Mollie retries and the merchant sees it in the webhook log.
     */
    private function commitPaid(PaymentContractInterface $contract, string $providerOrderId): ?FulfillmentResult
    {
        $outcome = $this->contractCommit->commit(new PaymentConfirmation(
            contractId: (string) $contract->getId(),
            providerName: MollieDefinitions::PROVIDER_NAME,
            authorizationId: $providerOrderId,
            providerOrderId: $providerOrderId,
            amount: $contract->getAmount(),
            currency: $contract->getCurrency(),
            requiresCapture: false,
            source: 'webhook',
            extraContext: ['molliePaymentId' => $providerOrderId],
        ));
        if ($outcome->isSettled()) {
            return null;
        }

        $this->logger->warning('[WebhookContractFulfillmentHandler] paid, but the contract was not committed', [
            'contractId' => $contract->getId(),
            'outcome' => $outcome->outcome,
            'reason' => $outcome->reason,
        ]);

        return FulfillmentResult::failed((string) $contract->getId());
    }

    /**
     * GRAPH-QL / MS3 — an authorization ends the order. With manual capture the shopper's
     * payment comes back `authorized`, not `paid`; until now that only moved the contract to
     * AUTHORIZED and the order stayed NOT_FINISHED until the merchant captured. A headless
     * shopper who never returns would leave the money reserved on an unfinished order, so the
     * authorization now commits the open contract through payment-base's ContractCommitService
     * with `requiresCapture`: the order is committed, not marked paid; the admin capture
     * (committed is capturable) and the following `paid` webhook fulfil it as before. Same semantics as
     * Stripe's `payment_intent.amount_capturable_updated` handler.
     *
     * The commit service saves its own copy of the contract, so the one loaded here is NOT saved
     * afterwards (it would overwrite the committed state with PENDING). The confirmed amount is the
     * contract's: this handler only knows the Mollie payment id, and Mollie's amount was checked
     * when the payment was created from the contract.
     */
    private function commitAuthorization(
        PaymentContractInterface $contract,
        string $providerOrderId,
    ): FulfillmentResult {
        $outcome = $this->contractCommit->commit(new PaymentConfirmation(
            contractId: (string) $contract->getId(),
            providerName: MollieDefinitions::PROVIDER_NAME,
            authorizationId: $providerOrderId,
            providerOrderId: $providerOrderId,
            amount: $contract->getAmount(),
            currency: $contract->getCurrency(),
            requiresCapture: true,
            source: 'webhook',
            extraContext: ['molliePaymentId' => $providerOrderId],
        ));

        $this->logger->info('[WebhookContractFulfillmentHandler] authorization committed the open contract', [
            'contractId' => $contract->getId(),
            'outcome' => $outcome->outcome,
            'orderId' => $outcome->orderId,
            'reason' => $outcome->reason,
        ]);

        if ($outcome->isSettled()) {
            $this->auditRecorder->record(
                $contract,
                MollieDefinitions::TRANSACTION_TYPE_AUTHORIZATION,
                MollieDefinitions::TRANSACTION_STATUS_COMPLETED,
                $contract->getAmount(),
            );

            return FulfillmentResult::acted((string) $contract->getId());
        }

        if ($outcome->outcome === CommitOutcome::PENDING) {
            // Another condition is still open; the commit will follow when it closes.
            return FulfillmentResult::noOp((string) $contract->getId());
        }

        // Refused (amount mismatch, closed contract): a 5xx, so Mollie retries and the
        // merchant sees it in the webhook log instead of a silent 200.
        return FulfillmentResult::failed((string) $contract->getId());
    }

    /**
     * MOL-17 Story 5, VR-22 A6: an automatically captured payment (iDEAL, a card with automatic capture, ...) has no
     * capture of its own to book, so the admin panel showed "Captured 0.00" for every paid Mollie order. The paid
     * payment is the capture: payment-base's ledger books it once under the Mollie payment id (a redelivery books
     * nothing). Nothing is booked when a capture is on record already (a manual capture, booked by the admin
     * action by its Mollie capture id); the `paid` that follows it only fulfils.
     *
     * The ledger saves its own copy of the contract, so the copy to continue on is read again.
     */
    private function bookAutomaticCapture(
        PaymentContractInterface $contract,
        string $providerOrderId,
    ): PaymentContractInterface {
        if (($contract->getCapturedAmount() ?? 0.0) > 0.0 || !$contract->getState()->isCommitted()) {
            return $contract;
        }

        $this->captures->book(
            (string) $contract->getId(),
            $providerOrderId,
            $contract->getAmount(),
            $contract->getCurrency(),
            'webhook',
        );

        return $this->contractRepository->findByProviderOrderId($providerOrderId) ?? $contract;
    }
}
