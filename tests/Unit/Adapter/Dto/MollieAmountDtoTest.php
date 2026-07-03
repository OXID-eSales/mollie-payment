<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Adapter\Dto;

use OxidEsales\Payments\Mollie\Adapter\Dto\MollieAmountDto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MollieAmountDto::class)]
final class MollieAmountDtoTest extends TestCase
{
    public function testToMollieArray_FormatsValueAsTwoDecimalString(): void
    {
        $amount = new MollieAmountDto('EUR', 10.0);
        self::assertSame(['currency' => 'EUR', 'value' => '10.00'], $amount->toMollieArray());

        $rounded = new MollieAmountDto('EUR', 9.5);
        self::assertSame('9.50', $rounded->toMollieArray()['value']);
    }

    public function testFromComponents_UppercasesCurrency(): void
    {
        $amount = MollieAmountDto::fromComponents('eur', 5.25);
        self::assertSame('EUR', $amount->currency);
        self::assertSame(5.25, $amount->value);
    }

    public function testFromArray_ReadsStringValue(): void
    {
        $amount = MollieAmountDto::fromArray(['currency' => 'usd', 'value' => '12.99']);
        self::assertSame('USD', $amount->currency);
        self::assertSame(12.99, $amount->value);
        self::assertSame('12.99', $amount->formatValue());
    }
}
