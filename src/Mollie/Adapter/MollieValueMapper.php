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

    /**
     * Read one string field out of Mollie's loosely-typed `details` bag (a
     * `stdClass` whose keys depend entirely on the payment method).
     *
     * Sprint 136.
     */
    public static function toDetailString(mixed $details, string $property): ?string
    {
        $data = is_object($details) ? (array) $details : (is_array($details) ? $details : []);

        return self::toNullableString($data[$property] ?? null);
    }

    /**
     * Last four digits out of Mollie's masked card number ("**** **** **** 4242").
     *
     * The mask characters vary by method and by API version, so the digits are
     * taken from the tail rather than by cutting at a fixed offset. A number
     * that carries no trailing digits yields null: the mask itself must never
     * be presented as digits a customer can confirm.
     *
     * Sprint 136.
     */
    public static function toCardLast4(mixed $details): ?string
    {
        $cardNumber = self::toDetailString($details, 'cardNumber');

        if ($cardNumber === null) {
            return null;
        }

        if (preg_match('/(\d{4})\D*$/', $cardNumber, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    public static function toNullableString(mixed $value): ?string
    {
        if ($value === null || $value === '' || !is_scalar($value)) {
            return null;
        }

        return (string) $value;
    }
}
