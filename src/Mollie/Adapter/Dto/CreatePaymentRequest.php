<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Adapter\Dto;

/**
 * Immutable input for creating a Mollie payment. The adapter turns this into the SDK create body.
 */
final readonly class CreatePaymentRequest
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public MollieAmountDto $amount,
        public string $description,
        public string $redirectUrl,
        public ?string $webhookUrl = null,
        public ?string $method = null,
        public array $metadata = [],
        public ?string $captureMode = null,
    ) {
    }
}
