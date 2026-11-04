<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Adapter\Dto;

/**
 * Immutable snapshot of a Mollie capture (two-step methods only).
 */
final readonly class MollieCaptureDto
{
    public function __construct(
        public string $id,
        public string $paymentId,
        public MollieAmountDto $amount,
        public string $status,
        public ?string $createdAt = null,
    ) {
    }
}
