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
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\Service\TransactionAuditRecorder;

/**
 * Mollie `failed`, `expired` and `canceled`: the payment ended without money. payment-base's termination service ends
 * the contract and its linked order; the audit row (Mollie's transaction types) and the "payment at risk" flag for an
 * expiry on a committed contract stay here.
 */
final class UnpaidPaymentTerminator
{
    public function __construct(
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly ContractTerminationServiceInterface $termination,
        private readonly TransactionAuditRecorder $auditRecorder,
        private readonly PaymentAtRiskFlagInterface $atRisk,
    ) {
    }

    public function fail(string $providerOrderId, string $reason): FulfillmentResult
    {
        $contract = $this->contractRepository->findByProviderOrderId($providerOrderId);
        if ($contract === null) {
            return FulfillmentResult::contractNotFound();
        }

        if ($this->termination->fail($contract, $reason) === TerminationOutcome::AlreadyTerminal) {
            return FulfillmentResult::noOp((string) $contract->getId());
        }

        return $this->recorded($contract, MollieDefinitions::TRANSACTION_TYPE_FAILURE);
    }

    public function expire(string $providerOrderId): FulfillmentResult
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

        return $this->recorded($contract, MollieDefinitions::TRANSACTION_TYPE_EXPIRATION);
    }

    public function cancel(string $providerOrderId, string $reason): FulfillmentResult
    {
        $contract = $this->contractRepository->findByProviderOrderId($providerOrderId);
        if ($contract === null) {
            return FulfillmentResult::contractNotFound();
        }

        if ($this->termination->cancel($contract, $reason) === TerminationOutcome::AlreadyTerminal) {
            return FulfillmentResult::noOp((string) $contract->getId());
        }

        return $this->recorded($contract, MollieDefinitions::TRANSACTION_TYPE_CANCELLATION);
    }

    private function recorded(PaymentContractInterface $contract, string $transactionType): FulfillmentResult
    {
        $this->auditRecorder->record($contract, $transactionType, MollieDefinitions::TRANSACTION_STATUS_FAILED, 0.0);

        return FulfillmentResult::acted((string) $contract->getId());
    }
}
