<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Integration\Support;

use OxidEsales\EshopCommunity\Internal\Container\ContainerBuilderFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The shop's own container (real project configuration, the active modules' services), built the way
 * ContainerFactory builds it, but with every service definition and alias public before the compile.
 *
 * The production container removes private services, so a test cannot fetch, for example, core's
 * private ModuleActivationServiceInterface from it. Core's test container (ContainerTrait) makes
 * services public but reads an empty in-memory project configuration: it sees no installed module.
 *
 * Build a new one after a module activation: the active modules' services are read at build time.
 */
final class ShopContainer
{
    public static function build(): ContainerInterface
    {
        $container = (new ContainerBuilderFactory())->create()->getContainer();
        foreach ($container->getDefinitions() as $definition) {
            $definition->setPublic(true);
        }
        foreach ($container->getAliases() as $alias) {
            $alias->setPublic(true);
        }
        $container->compile(true);

        return $container;
    }
}
