<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Integration\Checkout\Probe;

/** Stands in for the selected payment (`oView.getPayment()`): always Mollie. */
class MollieOrderProbePayment
{
    public function getId(): string
    {
        return 'oe_payments_mollie';
    }

    public function isStripePaymentMethod(): bool
    {
        return false;
    }

    /** @param array<int, mixed> $args */
    public function __call(string $name, array $args): mixed
    {
        return null;
    }
}
