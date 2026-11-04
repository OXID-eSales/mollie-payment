<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Adapter\Dto;

/**
 * The money Mollie says it holds for a payment: its `amount` and `currency`, nothing else.
 *
 * What a commit reports to payment-base. Reporting the contract's own figures made payment-base's amount check
 * compare the contract with itself, so a payment of the wrong amount or currency committed the order (VR-26 M1).
 * There is no fallback: a payment without a usable amount or currency yields null and the caller refuses the commit.
 */
final readonly class MollieConfirmedFigures
{
    public function __construct(
        public float $amount,
        public string $currency,
    ) {
    }

    public static function fromAmount(MollieAmountDto $amount): ?self
    {
        return self::usable($amount->value, $amount->currency);
    }

    /**
     * @param array<string, mixed> $eventObject the `object` of a verified Mollie webhook event
     */
    public static function fromEventObject(array $eventObject): ?self
    {
        $amount = $eventObject['amount'] ?? null;
        $currency = $eventObject['currency'] ?? null;
        if (!is_float($amount) && !is_int($amount)) {
            return null;
        }
        if (!is_string($currency)) {
            return null;
        }

        return self::usable((float) $amount, $currency);
    }

    private static function usable(float $amount, string $currency): ?self
    {
        if ($amount <= 0.0 || $currency === '') {
            return null;
        }

        return new self($amount, strtoupper($currency));
    }
}
