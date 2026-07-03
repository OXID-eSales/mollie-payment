<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Integration\Module;

use OxidEsales\Eshop\Application\Controller\PaymentController as CorePaymentController;
use OxidEsales\Eshop\Core\ViewConfig as CoreViewConfig;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\Module;
use PHPUnit\Framework\TestCase;

/**
 * Metadata + settings sanity under the real OXID autoloader.
 *
 * Contract: every class name referenced from `metadata.php` must actually load — OXID only
 * surfaces "class not found" at activation time, which makes debugging painful.
 *
 * @group integration
 * @group module
 */
final class MetadataTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function metadata(): array
    {
        $moduleRoot = dirname(__DIR__, 3);
        $aModule = [];
        require $moduleRoot . '/metadata.php';
        /** @var array<string, mixed> $aModule */
        return $aModule;
    }

    public function testMetadata_IdIsOePaymentsMollie(): void
    {
        $aModule = $this->metadata();
        self::assertSame(Module::MODULE_ID, $aModule['id']);
        self::assertSame('oe_payments_mollie', $aModule['id']);
        self::assertSame(MollieDefinitions::PAYMENT_ID, $aModule['id']);
    }

    public function testMetadata_ExtendsViewConfigAndPaymentController(): void
    {
        $extend = $this->metadata()['extend'] ?? [];
        self::assertIsArray($extend);
        self::assertArrayHasKey(CoreViewConfig::class, $extend);
        self::assertArrayHasKey(CorePaymentController::class, $extend);
        foreach ($extend as $class) {
            self::assertTrue($this->classFileExists($class), "extending class missing: $class");
        }
    }

    public function testMetadata_RegistersWebhookController(): void
    {
        $controllers = $this->metadata()['controllers'] ?? [];
        self::assertIsArray($controllers);
        self::assertArrayHasKey('MollieWebhookController', $controllers);
        self::assertTrue($this->classFileExists($controllers['MollieWebhookController']));
    }

    public function testMetadata_DeclaresFiveSettingGroups(): void
    {
        $settings = $this->metadata()['settings'] ?? [];
        self::assertIsArray($settings);
        $groups = [];
        foreach ($settings as $setting) {
            self::assertIsArray($setting);
            $groups[(string) $setting['group']] = true;
        }
        self::assertCount(5, $groups, 'expected exactly 5 setting groups, got: ' . implode(',', array_keys($groups)));
    }

    public function testMetadata_AllSettingsWellFormed(): void
    {
        foreach ($this->metadata()['settings'] ?? [] as $setting) {
            self::assertIsArray($setting);
            self::assertIsString($setting['name']);
            self::assertMatchesRegularExpression('/^(sMollie|blMollie|aMollie)/', $setting['name']);
            self::assertIsString($setting['type']);
            self::assertContains($setting['type'], ['str', 'bool', 'select', 'arr', 'num']);
        }
    }

    /**
     * Check the file backing a FQCN exists without triggering autoload of virtual `{Core}_parent`.
     */
    private function classFileExists(string $fqcn): bool
    {
        $prefix = 'OxidEsales\\Payments\\Mollie\\';
        if (str_starts_with($fqcn, $prefix)) {
            $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($fqcn, strlen($prefix))) . '.php';
            return is_file(dirname(__DIR__, 3) . '/src/Mollie/' . $relative);
        }
        return class_exists($fqcn, false) || interface_exists($fqcn, false);
    }
}
