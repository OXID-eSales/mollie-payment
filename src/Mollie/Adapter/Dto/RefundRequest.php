<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Adapter\Dto;

/**
 * Immutable input for refunding a Mollie payment (full or partial).
 *
 * Story 3 (Sprint 9): Added `metadata` field for admin description. Description is stored
 * in Mollie's metadata for audit trail retrieval.
 */
final readonly class RefundRequest
{
    public function __construct(
        public string $paymentId,
        public MollieAmountDto $amount,
        public ?string $reason = null,
        public ?string $idempotencyKey = null,
        public ?string $description = null,
    ) {
    }
}
