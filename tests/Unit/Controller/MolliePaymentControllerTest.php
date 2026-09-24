<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Controller;

use OxidEsales\Payments\Mollie\Controller\PaymentController;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\Tests\Unit\Support\PreloadsModuleClassChain;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaymentController::class)]
final class MolliePaymentControllerTest extends TestCase
{
    use PreloadsModuleClassChain;

    // TestableMolliePaymentController extends the concrete Mollie PaymentController chain member —
    // build the chain first so instantiating it does not re-enter ModuleChainsGenerator (see trait).
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::preloadModuleClassChain('payment');
    }

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
            userDataProblems: ['The street field is not valid. Allowed symbols are: letters'],
            delegateResult: 'order',
        );

        self::assertSame('order', $controller->validatePayment());
        self::assertSame([], $controller->userDataProblemsShown);
    }

    public function testValidatePaymentWhenMollieSelectedAndUserDataValidReturnsParentResult(): void
    {
        $controller = new TestableMolliePaymentController(
            MollieDefinitions::PAYMENT_ID,
            userDataProblems: [],
            delegateResult: 'order',
        );

        self::assertSame('order', $controller->validatePayment());
        self::assertSame([], $controller->userDataProblemsShown);
    }

    public function testValidatePaymentWhenMollieSelectedAndUserDataInvalidBlocksProgression(): void
    {
        $controller = new TestableMolliePaymentController(
            MollieDefinitions::PAYMENT_ID,
            userDataProblems: ['The street field is not valid. Allowed symbols are: letters'],
            delegateResult: 'order',
        );

        self::assertSame('payment', $controller->validatePayment());
        self::assertSame(
            ['The street field is not valid. Allowed symbols are: letters'],
            $controller->userDataProblemsShown,
        );
    }

    public function testValidatePaymentWhenParentRejectsAndMollieSelectedWithValidDataReturnsParentResult(): void
    {
        $controller = new TestableMolliePaymentController(
            MollieDefinitions::PAYMENT_ID,
            userDataProblems: [],
            delegateResult: null,
        );

        self::assertNull($controller->validatePayment());
    }
}
