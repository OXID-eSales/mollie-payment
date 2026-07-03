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
    ) {
    }

    /**
     * @param array{
     *     id?: string|null,
     *     paymentId?: string|null,
     *     amount?: array{currency?: string|null, value?: string|int|float|null}|null,
     *     status?: string|null
     * } $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['id'] ?? ''),
            (string) ($data['paymentId'] ?? ''),
            MollieAmountDto::fromArray($data['amount'] ?? []),
            (string) ($data['status'] ?? ''),
        );
    }
}
