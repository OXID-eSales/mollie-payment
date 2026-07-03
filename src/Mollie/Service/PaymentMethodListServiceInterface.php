<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

use OxidEsales\Payments\Mollie\Adapter\Dto\MollieMethodDto;

interface PaymentMethodListServiceInterface
{
    /**
     * Enabled Mollie methods (iDEAL, credit card, …) for the storefront selector, filtered by
     * the shop's supported currencies ({@see \OxidEsales\Payments\Mollie\Core\MollieDefinitions})
     * and — via the live Mollie API — the customer's billing country.
     *
     * @return list<MollieMethodDto>
     */
    public function listActiveMethods(string $currency, ?string $country = null): array;
}
