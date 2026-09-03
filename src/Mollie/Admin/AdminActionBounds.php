<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Admin;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\Payments\Mollie\Adapter\Dto\MolliePaymentDto;
use OxidEsales\Payments\Mollie\Adapter\MollieStatusMapper;

/**
 * Derives admin capture/refund bounds from the live Mollie payment via
 * {@see MolliePaymentDto::capturableAmount()}/{@see MolliePaymentDto::refundableAmount()} — the
 * exact same formulas Sprint 6's `RefundService`/`CaptureService` use to size the actual PSP call.
 * Kept as a small, independently-injectable seam (rather than reusing those services' private
 * methods) so the admin panel's read-only display path never depends on the write-side services.
 *
 * Sprint 136: the payment read itself moved to
 * {@see MolliePaymentSnapshotProviderInterface}, so the three questions below —
 * plus the new payment-method row — share one HTTP round trip instead of taking
 * one each. Behaviour, including the fail-closed 0.00 bounds and the warning
 * that explains them, is unchanged.
 */
final class AdminActionBounds implements AdminActionBoundsInterface
{
    public function __construct(
        private readonly MolliePaymentSnapshotProviderInterface $snapshots,
    ) {
    }

    public function captureBound(PaymentContractInterface $contract): float
    {
        return $this->snapshots->snapshot($contract)?->capturableAmount() ?? 0.0;
    }

    public function refundBound(PaymentContractInterface $contract): float
    {
        return $this->snapshots->snapshot($contract)?->refundableAmount() ?? 0.0;
    }

    public function isAuthorizedHold(PaymentContractInterface $contract): bool
    {
        return $this->snapshots->snapshot($contract)?->status === MollieStatusMapper::STATUS_AUTHORIZED;
    }
}
