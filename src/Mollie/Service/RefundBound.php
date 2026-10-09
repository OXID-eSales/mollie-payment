<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\Payments\Mollie\Adapter\Dto\MolliePaymentDto;
use OxidEsales\Payments\Mollie\Adapter\MollieStatusMapper;

/**
 * How much of a payment may be refunded - the one rule the admin panel (what it offers) and
 * {@see RefundService} (what it accepts) share, so the form never offers what the service refuses.
 *
 * MOL-30 (2026-10-09): Mollie's live payment is the answer once Mollie has settled the capture
 * ({@see MolliePaymentDto::refundableAmount()}, which knows about Dashboard refunds and pending
 * ones), lowered to the shop's own record where the shop knows of more refunds. While the payment
 * still reads `authorized` - the seconds after "Execute capture" in which Mollie books the capture -
 * Mollie reports nothing settled although the shop has just captured; then the shop's record
 * (captured minus refunded) stands, exactly as the capture bound trusts it since 2026-10-01.
 * No live payment at all is fail-closed: nothing is offered.
 */
final class RefundBound
{
    public static function of(PaymentContractInterface $contract, ?MolliePaymentDto $payment): float
    {
        if ($payment === null) {
            return 0.0;
        }

        $local = self::localRemainder($contract);
        if ($payment->status === MollieStatusMapper::STATUS_AUTHORIZED) {
            return $local ?? 0.0;
        }

        $live = $payment->refundableAmount();

        return $local === null ? $live : max(0.0, min($live, $local));
    }

    /**
     * What the shop captured minus what it refunded, or null when the shop never recorded a
     * capture (a redirect-method payment before MOL-17, for instance) - then only Mollie knows.
     */
    private static function localRemainder(PaymentContractInterface $contract): ?float
    {
        $captured = $contract->getCapturedAmount();
        if ($captured === null) {
            return null;
        }

        return max(0.0, round($captured - ($contract->getRefundedAmount() ?? 0.0), 2));
    }
}
