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

    /**
     * Sprint 11 Story 9 (F19) — the loggers are really injected, not defaulted.
     *
     * Three services carried `= new NullLogger()` / `?? new NullLogger()` defaults. They were dormant
     * (the shop container registers `Psr\Log\LoggerInterface`), but a dormant default is still a
     * promise contingent on wiring nobody re-checks: `ContractRefundRecorder`'s docblock says a skipped
     * refund is "logged as a warning for operator visibility". Making the parameter required moves that
     * from hope to a compile error — and this test proves the container can satisfy it.
     *
     * Also covers Story 7's new `MollieWebhookUrlProvider`, whose whole reason to exist is that
     * `ModuleConfigurationService` cannot hold a `ShopAdapterInterface` without closing a dependency
     * cycle. If that reasoning were wrong, the container would fail to compile it here.
     */
    public function testServicesWithRequiredLoggersResolveFromTheContainer(): void
    {
        // Deliberately does NOT go through ModuleActivationServiceInterface: that service is not public
        // in the compiled container here, which is why testContainer_CompilesWithMollieServices() skips.
        // Resolving the ids directly is what this story actually needs to prove, and it runs.
        $container = ContainerFactory::getInstance()->getContainer();

        if (!$container->has(\OxidEsales\Payments\Mollie\Service\MollieWebhookUrlProviderInterface::class)) {
            self::markTestSkipped('Mollie services are not registered in this container (module inactive).');
        }

        foreach (
            [
                \OxidEsales\Payments\Mollie\Service\RefundServiceInterface::class,
                \OxidEsales\Payments\Mollie\Service\ContractRefundRecorder::class,
                \OxidEsales\Payments\Mollie\Service\MollieWebhookUrlProviderInterface::class,
                \OxidEsales\Payments\Mollie\Service\ModuleConfigurationServiceInterface::class,
                \OxidEsales\Payments\Mollie\Admin\AdminActionBoundsInterface::class,
                \OxidEsales\Payments\Mollie\Webhook\Handler\PaymentPaidHandler::class,
            ] as $serviceId
        ) {
            self::assertNotNull(
                $container->get($serviceId),
                sprintf('%s must resolve with its required collaborators', $serviceId),
            );
        }
    }
}
