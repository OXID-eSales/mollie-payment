<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Admin;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\Payments\Mollie\Adapter\Dto\MolliePaymentDto;

/**
 * The one live Mollie payment read the admin panel makes per render.
 *
 * Sprint 136: the panel asks four questions of the same payment — capture
 * bound, refund bound, is-this-still-an-authorized-hold, and which method the
 * customer paid with. Each was previously its own HTTP round trip. This seam
 * owns the read (and its failure handling) exactly once; every consumer reads
 * the cached snapshot.
 *
 * Null means "no answer available": the contract has no provider order id yet,
 * or Mollie could not be reached. Callers must fail closed on null — never
 * treat it as "nothing to capture/refund" without saying so.
 */
interface MolliePaymentSnapshotProviderInterface
{
    public function snapshot(PaymentContractInterface $contract): ?MolliePaymentDto;

    /**
     * Forget every memoized payment so the next {@see snapshot()} reads Mollie again.
     *
     * An admin action request reads the payment once to validate the amount, performs the
     * capture/refund/cancel, then re-renders the panel in the same request. Without this call the
     * re-render would serve the pre-action memo — the panel showed the old refundable bound until
     * the operator reloaded (2026-09-17).
     */
    public function reset(): void;
}
