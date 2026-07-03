<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Admin;

/**
 * Outcome of {@see AdminAmountValidator::validate()}.
 *
 * ok(null)  = input absent — full capture/refund semantics (legitimate).
 * ok(float) = parsed, positive, precision- and bound-checked amount.
 * failure() = present-but-invalid input; the action must NOT be dispatched.
 */
final class AmountValidationResult
{
    public const CODE_MALFORMED     = 'amountMalformed';
    public const CODE_NOT_POSITIVE  = 'amountNotPositive';
    public const CODE_PRECISION     = 'amountPrecision';
    public const CODE_EXCEEDS_BOUND = 'amountExceedsBound';

    private function __construct(
        private readonly bool $ok,
        public readonly ?float $amount,
        public readonly ?string $code,
    ) {
    }

    public static function ok(?float $amount): self
    {
        return new self(true, $amount, null);
    }

    public static function failure(string $code): self
    {
        return new self(false, null, $code);
    }

    public function isOk(): bool
    {
        return $this->ok;
    }
}
