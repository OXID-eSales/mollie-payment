<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Adapter\Dto;

/**
 * Immutable snapshot of a Mollie refund.
 */
final readonly class MollieRefundDto
{
    private const STATUS_REFUNDED = 'refunded';

    public function __construct(
        public string $id,
        public string $paymentId,
        public MollieAmountDto $amount,
        public string $status,
        public ?string $createdAt = null,
    ) {
    }

    /**
     * Mollie's refund lifecycle is queued, pending, processing, then refunded (or failed / canceled).
     * Only `refunded` is money returned to the shopper; payment-base restocks on that alone.
     */
    public function hasSucceeded(): bool
    {
        return $this->status === self::STATUS_REFUNDED;
    }
}
