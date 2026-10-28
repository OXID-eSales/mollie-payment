<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\GraphQL\Admin\Service;

interface MolliePaymentDetailsReaderInterface
{
    /**
     * The Mollie facts of a contract, with one live read of the payment; null when the contract is not found or is
     * not a Mollie contract. Never throws for a Mollie that cannot be read: that is `apiError`.
     */
    public function forContract(string $contractId): ?MolliePaymentDetails;
}
