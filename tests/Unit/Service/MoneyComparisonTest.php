<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Service;

use OxidEsales\Payments\Mollie\Service\MoneyComparison;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Review 2026-10-14 A-M3 — the capture/refund bound tolerance, now in ONE place. The pin that
 * matters: representation noise passes, a genuine cent does not.
 */
#[CoversClass(MoneyComparison::class)]
final class MoneyComparisonTest extends TestCase
{
    public function testFloatRepresentationNoiseDoesNotCountAsExceeding(): void
    {
        self::assertFalse(MoneyComparison::exceeds(0.1 + 0.2, 0.3), 'binary float noise is not an overrun');
        self::assertFalse(MoneyComparison::exceeds(100.00, 100.00));
    }

    public function testAGenuineCentOverrunIsCaught(): void
    {
        self::assertTrue(MoneyComparison::exceeds(100.01, 100.00), 'one real cent over the bound must be refused');
        self::assertTrue(MoneyComparison::exceeds(0.31, 0.30));
    }

    public function testTheToleranceStaysWellBelowHalfACent(): void
    {
        self::assertLessThan(0.005, MoneyComparison::EPSILON);
    }
}
