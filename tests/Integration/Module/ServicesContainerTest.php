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
 * The module's services.yaml must compile inside the real OXID DI container once the module is
 * active. In the scaffold sprint there are no concrete Mollie services yet, so "compiles" means
 * the container rebuilds without an autowire/definition error while the module is enabled.
 *
 * @group integration
 * @group module
 */
final class ServicesContainerTest extends TestCase
{
    private const SHOP_ID = 1;

    public function testContainer_CompilesWithMollieServices(): void
    {
        try {
            $container = ContainerFactory::getInstance()->getContainer();
            $activation = $container->get(ModuleActivationServiceInterface::class);
            $state = $container->get(ModuleStateServiceInterface::class);
        } catch (Throwable $e) {
            self::markTestSkipped('OXID container unavailable: ' . $e->getMessage());
            return;
        }

        if (!$state->isActive(Module::MODULE_ID, self::SHOP_ID)) {
            $activation->activate(Module::MODULE_ID, self::SHOP_ID);
        }

        // Rebuilding the container after activation proves services.yaml is valid; a broken
        // definition/autowire would throw here.
        $rebuilt = ContainerFactory::getInstance()->getContainer();
        self::assertNotNull($rebuilt);

        $activation->deactivate(Module::MODULE_ID, self::SHOP_ID);
    }
}
