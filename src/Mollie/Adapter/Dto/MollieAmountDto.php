<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Adapter\Dto;

/**
 * Immutable money value. The single place Mollie's string-decimal amount quirk lives:
 * Mollie expects {"currency":"EUR","value":"10.00"} (value as a 2-decimal string), never a float.
 */
final readonly class MollieAmountDto
{
    public function __construct(
        public string $currency,
        public float $value,
    ) {
    }

    public static function fromComponents(string $currency, float $value): self
    {
        return new self(strtoupper($currency), $value);
    }

    /**
     * @param array{currency?: string|null, value?: string|int|float|null} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            strtoupper((string) ($data['currency'] ?? '')),
            (float) ($data['value'] ?? 0.0),
        );
    }

    /**
     * @return array{currency: string, value: string}
     */
    public function toMollieArray(): array
    {
        return [
            'currency' => $this->currency,
            'value' => $this->formatValue(),
        ];
    }

    public function formatValue(): string
    {
        return number_format($this->value, 2, '.', '');
    }
}
