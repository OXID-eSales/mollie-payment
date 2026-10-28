<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Adapter;

use OxidEsales\Payments\Mollie\Adapter\Exception\MollieAdapterException;
use OxidEsales\Payments\Mollie\Adapter\Exception\MollieConfigurationException;

/**
 * Asks Mollie whether the configured API key is accepted (ISP slice 6, deliberately its own: it is not part of
 * a payment flow, so it does not belong to the four payment slices).
 */
interface MollieConnectionAdapterInterface
{
    /**
     * Reads the profile of the configured API key and returns nothing: only the verdict matters. A refused key,
     * a malformed or missing key and a Mollie that cannot be reached each end in an exception that carries
     * the HTTP status where there is one (never the key).
     *
     * @throws MollieConfigurationException the key is missing or malformed
     * @throws MollieAdapterException       Mollie refused or did not answer
     */
    public function verifyConnection(): void;
}
