<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Admin;

/**
 * Semantic validation of admin capture/refund amount inputs — distinct from Sprint 4's
 * character-level user-data validation (this checks numeric shape and bounds, not allowed
 * characters).
 *
 * An ABSENT amount means "full capture/refund" and stays legitimate (`ok(null)`); a
 * PRESENT-but-malformed amount is a failure and must never degrade to null/full-action.
 *
 * Mollie's supported currencies are EUR-only ({@see \OxidEsales\Payments\Mollie\Core\MollieDefinitions::getSupportedCurrencies()}),
 * so — unlike Stripe's minor-unit-aware validator — precision is a flat 2-decimal check; no
 * currency-aware conversion is needed here (YAGNI).
 */
final class AdminAmountValidator
{
    /** One integer part, optionally one `.` or `,` separator with digits. Leading minus allowed (caught as non-positive). */
    private const AMOUNT_PATTERN = '/^-?\d+([.,]\d+)?$/';

    private const MAX_DECIMALS = 2;

    public function validate(mixed $raw, float $bound): AmountValidationResult
    {
        if ($raw === null || $raw === '') {
            return AmountValidationResult::ok(null);
        }

        $text = $this->toText($raw);
        if ($text === null || preg_match(self::AMOUNT_PATTERN, $text) !== 1) {
            return AmountValidationResult::failure(AmountValidationResult::CODE_MALFORMED);
        }

        $amount = (float) str_replace(',', '.', $text);
        if ($amount <= 0.0) {
            return AmountValidationResult::failure(AmountValidationResult::CODE_NOT_POSITIVE);
        }

        if ($this->decimalCount($text) > self::MAX_DECIMALS) {
            return AmountValidationResult::failure(AmountValidationResult::CODE_PRECISION);
        }

        if ($amount > $bound + 0.00001) {
            return AmountValidationResult::failure(AmountValidationResult::CODE_EXCEEDS_BOUND);
        }

        return AmountValidationResult::ok($amount);
    }

    /**
     * String form of the raw input, or null for non-scalar / bool junk. Request parameters
     * arrive as strings; native int/float are tolerated for programmatic callers.
     */
    private function toText(mixed $raw): ?string
    {
        if (is_string($raw)) {
            return $raw;
        }

        if (is_int($raw) || is_float($raw)) {
            return (string) $raw;
        }

        return null;
    }

    /** Digits after the single decimal separator (0 when none). */
    private function decimalCount(string $text): int
    {
        $separatorPos = strcspn($text, '.,');
        if ($separatorPos === strlen($text)) {
            return 0;
        }

        return strlen($text) - $separatorPos - 1;
    }
}
