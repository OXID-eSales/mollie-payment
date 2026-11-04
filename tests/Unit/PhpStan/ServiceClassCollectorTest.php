<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\PhpStan;

use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\Service\CheckoutUserDataGate;
use OxidEsales\Payments\Mollie\Service\Factory\MollieAdapterFactory;
use OxidEsales\Payments\Mollie\Service\Factory\MollieWebhookFileLoggerFactory;
use OxidEsales\Payments\Mollie\Service\Result\TransactionRow;
use OxidEsales\Payments\Mollie\Tests\PhpStan\DeadCode\ServiceClassCollector;
use PHPUnit\Framework\TestCase;

/**
 * Reads the module's real services.yaml: what the container builds has a constructor caller no PHP file shows.
 */
final class ServiceClassCollectorTest extends TestCase
{
    private ServiceClassCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new ServiceClassCollector(dirname(__DIR__, 3) . '/services.yaml');
    }

    public function testAResourceSweptServiceIsCollected(): void
    {
        self::assertArrayHasKey(CheckoutUserDataGate::class, $this->collector->collect());
    }

    public function testAnExcludedValueObjectIsNotCollected(): void
    {
        self::assertArrayNotHasKey(TransactionRow::class, $this->collector->collect());
    }

    public function testAClassOutsideTheServicesIsNotCollected(): void
    {
        self::assertArrayNotHasKey(MollieDefinitions::class, $this->collector->collect());
    }

    public function testFactoryMethodsAreCollectedPerFactoryClass(): void
    {
        $factories = $this->collector->factoryMethods();

        self::assertSame(['create'], $factories[MollieAdapterFactory::class]);
        self::assertSame(['create'], $factories[MollieWebhookFileLoggerFactory::class]);
    }
}
