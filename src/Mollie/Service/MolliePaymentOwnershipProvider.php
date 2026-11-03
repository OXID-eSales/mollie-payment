<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\PaymentBase\GraphQL\Admin\Contract\PaymentOwnershipProviderInterface;

/**
 * Tells payment-base's admin API which payment row is Mollie's, so that the API locks it (G09).
 */
final class MolliePaymentOwnershipProvider implements PaymentOwnershipProviderInterface
{
    public function providerName(): string
    {
        return MollieDefinitions::PROVIDER_NAME;
    }

    public function paymentIds(): array
    {
        return [MollieDefinitions::PAYMENT_ID];
    }
}
