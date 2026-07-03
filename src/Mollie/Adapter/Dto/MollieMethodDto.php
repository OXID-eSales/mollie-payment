<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Adapter\Dto;

/**
 * Immutable snapshot of one Mollie payment method (iDEAL, credit card, …) as returned by the
 * Methods API. No Mollie SDK type crosses this boundary.
 */
final readonly class MollieMethodDto
{
    public function __construct(
        public string $id,
        public string $description,
        public ?string $imageUrl = null,
    ) {
    }
}
