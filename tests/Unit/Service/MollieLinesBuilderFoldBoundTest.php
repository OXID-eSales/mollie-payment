<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Service;

use LogicException;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieProductLineInput;
use OxidEsales\Payments\Mollie\Service\MollieLinesBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 11 Story 10 (F14) — the residual fold absorbs rounding, not missing order data.
 */
#[CoversClass(MollieLinesBuilder::class)]
#[Group('F14')]
final class MollieLinesBuilderFoldBoundTest extends TestCase
{
    public function testEmptyBasketAgainstARealTotalIsRefusedInsteadOfFolded(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/too large to be rounding/');

        // MollieOrderDataProvider::lines() returns [] when the session basket is unavailable. Folding
        // the whole order into one "Rounding adjustment €249.00" line satisfies Mollie's invariant and
        // ships an unitemised Klarna invoice to the shopper.
        MollieLinesBuilder::build('EUR', 249.00, [], 0.0, 0.0, 0.0);
    }

    public function testPartialBasketIsAlsoRefused(): void
    {
        $this->expectException(LogicException::class);

        MollieLinesBuilder::build(
            'EUR',
            249.00,
            [new MollieProductLineInput('One item of many', 1, 49.00, 19.0)],
            0.0,
            0.0,
            0.0,
        );
    }

    public function testGenuineRoundingResidualStillFoldsSilently(): void
    {
        $lines = MollieLinesBuilder::build(
            'EUR',
            100.02,
            [new MollieProductLineInput('Item', 3, 33.3333, 19.0)],
            0.0,
            0.0,
            0.0,
        );

        $total = 0.0;
        foreach ($lines as $line) {
            $total += $line->totalAmount->value;
        }

        self::assertSame(100.02, round($total, 2), 'the cent-exact invariant must still hold');
    }

    public function testRelativeCeilingScalesWithLargeOrders(): void
    {
        // 1% of 10 000.00 = 100.00, so a 40.00 discount residual is still within the fold's remit.
        $lines = MollieLinesBuilder::build(
            'EUR',
            9960.00,
            [new MollieProductLineInput('Bulk', 1, 10000.00, 19.0)],
            0.0,
            0.0,
            0.0,
        );

        self::assertNotSame([], $lines);
    }

    public function testExactMatchNeedsNoAdjustmentLine(): void
    {
        $lines = MollieLinesBuilder::build(
            'EUR',
            50.00,
            [new MollieProductLineInput('Item', 2, 25.00, 19.0)],
            0.0,
            0.0,
            0.0,
        );

        self::assertCount(1, $lines);
        self::assertSame('Item', $lines[0]->description);
    }
}
