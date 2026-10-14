<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

/**
 * THE float-money comparison for capture/refund bounds (review 2026-10-14, A-M3: the `0.00001`
 * tolerance lived as an unnamed literal in three files — a future fix to float-money comparison
 * had to be made three times).
 *
 * The tolerance absorbs binary-float representation noise (0.1 + 0.2 style) at amounts the shop
 * handles in cents; it must stay well below half a cent so it can never bless a genuine
 * one-cent overrun.
 */
final class MoneyComparison
{
    public const EPSILON = 0.00001;

    /**
     * True when $amount genuinely exceeds $bound — not merely by float representation noise.
     */
    public static function exceeds(float $amount, float $bound): bool
    {
        return $amount > $bound + self::EPSILON;
    }
}
