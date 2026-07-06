<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Controller;

use OxidEsales\Payments\Mollie\Controller\PaymentController;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaymentController::class)]
final class MolliePaymentControllerTest extends TestCase
{
    public function testValidatePaymentAlwaysDelegatesToTheParentFirst(): void
    {
        $controller = new TestableMolliePaymentController('oxidcashondel', delegateResult: 'order');

        $controller->validatePayment();

        self::assertTrue($controller->delegatedValidatePayment);
    }

    public function testValidatePaymentWhenNonMollieMethodSkipsUserDataCheckAndReturnsParentResult(): void
    {
        $controller = new TestableMolliePaymentController(
            'oxidcashondel',
            userDataValid: false,
            delegateResult: 'order',
        );

        self::assertSame('order', $controller->validatePayment());
        self::assertFalse($controller->invalidUserDataErrorShown);
    }

    public function testValidatePaymentWhenMollieSelectedAndUserDataValidReturnsParentResult(): void
    {
        $controller = new TestableMolliePaymentController(
            MollieDefinitions::PAYMENT_ID,
            userDataValid: true,
            delegateResult: 'order',
        );

        self::assertSame('order', $controller->validatePayment());
        self::assertFalse($controller->invalidUserDataErrorShown);
    }

    public function testValidatePaymentWhenMollieSelectedAndUserDataInvalidBlocksProgression(): void
    {
        $controller = new TestableMolliePaymentController(
            MollieDefinitions::PAYMENT_ID,
            userDataValid: false,
            delegateResult: 'order',
        );

        self::assertSame('payment', $controller->validatePayment());
        self::assertTrue($controller->invalidUserDataErrorShown);
    }

    public function testValidatePaymentWhenParentRejectsAndMollieSelectedWithValidDataReturnsParentResult(): void
    {
        $controller = new TestableMolliePaymentController(
            MollieDefinitions::PAYMENT_ID,
            userDataValid: true,
            delegateResult: null,
        );

        self::assertNull($controller->validatePayment());
    }
}
