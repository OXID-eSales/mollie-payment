<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

/**
 * Narrow seam for reading user/address field values by logical name.
 *
 * Decouples {@see UserDataValidator} from the concrete OXID User model so both can be tested
 * without booting the OXID shop. Logical field names match the data Mollie collects/sends:
 * firstName, lastName, street, houseNumber, zip, city, company, vatId, additionalInfo, phone,
 * email.
 */
interface UserFieldReaderInterface
{
    /**
     * Returns the billing-address value for the given logical field name.
     * Returns an empty string when the field has no value or the logical name is unknown.
     */
    public function readBillingField(string $logicalName): string;
}
