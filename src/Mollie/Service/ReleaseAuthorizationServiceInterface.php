<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;

/**
 * MOL-32 S6 — releases the remaining authorized amount of a (partially) captured Mollie payment
 * via Mollie's `release-authorization`, keeping whatever was already captured.
 */
interface ReleaseAuthorizationServiceInterface
{
    public function release(PaymentContractInterface $contract, ?string $reason = null): void;
}
