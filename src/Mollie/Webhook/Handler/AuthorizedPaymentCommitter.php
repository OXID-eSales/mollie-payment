<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Webhook\Handler;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Service\Commit\CommitOutcome;
use OxidEsales\PaymentBase\Service\Commit\ContractCommitServiceInterface;
use OxidEsales\PaymentBase\Service\Commit\PaymentConfirmation;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\Service\TransactionAuditRecorder;
use Psr\Log\LoggerInterface;

/**
 * Mollie `authorized`: commits the open contract, the state an admin capture or void of the authorization starts from.
 *
 * A contract that is anything other than NOT_FINISHED or PENDING is left untouched and this is reported as a no-op: it
 * is either already AUTHORIZED (re-delivered webhook) or has already advanced past it (e.g. captured, committed,
 * fulfilled, or terminal). Attempting the ladder would be a wasted no-op, so it is skipped outright to avoid recording
 * a spurious audit transaction.
 */
final class AuthorizedPaymentCommitter
{
    public function __construct(
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly ContractCommitServiceInterface $contractCommit,
        private readonly TransactionAuditRecorder $auditRecorder,
        private readonly ClosedContractLateMoney $lateMoney,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function commit(string $providerOrderId): FulfillmentResult
    {
        $contract = $this->contractRepository->findByProviderOrderId($providerOrderId);
        if ($contract === null) {
            return $this->lateMoney->forUnknownPayment($providerOrderId);
        }
        if ($this->lateMoney->isClosedWithoutPayment($contract)) {
            return $this->lateMoney->giveBack($contract, $providerOrderId);
        }

        if (!$contract->getState()->isNotFinished() && !$contract->getState()->isPending()) {
            return FulfillmentResult::noOp((string) $contract->getId());
        }

        return $this->commitAuthorization($contract, $providerOrderId);
    }

    /**
     * GRAPH-QL / MS3 — an authorization ends the order. With manual capture the shopper's payment comes back
     * `authorized`, not `paid`; a headless shopper who never returns would leave the money reserved on an unfinished
     * order, so the authorization commits the open contract through payment-base's ContractCommitService with
     * `requiresCapture`: the order is committed, not marked paid; the admin capture (committed is capturable) and the
     * following `paid` webhook fulfil it. Same semantics as Stripe's `payment_intent.amount_capturable_updated` handler.
     *
     * The commit service saves its own copy of the contract, so the one loaded here is NOT saved afterwards (it would
     * overwrite the committed state with PENDING). The confirmed amount is the contract's: this handler only knows the
     * Mollie payment id, and Mollie's amount was checked when the payment was created from the contract.
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

        $this->logger->info('[AuthorizedPaymentCommitter] authorization committed the open contract', [
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
}
