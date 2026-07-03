<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Adapter\Dto;

/**
 * Filter parameters for {@see \OxidEsales\Payments\Mollie\Adapter\MollieMethodsAdapterInterface::listActiveMethods()}.
 */
final readonly class MethodsListRequest
{
    public function __construct(
        public ?string $billingCountry = null,
        public ?string $locale = null,
    ) {
    }
}
