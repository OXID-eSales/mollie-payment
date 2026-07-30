<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

use OxidEsales\Payments\Mollie\Adapter\Dto\MollieAmountDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieLineDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieProductLineInput;

/**
 * Builds Mollie order `lines` that satisfy Mollie's cent-exact invariants (see {@see MollieLineDto}):
 * per line `unitPrice*quantity == totalAmount` and inclusive-VAT `vatAmount`, and — across all lines —
 * `sum(totalAmount) == payment.amount`. Any residual cent (OXID rounding, discounts) is folded onto a
 * final adjustment line so the sum matches the order total exactly.
 *
 * Pure + static (no state, no I/O) — the reconciliation crux, exhaustively unit-tested. Order data is
 * mapped to the plain inputs by {@see MollieOrderDataProvider}.
 */
final class MollieLinesBuilder
{
    private const CENT = 0.01;

    /**
     * @param list<MollieProductLineInput> $products
     * @return list<MollieLineDto>
     */
    public static function build(
        string $currency,
        float $expectedTotal,
        array $products,
        float $shippingGross,
        float $shippingVatRate,
        float $discountGross,
    ): array {
        $currency = strtoupper($currency);
        $lines = [];

        foreach ($products as $product) {
            $lines[] = self::productLine($currency, $product);
        }
        if (self::round($shippingGross) > 0.0) {
            $lines[] = self::shippingLine($currency, $shippingGross, $shippingVatRate);
        }
        if (self::round($discountGross) > 0.0) {
            $discount = -self::round($discountGross);
            $lines[] = self::flatLine($currency, 'Discount', $discount, MollieLineDto::TYPE_DISCOUNT);
        }

        $delta = self::round($expectedTotal - self::sumTotals($lines));
        if (abs($delta) >= self::CENT) {
            $type = $delta < 0 ? MollieLineDto::TYPE_DISCOUNT : MollieLineDto::TYPE_SURCHARGE;
            $lines[] = self::flatLine($currency, 'Rounding adjustment', $delta, $type);
        }

        return $lines;
    }

    private static function productLine(string $currency, MollieProductLineInput $product): MollieLineDto
    {
        $unit = self::round($product->unitPriceGross);
        $total = self::round($unit * $product->quantity);

        return new MollieLineDto(
            $product->description,
            $product->quantity,
            new MollieAmountDto($currency, $unit),
            new MollieAmountDto($currency, $total),
            $product->vatRatePercent,
            new MollieAmountDto($currency, self::vatAmount($total, $product->vatRatePercent)),
            MollieLineDto::TYPE_PHYSICAL,
        );
    }

    private static function shippingLine(string $currency, float $gross, float $vatRate): MollieLineDto
    {
        $total = self::round($gross);

        return new MollieLineDto(
            'Shipping',
            1,
            new MollieAmountDto($currency, $total),
            new MollieAmountDto($currency, $total),
            $vatRate,
            new MollieAmountDto($currency, self::vatAmount($total, $vatRate)),
            MollieLineDto::TYPE_SHIPPING_FEE,
        );
    }

    /**
     * A quantity-1, zero-VAT line (discount / surcharge / rounding adjustment). `unitPrice == total`
     * (quantity 1) and `vatAmount == 0` both hold, satisfying Mollie's per-line invariants.
     */
    private static function flatLine(string $currency, string $description, float $total, string $type): MollieLineDto
    {
        $total = self::round($total);

        return new MollieLineDto(
            $description,
            1,
            new MollieAmountDto($currency, $total),
            new MollieAmountDto($currency, $total),
            0.0,
            new MollieAmountDto($currency, 0.0),
            $type,
        );
    }

    /** Inclusive VAT: the tax already contained in a gross amount. Zero rate → zero VAT. */
    private static function vatAmount(float $grossTotal, float $vatRate): float
    {
        if ($vatRate <= 0.0) {
            return 0.0;
        }

        return self::round($grossTotal * $vatRate / (100.0 + $vatRate));
    }

    /**
     * @param list<MollieLineDto> $lines
     */
    private static function sumTotals(array $lines): float
    {
        $sum = 0.0;
        foreach ($lines as $line) {
            $sum += $line->totalAmount->value;
        }

        return self::round($sum);
    }

    private static function round(float $value): float
    {
        return round($value, 2);
    }
}
