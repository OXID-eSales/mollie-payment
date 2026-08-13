<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Integration\Module;

use OxidEsales\Eshop\Application\Controller\OrderController as CoreOrderController;
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

    public function testMetadata_ExtendsViewConfigPaymentControllerAndOrderController(): void
    {
        $extend = $this->metadata()['extend'] ?? [];
        self::assertIsArray($extend);
        self::assertArrayHasKey(CoreViewConfig::class, $extend);
        self::assertArrayHasKey(CorePaymentController::class, $extend);
        self::assertArrayHasKey(CoreOrderController::class, $extend);
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

    /**
     * MollieOrderController is reached at `cl=order` via the class-chain `extend` (checked
     * above) — NOT as a standalone `controllers` entry (that was the pre-fix wiring bug: the
     * standalone `cl=MollieOrderController` route was never hit by the "Place order" submit,
     * which posts to `cl=order&fnc=execute`).
     */
    public function testMetadata_DoesNotRegisterMollieOrderControllerAsAStandaloneController(): void
    {
        $controllers = $this->metadata()['controllers'] ?? [];
        self::assertIsArray($controllers);
        self::assertArrayNotHasKey('MollieOrderController', $controllers);
    }

    /**
     * The groups the module's own settings live in must all still be there.
     *
     * Asserts the expected set is *present* rather than counting groups: the count carried no
     * information and made adding a legitimate group a test failure, which is what happened when
     * `MOLLIE_ADVANCED` arrived with opc-125. Removing or renaming a group is still caught, which is the
     * part that would actually orphan settings in the admin UI. That every group has a translated header
     * is covered by {@see \OxidEsales\Payments\Mollie\Tests\Unit\Core\SelectSettingTranslationsTest}.
     */
    public function testMetadata_DeclaresTheExpectedSettingGroups(): void
    {
        $settings = $this->metadata()['settings'] ?? [];
        self::assertIsArray($settings);
        $groups = [];
        foreach ($settings as $setting) {
            self::assertIsArray($setting);
            $groups[(string) $setting['group']] = true;
        }

        foreach (
            [
                'MOLLIE_GENERAL',
                'MOLLIE_TEST_CONFIG',
                'MOLLIE_LIVE_CONFIG',
                'MOLLIE_WEBHOOKS',
                'MOLLIE_LOGGING',
            ] as $expected
        ) {
            self::assertArrayHasKey(
                $expected,
                $groups,
                'missing setting group ' . $expected . '; got: ' . implode(',', array_keys($groups)),
            );
        }
    }

    /**
     * Setting names carry a Mollie prefix so they cannot collide with another module's in `oxconfig`.
     *
     * One documented exception: cross-provider settings that a *consumer* reads by exact name across all
     * payment modules. `sPaymentHandlerUiTopology` (opc-125 rev-56) is read by one-page-checkout's
     * PaymentHandlerRegistry to learn how each provider's checkout UI is shaped, so the name is part of
     * that contract and cannot be Mollie-namespaced. Add to the allowlist only for names that a shared
     * consumer defines — not to excuse a forgotten prefix.
     */
    public function testMetadata_AllSettingsWellFormed(): void
    {
        $crossProviderContractNames = ['sPaymentHandlerUiTopology'];

        foreach ($this->metadata()['settings'] ?? [] as $setting) {
            self::assertIsArray($setting);
            self::assertIsString($setting['name']);

            if (!in_array($setting['name'], $crossProviderContractNames, true)) {
                self::assertMatchesRegularExpression('/^(sMollie|blMollie|aMollie)/', $setting['name']);
            }

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
