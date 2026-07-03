<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Adapter;

use OxidEsales\Payments\Mollie\Adapter\OxidShopAdapter;
use OxidEsales\Payments\Mollie\Service\ModuleConfigurationServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OxidShopAdapter::class)]
final class OxidShopAdapterTest extends TestCase
{
    public function testGetAdapterName_IsOxidMollie(): void
    {
        $adapter = new OxidShopAdapter($this->createMock(ModuleConfigurationServiceInterface::class));
        self::assertSame('oxid-mollie', $adapter->getAdapterName());
    }

    public function testIsTestMode_ReflectsModuleMode(): void
    {
        $config = $this->createMock(ModuleConfigurationServiceInterface::class);
        $config->method('isTestMode')->willReturn(true);
        self::assertTrue((new OxidShopAdapter($config))->isTestMode());

        $liveConfig = $this->createMock(ModuleConfigurationServiceInterface::class);
        $liveConfig->method('isTestMode')->willReturn(false);
        self::assertFalse((new OxidShopAdapter($liveConfig))->isTestMode());
    }
}
