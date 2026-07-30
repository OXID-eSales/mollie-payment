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
 * Reads the billing address + reconciled order lines from the early-created OXID order, for Mollie's
 * pay-later methods (Klarna, Riverty, …). Returns null / [] when the order is missing or its address
 * is incomplete, so callers can skip a method rather than 422 the shopper.
 */
interface MollieOrderDataProviderInterface
{
    public function billingAddress(string $orderId): ?MollieAddressDto;

    /**
     * @return list<MollieLineDto> reconciled so sum(totalAmount) == $expectedTotal
     */
    public function lines(string $orderId, string $currency, float $expectedTotal): array;
}
