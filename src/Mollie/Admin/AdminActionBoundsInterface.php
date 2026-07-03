<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Admin;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;

/**
 * Narrow seam exposing the maximum amount an admin action may move, sourced from the live Mollie
 * payment (never from locally-tracked columns) — the same values the panel displays and the
 * {@see \OxidEsales\Payments\Mollie\Admin\AdminAmountValidator} validates against.
 */
interface AdminActionBoundsInterface
{
    /** Maximum capturable amount in major units (0.0 when nothing is capturable). */
    public function captureBound(PaymentContractInterface $contract): float;

    /** Maximum refundable amount in major units (0.0 when nothing is refundable). */
    public function refundBound(PaymentContractInterface $contract): float;
}
