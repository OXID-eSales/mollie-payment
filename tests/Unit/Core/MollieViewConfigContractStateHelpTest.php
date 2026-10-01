<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Core;

use OxidEsales\Payments\Mollie\Admin\MollieContractStateHelp;
use OxidEsales\Payments\Mollie\Core\ViewConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * MOL-10 — admin templates see the shop's oViewConf, so the Settings tab and the order panel take
 * Mollie's column of the shared Help from the ViewConfig extension.
 */
#[CoversClass(ViewConfig::class)]
final class MollieViewConfigContractStateHelpTest extends TestCase
{
    public function testHandsOutMolliesColumnOfTheSharedHelp(): void
    {
        $help = (new TestableMollieViewConfig())->getMollieContractStateHelp();

        self::assertInstanceOf(MollieContractStateHelp::class, $help);
        self::assertCount(8, $help->providerStatuses());
    }
}
