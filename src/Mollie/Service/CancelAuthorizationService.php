<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

use DomainException;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\Payments\Mollie\Adapter\MolliePaymentsAdapterInterface;

/**
 * Cancels an uncaptured Mollie two-step authorization.
 *
 * Idempotent: a contract already CANCELLED returns without touching the Mollie API (mirrors
 * PayPal's `CancelAuthorizationService`). A contract that has already moved past AUTHORIZED
 * (captured) is rejected — Mollie has no "cancel a capture" operation, only refund.
 */
final class CancelAuthorizationService implements CancelAuthorizationServiceInterface
{
    private const CANCEL_REASON = 'mollie_authorization_canceled';

    public function __construct(
        private readonly MolliePaymentsAdapterInterface $paymentsAdapter,
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly ContractLinkedOrderUpdaterInterface $orderUpdater,
    ) {
    }

    public function cancel(PaymentContractInterface $contract, ?string $reason = null): void
    {
        if ($contract->getState()->isCancelled()) {
            return;
        }

        $this->assertCancellable($contract);
        $providerOrderId = $this->requireProviderOrderId($contract);

        $this->paymentsAdapter->cancelPayment($providerOrderId);

        $contract->cancel($reason ?? self::CANCEL_REASON);
        $this->contractRepository->save($contract);
        $this->mirrorToLinkedOrder($contract);
    }

    private function assertCancellable(PaymentContractInterface $contract): void
    {
        if ($contract->getState()->isAuthorized()) {
            return;
        }

        throw new DomainException(sprintf(
            'Cannot cancel contract "%s": authorization already captured or not in an authorized state.',
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

    private function mirrorToLinkedOrder(PaymentContractInterface $contract): void
    {
        $orderId = $contract->getOrderId();
        if ($orderId === null || $orderId === '') {
            return;
        }

        $this->orderUpdater->markCancelled($orderId);
    }
}
