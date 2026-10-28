<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Adapter;

use Mollie\Api\MollieApiClient;
use OxidEsales\Payments\Mollie\Service\ModuleConfigurationServiceInterface;

/**
 * DI factory of the connection check's adapter. The API key of the active mode is read inside the closure, at first
 * use, so the container compiles without a configured key.
 */
final class MollieConnectionAdapterFactory
{
    public function __construct(private readonly ModuleConfigurationServiceInterface $config)
    {
    }

    public function create(): MollieConnectionAdapter
    {
        $config = $this->config;

        return new MollieConnectionAdapter(
            static fn (): MollieApiClient => (new MollieClientFactory($config->getApiKey()))->create(),
        );
    }
}
