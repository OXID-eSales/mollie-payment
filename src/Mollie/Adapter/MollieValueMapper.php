<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Adapter;

use OxidEsales\Payments\Mollie\Adapter\Dto\MollieAmountDto;

/**
 * Small, stateless coercions from the Mollie SDK's loosely-typed `stdClass`/mixed properties into
 * the module's DTOs/scalars. Split out of {@see MollieAdapter} purely to keep that class's
 * cyclomatic/class complexity within PHPMD's threshold — still confined to `Adapter/`.
 */
final class MollieValueMapper
{
    public static function toAmount(mixed $amount): MollieAmountDto
    {
        $data = is_object($amount) ? (array) $amount : (is_array($amount) ? $amount : []);
        $currency = isset($data['currency']) && is_scalar($data['currency']) ? (string) $data['currency'] : '';
        $value = isset($data['value']) && is_scalar($data['value']) ? (float) $data['value'] : 0.0;

        return MollieAmountDto::fromComponents($currency, $value);
    }

    /**
     * @return array<string, mixed>
     */
    public static function toMetadata(mixed $metadata): array
    {
        $data = is_object($metadata) ? (array) $metadata : (is_array($metadata) ? $metadata : []);
        $out = [];
        foreach ($data as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    public static function toNullableString(mixed $value): ?string
    {
        if ($value === null || $value === '' || !is_scalar($value)) {
            return null;
        }

        return (string) $value;
    }
}
