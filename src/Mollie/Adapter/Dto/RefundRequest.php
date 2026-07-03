<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Adapter\Dto;

/**
 * Immutable input for refunding a Mollie payment (full or partial).
 */
final readonly class RefundRequest
{
    public function __construct(
        public string $paymentId,
        public MollieAmountDto $amount,
        public ?string $description = null,
        public ?string $idempotencyKey = null,
    ) {
    }
}
