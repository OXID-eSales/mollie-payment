<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Adapter;

use OxidEsales\Payments\Mollie\Adapter\LazyMollieAdapter;
use OxidEsales\Payments\Mollie\Service\Factory\MollieAdapterFactory;
use OxidEsales\Payments\Mollie\Service\ModuleConfigurationServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MollieAdapterFactory::class)]
final class MollieAdapterFactoryTest extends TestCase
{
    public function testCreate_ReturnsLazyAdapter(): void
    {
        $config = $this->createMock(ModuleConfigurationServiceInterface::class);
        $adapter = (new MollieAdapterFactory($config))->create();
        self::assertInstanceOf(LazyMollieAdapter::class, $adapter);
    }

    public function testCreate_DoesNotReadCredentialsAtConstruction(): void
    {
        // Credentials must only be resolved on first adapter use, not while the container builds.
        $config = $this->createMock(ModuleConfigurationServiceInterface::class);
        $config->expects(self::never())->method('getApiKey');

        (new MollieAdapterFactory($config))->create();
    }
}
