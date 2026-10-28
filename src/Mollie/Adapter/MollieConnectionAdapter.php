<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Adapter;

use Closure;
use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\MollieApiClient;

/**
 * The SDK side of the connection check: `GET /v2/profiles/me`, the cheapest call every API key may make. The client
 * is built on first use (the key is read lazily, never at container build), like {@see LazyMollieAdapter}.
 */
final class MollieConnectionAdapter implements MollieConnectionAdapterInterface
{
    /**
     * @param Closure(): MollieApiClient $clientFactory
     */
    public function __construct(private readonly Closure $clientFactory)
    {
    }

    public function verifyConnection(): void
    {
        $client = ($this->clientFactory)();
        try {
            $client->profiles->getCurrent();
        } catch (ApiException $exception) {
            throw MollieExceptionConverter::convert($exception);
        }
    }
}
