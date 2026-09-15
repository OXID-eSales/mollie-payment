<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Integration\Module;

use PHPUnit\Framework\TestCase;

/**
 * composer.json must declare the payment-base dependency graph and the Mollie SDK, and map the
 * module namespace to src/Mollie. OXID only surfaces autoload/dependency problems at activation.
 *
 * @group integration
 * @group module
 */
final class ComposerStructureTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function composer(): array
    {
        $path = dirname(__DIR__, 3) . '/composer.json';
        self::assertFileExists($path);
        /** @var array<string, mixed> $data */
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        return $data;
    }

    public function testComposer_RequiresPaymentBase(): void
    {
        $require = $this->composer()['require'] ?? [];
        self::assertIsArray($require);
        self::assertArrayHasKey('oxid-esales/payment-base', $require);
    }

    public function testComposer_RequiresMollieApiPhpSdk(): void
    {
        $require = $this->composer()['require'] ?? [];
        self::assertIsArray($require);
        self::assertArrayHasKey('mollie/mollie-api-php', $require);
    }

    public function testAutoload_MollieNamespaceMapsToSrcMollie(): void
    {
        $composer = $this->composer();
        $psr4 = $composer['autoload']['psr-4'] ?? [];
        self::assertIsArray($psr4);
        self::assertSame('./src/Mollie', $psr4['OxidEsales\\Payments\\Mollie\\'] ?? null);

        // The test namespace belongs in autoload-dev, not in the production
        // autoloader: tests/ is stripped from the composer package, and the unit
        // suite runs standalone (tests/phpunit-unit.xml), where the module is the
        // root package and autoload-dev applies.
        $psr4Dev = $composer['autoload-dev']['psr-4'] ?? [];
        self::assertIsArray($psr4Dev);
        self::assertSame('./tests', $psr4Dev['OxidEsales\\Payments\\Mollie\\Tests\\'] ?? null);
    }

    public function testComposer_IsOxideshopModule(): void
    {
        self::assertSame('oxideshop-module', $this->composer()['type'] ?? null);
    }
}
