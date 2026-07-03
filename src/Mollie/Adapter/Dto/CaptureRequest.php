<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Adapter\Dto;

/**
 * Immutable input for capturing an authorized Mollie payment (two-step methods only).
 * A null amount captures the full authorized amount.
 */
final readonly class CaptureRequest
{
    public function __construct(
        public string $paymentId,
        public ?MollieAmountDto $amount = null,
        public ?string $idempotencyKey = null,
    ) {
    }
}
