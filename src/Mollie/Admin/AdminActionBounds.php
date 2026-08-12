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
use OxidEsales\Payments\Mollie\Adapter\MollieStatusMapper;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Derives admin capture/refund bounds from the live Mollie payment via
 * {@see MolliePaymentDto::capturableAmount()}/{@see MolliePaymentDto::refundableAmount()} — the
 * exact same formulas Sprint 6's `RefundService`/`CaptureService` use to size the actual PSP call.
 * Kept as a small, independently-injectable seam (rather than reusing those services' private
 * methods) so the admin panel's read-only display path never depends on the write-side services.
 */
final class AdminActionBounds implements AdminActionBoundsInterface
{
    public function __construct(
        private readonly MolliePaymentsAdapterInterface $paymentsAdapter,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function captureBound(PaymentContractInterface $contract): float
    {
        return $this->loadPayment($contract)?->capturableAmount() ?? 0.0;
    }

    public function refundBound(PaymentContractInterface $contract): float
    {
        return $this->loadPayment($contract)?->refundableAmount() ?? 0.0;
    }

    public function isAuthorizedHold(PaymentContractInterface $contract): bool
    {
        return $this->loadPayment($contract)?->status === MollieStatusMapper::STATUS_AUTHORIZED;
    }

    private function loadPayment(PaymentContractInterface $contract): ?MolliePaymentDto
    {
        $providerOrderId = $contract->getProviderOrderId();
        if ($providerOrderId === null || $providerOrderId === '') {
            return null;
        }

        try {
            return $this->paymentsAdapter->getPayment($providerOrderId);
        } catch (Throwable $e) {
            // Sprint 11 Story 8 (F12): failing closed to a 0.00 bound is the right direction, but
            // the admin panel then renders "0.00 refundable" for both "already fully refunded" and
            // "Mollie is unreachable" — and an operator can reasonably read the first from the
            // second and stop investigating. At minimum it must be in the log.
            $this->logger->warning(
                '[AdminActionBounds] could not load the Mollie payment; capture/refund bounds will '
                . 'read as 0.00',
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
