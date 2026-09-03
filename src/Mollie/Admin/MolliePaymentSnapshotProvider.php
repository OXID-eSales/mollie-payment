<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Admin;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\Payments\Mollie\Adapter\Dto\MolliePaymentDto;
use OxidEsales\Payments\Mollie\Adapter\MolliePaymentsAdapterInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Per-request memoized read of the live Mollie payment, keyed by provider order id.
 *
 * Sprint 136: extracted from {@see AdminActionBounds::loadPayment()}, which made
 * one HTTP call per question asked (three per render, four once the payment-method
 * row arrived). The container shares this service for the request, so all
 * consumers of one payment share one round trip.
 *
 * A failed read is cached too, deliberately: retrying a PSP that is already down
 * once per consumer buys nothing and emits one identical warning per attempt for
 * a single incident.
 */
final class MolliePaymentSnapshotProvider implements MolliePaymentSnapshotProviderInterface
{
    /**
     * Provider order id → payment, or null for "read attempted and failed".
     *
     * @var array<string, MolliePaymentDto|null>
     */
    private array $snapshots = [];

    public function __construct(
        private readonly MolliePaymentsAdapterInterface $paymentsAdapter,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function snapshot(PaymentContractInterface $contract): ?MolliePaymentDto
    {
        $providerOrderId = $contract->getProviderOrderId();

        // No provider order id is an ordinary early-lifecycle state, not an
        // incident: nothing to read, nothing to log, nothing to cache.
        if ($providerOrderId === null || $providerOrderId === '') {
            return null;
        }

        if (array_key_exists($providerOrderId, $this->snapshots)) {
            return $this->snapshots[$providerOrderId];
        }

        return $this->snapshots[$providerOrderId] = $this->read($contract, $providerOrderId);
    }

    private function read(PaymentContractInterface $contract, string $providerOrderId): ?MolliePaymentDto
    {
        try {
            return $this->paymentsAdapter->getPayment($providerOrderId);
        } catch (Throwable $e) {
            // Failing closed to a 0.00 bound is the right direction, but the
            // panel then renders "0.00 refundable" for both "already fully
            // refunded" and "Mollie is unreachable" — and an operator can
            // reasonably read the first from the second and stop investigating.
            // At minimum it must be in the log. (Sprint 11 Story 8 / F12.)
            $this->logger->warning(
                '[MolliePaymentSnapshotProvider] could not load the Mollie payment; capture/refund '
                . 'bounds will read as 0.00 and the payment method as unknown',
                [
                    'contractId' => $contract->getId(),
                    'providerOrderId' => $providerOrderId,
                    'error' => $e->getMessage(),
                ],
            );

            return null;
        }
    }
}
