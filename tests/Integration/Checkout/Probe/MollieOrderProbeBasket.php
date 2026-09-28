<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Integration\Checkout\Probe;

/** Stands in for `oxcmp_basket`: one product. */
class MollieOrderProbeBasket
{
    public function getProductsCount(): int
    {
        return 1;
    }

    /** @param array<int, mixed> $args */
    public function __call(string $name, array $args): mixed
    {
        return null;
    }
}
