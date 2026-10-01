<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

use DateTimeImmutable;
use DomainException;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\Payments\Mollie\Adapter\MolliePaymentsAdapterInterface;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;

/**
 * Releases the uncaptured part of a Mollie two-step authorization — Mollie's
 * `release-authorization` call, the documented way to cancel a hold.
 *
 * Two outcomes, decided by what the shop has captured so far:
 *  - nothing captured → the whole hold is released and the contract is CANCELLED, the linked
 *    order marked cancelled (the "cancel authorization" of the Stripe tab);
 *  - partially captured (multi-capture methods: cards with multi-capture, Klarna, PayPal, …) →
 *    only the remainder is released; the contract stays FULFILLED for the captured amount,
 *    the release is recorded on the contract ({@see AuthorizationReleaseMarker}) and in the
 *    transaction audit log. A card without multi-capture never gets here: Mollie releases its
 *    remainder by itself on the first partial capture, like Stripe does.
 *
 * Idempotent: a CANCELLED contract or an already released hold returns without touching Mollie.
 */
final class CancelAuthorizationService implements CancelAuthorizationServiceInterface
{
    private const CANCEL_REASON = 'mollie_authorization_canceled';

    public function __construct(
        private readonly MolliePaymentsAdapterInterface $paymentsAdapter,
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly ContractLinkedOrderUpdaterInterface $orderUpdater,
        private readonly TransactionAuditRecorder $auditRecorder,
    ) {
    }

    public function cancel(PaymentContractInterface $contract, ?string $reason = null): void
    {
        if ($contract->getState()->isCancelled() || AuthorizationReleaseMarker::isReleased($contract)) {
            return;
        }

        $this->assertCancellable($contract);
        $providerOrderId = $this->requireProviderOrderId($contract);

        $this->paymentsAdapter->releaseAuthorization($providerOrderId);

        if ($this->capturedAmount($contract) > 0.0) {
            $this->releaseRemainder($contract, $reason);

            return;
        }

        $contract->cancel($reason ?? self::CANCEL_REASON);
        $this->contractRepository->save($contract);
        $this->mirrorToLinkedOrder($contract);
    }

    private function assertCancellable(PaymentContractInterface $contract): void
    {
        // AUTHORIZED or COMMITTED: a manual-capture order is committed by the shared return
        // chain while its Mollie payment is still an uncaptured `authorized` hold (STRP-118
        // pattern). FULFILLED with a captured amount: a partial capture went through and the
        // remainder of the hold is still open. Mollie re-validates the live status, so the PSP
        // is the real guard, not contract state.
        $state = $contract->getState();
        if ($state->isAuthorized() || $state->isCommitted()) {
            return;
        }
        if ($state->isFulfilled() && $this->capturedAmount($contract) > 0.0) {
            return;
        }

        throw new DomainException(sprintf(
            'Cannot cancel contract "%s": authorization already captured or not in a cancellable state.',
            $contract->getId() ?? 'unknown',
        ));
    }

    private function requireProviderOrderId(PaymentContractInterface $contract): string
    {
        $providerOrderId = $contract->getProviderOrderId();
        if ($providerOrderId === null || $providerOrderId === '') {
            throw new DomainException(sprintf(
                'Cannot cancel contract "%s": no Mollie payment id on record.',
                $contract->getId() ?? 'unknown',
            ));
        }

        return $providerOrderId;
    }

    /**
     * Partial release: the captured money stays booked (the contract is FULFILLED for it), only
     * the uncaptured remainder of the hold goes back to the customer.
     */
    private function releaseRemainder(PaymentContractInterface $contract, ?string $reason): void
    {
        $remainder = max(0.0, round($contract->getAmount() - $this->capturedAmount($contract), 2));

        AuthorizationReleaseMarker::mark($contract, $remainder, $reason, new DateTimeImmutable());
        $this->contractRepository->save($contract);
        $this->auditRecorder->record(
            $contract,
            MollieDefinitions::TRANSACTION_TYPE_AUTHORIZATION_RELEASE,
            MollieDefinitions::TRANSACTION_STATUS_COMPLETED,
            $remainder,
        );
    }

    private function capturedAmount(PaymentContractInterface $contract): float
    {
        return $contract->getCapturedAmount() ?? 0.0;
    }

    private function mirrorToLinkedOrder(PaymentContractInterface $contract): void
    {
        $orderId = $contract->getOrderId();
        if ($orderId === null || $orderId === '') {
            return;
        }

        $this->orderUpdater->markCancelled($orderId);
    }
}
