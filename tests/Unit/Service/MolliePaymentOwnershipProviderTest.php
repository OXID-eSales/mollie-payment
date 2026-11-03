<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Service;

use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\Service\MolliePaymentOwnershipProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MolliePaymentOwnershipProvider::class)]
final class MolliePaymentOwnershipProviderTest extends TestCase
{
    public function testOwnsTheMolliePaymentRow(): void
    {
        $provider = new MolliePaymentOwnershipProvider();

        self::assertSame(MollieDefinitions::PROVIDER_NAME, $provider->providerName());
        self::assertSame([MollieDefinitions::PAYMENT_ID], $provider->paymentIds());
    }
}
