<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Adapter\Dto;

use OxidEsales\Payments\Mollie\Adapter\Dto\MollieAmountDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieConfirmedFigures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MollieConfirmedFigures::class)]
final class MollieConfirmedFiguresTest extends TestCase
{
    public function testTakesMolliesAmountAndCurrency(): void
    {
        $figures = MollieConfirmedFigures::fromAmount(MollieAmountDto::fromComponents('eur', 12.5));

        self::assertEquals(new MollieConfirmedFigures(12.5, 'EUR'), $figures);
    }

    public function testAnAmountWithoutCurrencyIsNotUsable(): void
    {
        self::assertNull(MollieConfirmedFigures::fromAmount(MollieAmountDto::fromArray(['value' => '5.00'])));
    }

    public function testAnAmountOfZeroIsNotUsable(): void
    {
        self::assertNull(MollieConfirmedFigures::fromAmount(MollieAmountDto::fromComponents('EUR', 0.0)));
    }

    public function testReadsTheFiguresOfAWebhookEventObject(): void
    {
        $figures = MollieConfirmedFigures::fromEventObject(['amount' => 10.0, 'currency' => 'usd']);

        self::assertEquals(new MollieConfirmedFigures(10.0, 'USD'), $figures);
    }

    public function testAnEventObjectWithoutFiguresIsNotUsable(): void
    {
        self::assertNull(MollieConfirmedFigures::fromEventObject(['id' => 'tr_1']));
        self::assertNull(MollieConfirmedFigures::fromEventObject(['amount' => 10.0, 'currency' => '']));
        self::assertNull(MollieConfirmedFigures::fromEventObject(['amount' => 'ten', 'currency' => 'EUR']));
    }
}
