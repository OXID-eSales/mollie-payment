<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

use OxidEsales\Eshop\Application\Model\Basket;
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
    /**
     * @param Basket|null $basket the basket being paid; null = the session basket (Twig / OPC).
     *        GRAPH-QL / MS1: the headless checkout has no session and passes the persisted basket.
     */
    public function billingAddress(?Basket $basket = null): ?MollieAddressDto;

    /**
     * @return list<MollieLineDto> reconciled so sum(totalAmount) == $expectedTotal
     */
    public function lines(string $currency, float $expectedTotal, ?Basket $basket = null): array;
}
