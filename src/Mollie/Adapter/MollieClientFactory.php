<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Adapter;

use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\MollieApiClient;
use OxidEsales\Payments\Mollie\Adapter\Exception\MollieConfigurationException;

/**
 * Builds a configured Mollie SDK client from an API key. Fails fast and typed when the key is
 * missing. One of only two classes (with MollieAdapter) allowed to reference the Mollie SDK.
 */
final class MollieClientFactory
{
    public function __construct(
        private readonly string $apiKey,
    ) {
    }

    public function create(): MollieApiClient
    {
        if (trim($this->apiKey) === '') {
            throw new MollieConfigurationException(
                'Mollie API key is not configured. Set the test/live key for the active mode.',
            );
        }

        try {
            $client = new MollieApiClient();
            $client->setApiKey($this->apiKey);
        } catch (ApiException $exception) {
            // A malformed key (wrong prefix / too short) must fail as our typed config error,
            // never as a leaked SDK exception.
            throw new MollieConfigurationException(
                'Mollie API key is malformed: it must start with test_/live_ and be at least 30 characters.',
                (int) $exception->getCode(),
                $exception,
            );
        }

        return $client;
    }
}
