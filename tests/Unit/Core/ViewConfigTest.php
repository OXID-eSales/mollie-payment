<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Core;

use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\Core\ViewConfig;
use OxidEsales\Payments\Mollie\Service\ModuleConfigurationServiceInterface;
use OxidEsales\Payments\Mollie\Tests\Unit\Support\PreloadsModuleClassChain;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ViewConfig::class)]
final class ViewConfigTest extends TestCase
{
    use PreloadsModuleClassChain;

    // TestableMollieViewConfig extends the concrete Mollie ViewConfig chain member — build the
    // ViewConfig chain first so instantiating it does not re-enter ModuleChainsGenerator (see trait).
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::preloadModuleClassChain('oxviewconfig');
    }

    public function testGetMollieModuleId_ReturnsTheModuleId(): void
    {
        $viewConfig = new TestableMollieViewConfig();

        self::assertSame(MollieDefinitions::MODULE_ID, $viewConfig->getMollieModuleId());
    }

    public function testIsMollieDebugLoggingEnabled_TrueOnlyWhenConfigReportsDebugLevel(): void
    {
        $debugConfig = $this->createMock(ModuleConfigurationServiceInterface::class);
        $debugConfig->method('isFrontendDebugEnabled')->willReturn(true);
        $debugViewConfig = new TestableMollieViewConfig($debugConfig);
        self::assertTrue($debugViewConfig->isMollieDebugLoggingEnabled());

        $quietConfig = $this->createMock(ModuleConfigurationServiceInterface::class);
        $quietConfig->method('isFrontendDebugEnabled')->willReturn(false);
        $quietViewConfig = new TestableMollieViewConfig($quietConfig);
        self::assertFalse($quietViewConfig->isMollieDebugLoggingEnabled());
    }

    public function testIsMollieDebugLoggingEnabled_FalseWhenConfigServiceUnavailable(): void
    {
        $viewConfig = new TestableMollieViewConfig(null);

        self::assertFalse($viewConfig->isMollieDebugLoggingEnabled());
    }
}
