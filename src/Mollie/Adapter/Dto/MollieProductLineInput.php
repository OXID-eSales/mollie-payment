<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Adapter\Dto;

/**
 * Plain input for one basket/order product line, fed to {@see \OxidEsales\Payments\Mollie\Service\MollieLinesBuilder}.
 * Gross (VAT-inclusive) unit price + the line's VAT rate; the builder derives Mollie's per-line amounts.
 */
final readonly class MollieProductLineInput
{
    public function __construct(
        public string $description,
        public int $quantity,
        public float $unitPriceGross,
        public float $vatRatePercent,
    ) {
    }
}
