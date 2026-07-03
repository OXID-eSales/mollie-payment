<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Core;

use OxidEsales\Payments\Mollie\Adapter\Dto\MollieMethodDto;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\Core\ViewConfig;
use OxidEsales\Payments\Mollie\Service\ModuleConfigurationServiceInterface;
use OxidEsales\Payments\Mollie\Service\PaymentMethodListServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(ViewConfig::class)]
final class ViewConfigTest extends TestCase
{
    public function testGetMollieModuleId_ReturnsTheModuleId(): void
    {
        $service = $this->createMock(PaymentMethodListServiceInterface::class);
        $viewConfig = new TestableMollieViewConfig($service, 'EUR');

        self::assertSame(MollieDefinitions::MODULE_ID, $viewConfig->getMollieModuleId());
    }

    public function testGetMollieActiveMethods_DelegatesToTheServiceForTheActiveCurrency(): void
    {
        $methods = [new MollieMethodDto('ideal', 'iDEAL')];
        $service = $this->createMock(PaymentMethodListServiceInterface::class);
        $service->expects(self::once())->method('listActiveMethods')->with('EUR')->willReturn($methods);

        $viewConfig = new TestableMollieViewConfig($service, 'EUR');

        self::assertSame($methods, $viewConfig->getMollieActiveMethods());
    }

    public function testIsMollieDebugLoggingEnabled_TrueOnlyWhenConfigReportsDebugLevel(): void
    {
        $service = $this->createMock(PaymentMethodListServiceInterface::class);

        $debugConfig = $this->createMock(ModuleConfigurationServiceInterface::class);
        $debugConfig->method('isFrontendDebugEnabled')->willReturn(true);
        $debugViewConfig = new TestableMollieViewConfig($service, 'EUR', $debugConfig);
        self::assertTrue($debugViewConfig->isMollieDebugLoggingEnabled());

        $quietConfig = $this->createMock(ModuleConfigurationServiceInterface::class);
        $quietConfig->method('isFrontendDebugEnabled')->willReturn(false);
        $quietViewConfig = new TestableMollieViewConfig($service, 'EUR', $quietConfig);
        self::assertFalse($quietViewConfig->isMollieDebugLoggingEnabled());
    }
}
