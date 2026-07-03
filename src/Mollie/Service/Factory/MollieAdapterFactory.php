<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service\Factory;

use OxidEsales\Payments\Mollie\Adapter\LazyMollieAdapter;
use OxidEsales\Payments\Mollie\Adapter\MollieAdapter;
use OxidEsales\Payments\Mollie\Adapter\MollieClientFactory;
use OxidEsales\Payments\Mollie\Service\ModuleConfigurationServiceInterface;

/**
 * DI factory that produces the shared {@see LazyMollieAdapter}. Credentials are read lazily inside
 * the closure (never at container build), so the container compiles even without configured keys.
 * Mirrors PayPal's PayPalAdapterFactory.
 */
final class MollieAdapterFactory
{
    public function __construct(
        private readonly ModuleConfigurationServiceInterface $config,
    ) {
    }

    public function create(): LazyMollieAdapter
    {
        $config = $this->config;

        return new LazyMollieAdapter(
            static fn (): MollieAdapter => new MollieAdapter(
                (new MollieClientFactory($config->getApiKey()))->create(),
            ),
        );
    }
}
