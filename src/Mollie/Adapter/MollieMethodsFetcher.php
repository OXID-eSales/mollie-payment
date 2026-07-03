<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Adapter;

use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\MollieApiClient;
use Mollie\Api\Resources\Method;
use OxidEsales\Payments\Mollie\Adapter\Dto\MethodsListRequest;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieMethodDto;

/**
 * Fetches + maps the Mollie Methods API (`GET /methods`) — split out of {@see MollieAdapter}
 * purely to keep that class's cyclomatic/class complexity within PHPMD's threshold. Still SDK
 * -coupled and confined to `Adapter/`, so the "one SDK-aware class" note on `MollieAdapter`'s
 * docblock (the module's OUTER boundary: Service/Controller/EventSystem never import the SDK) is
 * unaffected — this is an internal split of that same boundary, not a new one.
 */
final class MollieMethodsFetcher
{
    public function __construct(private readonly MollieApiClient $client)
    {
    }

    /**
     * @return list<MollieMethodDto>
     */
    public function listActiveMethods(MethodsListRequest $request): array
    {
        try {
            $collection = $this->client->methods->allActive($this->buildParameters($request));
        } catch (ApiException $exception) {
            throw MollieExceptionConverter::convert($exception);
        }

        return MollieCollectionMapper::map(
            $collection,
            Method::class,
            fn (Method $method): MollieMethodDto => $this->mapMethod($method),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildParameters(MethodsListRequest $request): array
    {
        $params = [];
        if ($request->billingCountry !== null && $request->billingCountry !== '') {
            $params['billingCountry'] = $request->billingCountry;
        }
        if ($request->locale !== null && $request->locale !== '') {
            $params['locale'] = $request->locale;
        }

        return $params;
    }

    private function mapMethod(Method $method): MollieMethodDto
    {
        return new MollieMethodDto((string) $method->id, (string) $method->description, $this->imageUrl($method));
    }

    private function imageUrl(Method $method): ?string
    {
        if (!is_object($method->image)) {
            return null;
        }

        $url = $method->image->size2x ?? $method->image->size1x ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }
}
