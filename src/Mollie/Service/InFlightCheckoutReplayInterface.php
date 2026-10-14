<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

/**
 * MOL-18: "is an attempt for this basket already in flight?" — narrow seam so
 * {@see CheckoutSessionStarter} can be unit-tested ({@see InFlightCheckoutReplay} is final).
 */
interface InFlightCheckoutReplayInterface
{
    /**
     * @return string|null the Mollie checkout URL of the attempt already in flight, or null when a
     *                     new attempt is due
     */
    public function checkoutUrl(): ?string;
}
