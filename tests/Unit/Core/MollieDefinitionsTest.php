<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Core;

use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\Module;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MollieDefinitions::class)]
final class MollieDefinitionsTest extends TestCase
{
    public function testPaymentId_IsStableConstant(): void
    {
        // The payment id must equal the module id and never be hardcoded elsewhere
        // (Stripe sprint 56b: a drifting payment id triggers ORDER_STATE_INVALIDPAYMENT).
        self::assertSame('oe_payments_mollie', MollieDefinitions::PAYMENT_ID);
        self::assertSame(Module::MODULE_ID, MollieDefinitions::MODULE_ID);
        self::assertSame(MollieDefinitions::MODULE_ID, MollieDefinitions::PAYMENT_ID);
    }

    public function testProviderName_IsMollie(): void
    {
        self::assertSame('mollie', MollieDefinitions::PROVIDER_NAME);
    }

    public function testIsMolliePayment_TrueOnlyForKnownId(): void
    {
        self::assertTrue(MollieDefinitions::isMolliePayment(MollieDefinitions::PAYMENT_ID));
        self::assertFalse(MollieDefinitions::isMolliePayment('oe_payments_paypal'));
    }

    public function testSupportsCurrency_DefaultEurAllowed_UnknownRejected(): void
    {
        self::assertTrue(MollieDefinitions::supportsCurrency(MollieDefinitions::PAYMENT_ID, 'eur'));
        self::assertFalse(MollieDefinitions::supportsCurrency(MollieDefinitions::PAYMENT_ID, 'JPY'));
    }

    public function testSupportsCountry_EmptyListMeansAll(): void
    {
        self::assertTrue(MollieDefinitions::supportsCountry(MollieDefinitions::PAYMENT_ID, 'DE'));
    }

    public function testModeAndCaptureConstants_AreExpectedValues(): void
    {
        self::assertSame('test', MollieDefinitions::MODE_TEST);
        self::assertSame('live', MollieDefinitions::MODE_LIVE);
        self::assertSame('automatic', MollieDefinitions::CAPTURE_MODE_AUTOMATIC);
        self::assertSame('manual', MollieDefinitions::CAPTURE_MODE_MANUAL);
    }
}
