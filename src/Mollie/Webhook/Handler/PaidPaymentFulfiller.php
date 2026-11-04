<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Webhook\Handler;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Payment\ProviderCaptureLedgerInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Service\Commit\ContractCommitServiceInterface;
use OxidEsales\PaymentBase\Service\Commit\PaymentConfirmation;
use OxidEsales\PaymentBase\Service\ContractFulfillmentServiceInterface;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use Psr\Log\LoggerInterface;

/**
 * Mollie `paid`: commits the contract through payment-base's {@see ContractCommitServiceInterface} (VR-20 R6), the
 * same chain Stripe and PayPal use, books an automatic capture, then the shared
 * {@see ContractFulfillmentServiceInterface} takes COMMITTED → FULFILLED, which dispatches ContractFulfilledEvent and
 * stamps OXPAID through OrderPaymentCompletedHandler. Mollie's return controller commits nothing, so this webhook is
 * the one path from PENDING to COMMITTED. A refusal (closed contract, amount off the snapshot) or a chain that leaves
 * the contract open fails the delivery so Mollie retries.
 */
final class PaidPaymentFulfiller
{
    public function __construct(
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly ContractFulfillmentServiceInterface $contractFulfillmentService,
        private readonly ContractCommitServiceInterface $contractCommit,
        // VR-22 A6: an automatically captured payment is booked like an admin capture, by payment-base's ledger.
        private readonly ProviderCaptureLedgerInterface $captures,
        private readonly ClosedContractLateMoney $lateMoney,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function fulfil(string $providerOrderId): FulfillmentResult
    {
        $contract = $this->contractRepository->findByProviderOrderId($providerOrderId);
        if ($contract === null) {
            return $this->lateMoney->forUnknownPayment($providerOrderId);
        }

        if ($contract->getState()->isFulfilled()) {
            return FulfillmentResult::noOp((string) $contract->getId());
        }
        if ($this->lateMoney->isClosedWithoutPayment($contract)) {
            return $this->lateMoney->giveBack($contract, $providerOrderId);
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

        return $this->fulfilled($contract, $providerOrderId);
    }

    private function fulfilled(PaymentContractInterface $contract, string $providerOrderId): FulfillmentResult
    {
        if ($this->contractFulfillmentService->fulfill($contract)) {
            return FulfillmentResult::acted((string) $contract->getId());
        }

        // This is NOT the same as "already fulfilled". The ladder did not complete, so
        // Mollie must be told to come back.
        $this->logger->warning('[PaidPaymentFulfiller] fulfilment did not complete', [
            'contractId' => $contract->getId(),
            'providerOrderId' => $providerOrderId,
            'state' => $contract->getStateValue(),
        ]);

        return FulfillmentResult::failed((string) $contract->getId());
    }

    /**
     * VR-20 R6: `paid` on a contract that is not committed yet (no `authorized` webhook before it - the usual
     * automatic-capture case) commits through the shared commit service. A refusal (amount mismatch, closed
     * contract) or an open second condition answers Failed: Mollie retries and the merchant sees it in the webhook log.
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

        $this->logger->warning('[PaidPaymentFulfiller] paid, but the contract was not committed', [
            'contractId' => $contract->getId(),
            'outcome' => $outcome->outcome,
            'reason' => $outcome->reason,
        ]);

        return FulfillmentResult::failed((string) $contract->getId());
    }

    /**
     * An automatically captured payment (iDEAL, a card with automatic capture, ...) has no
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
