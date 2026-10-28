<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Admin;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;

/**
 * MOL-32: when the still-authorized remainder of a (partially) captured Mollie payment may be released, and what a
 * release gave back. One rule for the classic Payment tab (what it offers), the release action (what it accepts) and
 * the admin API (what it shows), so none of them can offer what another refuses.
 */
final class ReleaseRule
{
    public function __construct(private readonly AdminActionBoundsInterface $bounds)
    {
    }

    /**
     * Release needs a live authorized hold with something left to capture, and something captured already: with
     * nothing captured, "release" would be a cancellation of the whole hold, which is Cancel's business.
     */
    public function isReleasable(PaymentContractInterface $contract): bool
    {
        return $this->bounds->isAuthorizedHold($contract)
            && $this->bounds->captureBound($contract) > 0.0
            && $this->captured($contract) > 0.0;
    }

    /**
     * What a release would give back right now: the whole remainder (Mollie has no partial release); 0 when it
     * may not be released.
     */
    public function releasableAmount(PaymentContractInterface $contract): float
    {
        return $this->isReleasable($contract) ? $this->bounds->captureBound($contract) : 0.0;
    }

    /**
     * What went back to the customer: after a release the order is fulfilled with less than the authorized amount.
     */
    public function releasedAmount(PaymentContractInterface $contract): float
    {
        return round(max(0.0, $contract->getAmount() - $this->captured($contract)), 2);
    }

    public function isReleased(PaymentContractInterface $contract): bool
    {
        return $contract->getState()->isFulfilled()
            && $this->captured($contract) > 0.0
            && $this->releasedAmount($contract) > 0.0;
    }

    private function captured(PaymentContractInterface $contract): float
    {
        return $contract->getCapturedAmount() ?? 0.0;
    }
}
