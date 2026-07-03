<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Adapter;

/**
 * The single place a Mollie payment status string is interpreted. Unknown statuses map to a safe
 * IGNORED no-op (never throw) so a future Mollie status can't break the webhook pipeline.
 */
final class MollieStatusMapper
{
    // Mollie payment status wire values (Types\PaymentStatus).
    public const STATUS_OPEN = 'open';
    public const STATUS_PENDING = 'pending';
    public const STATUS_AUTHORIZED = 'authorized';
    public const STATUS_PAID = 'paid';
    public const STATUS_CANCELED = 'canceled';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_FAILED = 'failed';

    public function map(string $mollieStatus): MollieOutcome
    {
        return match ($mollieStatus) {
            self::STATUS_PAID => MollieOutcome::PAID,
            self::STATUS_AUTHORIZED => MollieOutcome::AUTHORIZED,
            self::STATUS_OPEN, self::STATUS_PENDING => MollieOutcome::PENDING,
            self::STATUS_CANCELED => MollieOutcome::CANCELED,
            self::STATUS_EXPIRED => MollieOutcome::EXPIRED,
            self::STATUS_FAILED => MollieOutcome::FAILED,
            default => MollieOutcome::IGNORED,
        };
    }
}
