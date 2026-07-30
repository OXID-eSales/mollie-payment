<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

use OxidEsales\Payments\Mollie\Adapter\Dto\MollieAddressDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieLineDto;

/**
 * Reads the billing address + reconciled order lines from the live session basket/user, for Mollie's
 * pay-later methods (Klarna, Riverty, …). The early-created order is only a NOT_FINISHED shell at
 * create-payment time (its address fields are filled on finalize), so the session is the reliable
 * source. Returns null / [] when the basket/user is unavailable, so callers can skip a method rather
 * than 422 the shopper.
 */
interface MollieOrderDataProviderInterface
{
    public function billingAddress(): ?MollieAddressDto;

    /**
     * @return list<MollieLineDto> reconciled so sum(totalAmount) == $expectedTotal
     */
    public function lines(string $currency, float $expectedTotal): array;
}
