<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Adapter;

/**
 * The one place a currency code is read out of an OXID currency object.
 *
 * Sprint 11 Story 11 (F17). Four call sites each carried their own `?? 'EUR'` / `?: 'EUR'`, which is
 * worse than one: the same unverified guess appearing in four places reads like a corroborated fact.
 * Worse, the guess defeated a gate — `MollieDefinitions::supportsCurrency()` allows EUR only, so a
 * GBP shop whose currency object could not be read was told "EUR", passed the EUR-only check, and was
 * offered methods the create-payment call could not honour.
 *
 * The reader now reports failure instead of inventing a currency, and each caller decides what that
 * means for it. Note the charge currency itself still defaults to EUR one level up, in
 * `payment-base`'s `ContractService::calculateTotals()` — that is inherited and out of Mollie's hands;
 * this class only stops Mollie from adding four more guesses of its own.
 */
final class OxidCurrencyReader
{
    /**
     * The currency code carried by an OXID currency object, or null when it cannot be read.
     */
    public static function codeFrom(mixed $currency): ?string
    {
        if (!is_object($currency) || !isset($currency->name)) {
            return null;
        }

        $name = $currency->name;
        if (!is_scalar($name)) {
            return null;
        }

        $name = trim((string) $name);

        return $name !== '' ? strtoupper($name) : null;
    }
}
