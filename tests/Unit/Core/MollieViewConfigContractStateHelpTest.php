<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Core;

use OxidEsales\Payments\Mollie\Admin\ContractStateHelp;
use OxidEsales\Payments\Mollie\Core\ViewConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * MOL-10 — admin templates see the shop's oViewConf, so the module settings page takes the Help
 * rows from the ViewConfig extension; it must hand out exactly the one PHP table.
 */
#[CoversClass(ViewConfig::class)]
final class MollieViewConfigContractStateHelpTest extends TestCase
{
    public function testHandsOutTheContractStateHelpRows(): void
    {
        $rows = (new TestableMollieViewConfig())->getMollieContractStateHelp();

        self::assertEquals((new ContractStateHelp())->rows(), $rows);
        self::assertCount(8, $rows);
    }
}
