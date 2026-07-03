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
    public function __construct(private readonly MolliePaymentsAdapterInterface $paymentsAdapter)
    {
    }

    public function captureBound(PaymentContractInterface $contract): float
    {
        return $this->loadPayment($contract)?->capturableAmount() ?? 0.0;
    }

    public function refundBound(PaymentContractInterface $contract): float
    {
        return $this->loadPayment($contract)?->refundableAmount() ?? 0.0;
    }

    private function loadPayment(PaymentContractInterface $contract): ?MolliePaymentDto
    {
        $providerOrderId = $contract->getProviderOrderId();
        if ($providerOrderId === null || $providerOrderId === '') {
            return null;
        }

        try {
            return $this->paymentsAdapter->getPayment($providerOrderId);
        } catch (Throwable) {
            return null;
        }
    }
}
