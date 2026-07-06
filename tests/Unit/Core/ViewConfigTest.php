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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ViewConfig::class)]
final class ViewConfigTest extends TestCase
{
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
