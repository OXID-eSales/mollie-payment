<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

use OxidEsales\Eshop\Application\Model\User;

/**
 * MOL-15: "may this shopper's address data go to Mollie?" — narrow seam so
 * {@see CheckoutSessionStarter} can be unit-tested ({@see CheckoutUserDataGate} is final).
 */
interface CheckoutUserDataGateInterface
{
    /**
     * @return list<string> translated per-field problem messages; empty = the data may go out
     */
    public function problemsFor(?User $user): array;
}
