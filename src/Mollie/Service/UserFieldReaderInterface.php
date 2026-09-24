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

    /**
     * MOL-15: whether the shopper selected a separate delivery address. When they did, every
     * logical field is validated a second time against that address - it is sent to the PSP too.
     */
    public function hasDeliveryAddress(): bool;

    /**
     * The delivery-address value of a logical field, '' when none is selected or the field is unknown.
     */
    public function readDeliveryField(string $logicalName): string;
}
