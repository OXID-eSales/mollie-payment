<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Integration\Module;

// The shop's autoloader runs the integration suite in CI and does not map this module's Tests namespace.
require_once __DIR__ . '/../Support/ShopContainer.php';

use OxidEsales\EshopCommunity\Internal\Framework\Module\Setup\Service\ModuleActivationServiceInterface;
use OxidEsales\EshopCommunity\Internal\Framework\Module\State\ModuleStateServiceInterface;
use OxidEsales\Payments\Mollie\Module;
use OxidEsales\Payments\Mollie\Tests\Integration\Support\ShopContainer;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the module activation/deactivation lifecycle.
 *
 * Verifies the Mollie module activates and deactivates cleanly on a shop.
 *
 * The services come from ShopContainer: ModuleActivationServiceInterface is private, which the
 * production container removes. Asking that container for it is an error, not a reason to skip.
 *
 * No IntegrationTestCase: its database transaction is rolled back after the test, but activation
 * also writes the module configuration files, which a rollback does not undo. The module's
 * activation state is restored in tearDown instead, so the tests after this one find Mollie as it was.
 *
 * @group integration
 * @group module
 * @group lifecycle
 */
final class ModuleLifecycleTest extends TestCase
{
    private const SHOP_ID = 1;

    private ModuleActivationServiceInterface $activationService;
    private ModuleStateServiceInterface $stateService;
    private bool $wasActive;

    protected function setUp(): void
    {
        parent::setUp();
        $container = ShopContainer::build();
        $this->activationService = $container->get(ModuleActivationServiceInterface::class);
        $this->stateService = $container->get(ModuleStateServiceInterface::class);
        $this->wasActive = $this->stateService->isActive(Module::MODULE_ID, self::SHOP_ID);
    }

    protected function tearDown(): void
    {
        $isActive = $this->stateService->isActive(Module::MODULE_ID, self::SHOP_ID);
        if ($this->wasActive && !$isActive) {
            $this->activationService->activate(Module::MODULE_ID, self::SHOP_ID);
        }
        if (!$this->wasActive && $isActive) {
            $this->activationService->deactivate(Module::MODULE_ID, self::SHOP_ID);
        }
        parent::tearDown();
    }

    public function testModule_InstallsAndActivatesCleanly(): void
    {
        if ($this->wasActive) {
            $this->activationService->deactivate(Module::MODULE_ID, self::SHOP_ID);
        }

        $this->activationService->activate(Module::MODULE_ID, self::SHOP_ID);
        self::assertTrue(
            $this->stateService->isActive(Module::MODULE_ID, self::SHOP_ID),
            'Module should report active after activation',
        );
    }

    public function testModule_DeactivatesWithoutError(): void
    {
        if (!$this->wasActive) {
            $this->activationService->activate(Module::MODULE_ID, self::SHOP_ID);
        }

        $this->activationService->deactivate(Module::MODULE_ID, self::SHOP_ID);
        self::assertFalse(
            $this->stateService->isActive(Module::MODULE_ID, self::SHOP_ID),
            'Module should report inactive after deactivation',
        );
    }
}
