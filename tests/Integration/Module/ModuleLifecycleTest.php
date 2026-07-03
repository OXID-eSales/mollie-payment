<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Integration\Module;

use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Module\Setup\Service\ModuleActivationServiceInterface;
use OxidEsales\EshopCommunity\Internal\Framework\Module\State\ModuleStateServiceInterface;
use OxidEsales\Payments\Mollie\Module;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Integration tests for the module activation/deactivation lifecycle.
 *
 * Verifies the Mollie module installs into OXID, activates, and deactivates cleanly on a shop.
 *
 * @group integration
 * @group module
 * @group lifecycle
 */
final class ModuleLifecycleTest extends TestCase
{
    private const SHOP_ID = 1;

    private ?ModuleActivationServiceInterface $activationService = null;
    private ?ModuleStateServiceInterface $stateService = null;

    protected function setUp(): void
    {
        parent::setUp();
        try {
            $container = ContainerFactory::getInstance()->getContainer();
            $this->activationService = $container->get(ModuleActivationServiceInterface::class);
            $this->stateService = $container->get(ModuleStateServiceInterface::class);
        } catch (Throwable $e) {
            self::markTestSkipped('OXID container unavailable: ' . $e->getMessage());
        }
    }

    public function testModule_InstallsAndActivatesCleanly(): void
    {
        self::assertNotNull($this->activationService);
        self::assertNotNull($this->stateService);

        if ($this->stateService->isActive(Module::MODULE_ID, self::SHOP_ID)) {
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
        if ($this->activationService === null || $this->stateService === null) {
            self::fail('container services unavailable');
        }
        if (!$this->stateService->isActive(Module::MODULE_ID, self::SHOP_ID)) {
            $this->activationService->activate(Module::MODULE_ID, self::SHOP_ID);
        }

        $this->activationService->deactivate(Module::MODULE_ID, self::SHOP_ID);
        self::assertFalse(
            $this->stateService->isActive(Module::MODULE_ID, self::SHOP_ID),
            'Module should report inactive after deactivation',
        );
    }
}
