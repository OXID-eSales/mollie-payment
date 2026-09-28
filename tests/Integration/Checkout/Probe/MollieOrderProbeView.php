<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Integration\Checkout\Probe;

/** Stands in for the order controller (`oView`): Mollie selected, regular basket total. */
class MollieOrderProbeView
{
    public function getPayment(): MollieOrderProbePayment
    {
        return new MollieOrderProbePayment();
    }

    public function isLowOrderPrice(): bool
    {
        return false;
    }

    public function isSingleShippingAutoAssigned(): bool
    {
        return true;
    }

    public function isSinglePaymentAutoAssigned(): bool
    {
        return true;
    }

    /** @param array<int, mixed> $args */
    public function __call(string $name, array $args): mixed
    {
        return null;
    }
}
