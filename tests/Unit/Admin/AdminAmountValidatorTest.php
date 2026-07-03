<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Admin;

use OxidEsales\Payments\Mollie\Admin\AdminAmountValidator;
use OxidEsales\Payments\Mollie\Admin\AmountValidationResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AdminAmountValidator::class)]
#[CoversClass(AmountValidationResult::class)]
final class AdminAmountValidatorTest extends TestCase
{
    private AdminAmountValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new AdminAmountValidator();
    }

    public function testAbsentAmount_IsOkWithNullAmount(): void
    {
        $result = $this->validator->validate(null, 100.0);

        self::assertTrue($result->isOk());
        self::assertNull($result->amount);
    }

    public function testEmptyStringAmount_IsOkWithNullAmount(): void
    {
        $result = $this->validator->validate('', 100.0);

        self::assertTrue($result->isOk());
        self::assertNull($result->amount);
    }

    public function testValidPartialAmount_IsOkWithParsedFloat(): void
    {
        $result = $this->validator->validate('42.50', 100.0);

        self::assertTrue($result->isOk());
        self::assertSame(42.50, $result->amount);
    }

    public function testCommaDecimalSeparator_IsAccepted(): void
    {
        $result = $this->validator->validate('12,50', 100.0);

        self::assertTrue($result->isOk());
        self::assertSame(12.50, $result->amount);
    }

    public function testRefund_ExceedingRefundable_Fails(): void
    {
        $result = $this->validator->validate('150.00', 100.0);

        self::assertFalse($result->isOk());
        self::assertSame(AmountValidationResult::CODE_EXCEEDS_BOUND, $result->code);
    }

    public function testCapture_ExceedingAuthorized_Fails(): void
    {
        $result = $this->validator->validate('30.01', 30.0);

        self::assertFalse($result->isOk());
        self::assertSame(AmountValidationResult::CODE_EXCEEDS_BOUND, $result->code);
    }

    public function testAmount_NonNumericOrNegative_Fails(): void
    {
        $nonNumeric = $this->validator->validate('abc', 100.0);
        $negative = $this->validator->validate('-5', 100.0);
        $zero = $this->validator->validate('0', 100.0);

        self::assertFalse($nonNumeric->isOk());
        self::assertSame(AmountValidationResult::CODE_MALFORMED, $nonNumeric->code);

        self::assertFalse($negative->isOk());
        self::assertSame(AmountValidationResult::CODE_NOT_POSITIVE, $negative->code);

        self::assertFalse($zero->isOk());
        self::assertSame(AmountValidationResult::CODE_NOT_POSITIVE, $zero->code);
    }

    public function testAmount_WithTooManyDecimals_FailsPrecision(): void
    {
        $result = $this->validator->validate('10.999', 100.0);

        self::assertFalse($result->isOk());
        self::assertSame(AmountValidationResult::CODE_PRECISION, $result->code);
    }

    public function testAmount_ExactlyAtBound_IsOk(): void
    {
        $result = $this->validator->validate('100.00', 100.0);

        self::assertTrue($result->isOk());
        self::assertSame(100.0, $result->amount);
    }
}
