<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;

/**
 * Cancels an uncaptured Mollie two-step authorization.
 */
interface CancelAuthorizationServiceInterface
{
    /**
     * @throws \DomainException if the contract has already been captured, or has no Mollie
     *                           payment id on record
     */
    public function cancel(PaymentContractInterface $contract, ?string $reason = null): void;
}
