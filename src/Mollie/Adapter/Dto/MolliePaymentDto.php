<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Adapter\Dto;

/**
 * Immutable snapshot of a Mollie payment as the rest of the module sees it. No Mollie SDK type
 * crosses this boundary — the adapter maps the SDK Payment into this DTO.
 */
final readonly class MolliePaymentDto
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $id,
        public string $status,
        public MollieAmountDto $amount,
        public ?string $checkoutUrl = null,
        public ?string $method = null,
        public array $metadata = [],
        public float $amountRefunded = 0.0,
        public float $amountRemaining = 0.0,
        public ?string $redirectUrl = null,
        public ?string $webhookUrl = null,
        public float $amountChargedBack = 0.0,
        public ?string $createdAt = null,
    ) {
    }

    /**
     * @param array{
     *     id?: string|null,
     *     status?: string|null,
     *     amount?: array{currency?: string|null, value?: string|int|float|null}|null,
     *     checkoutUrl?: string|null,
     *     method?: string|null,
     *     metadata?: array<string, mixed>|null,
     *     amountRefunded?: string|int|float|null,
     *     amountRemaining?: string|int|float|null,
     *     redirectUrl?: string|null,
     *     webhookUrl?: string|null,
     *     amountChargedBack?: string|int|float|null,
     *     createdAt?: string|null
     * } $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['id'] ?? ''),
            (string) ($data['status'] ?? ''),
            MollieAmountDto::fromArray($data['amount'] ?? []),
            isset($data['checkoutUrl']) ? (string) $data['checkoutUrl'] : null,
            isset($data['method']) ? (string) $data['method'] : null,
            $data['metadata'] ?? [],
            (float) ($data['amountRefunded'] ?? 0.0),
            (float) ($data['amountRemaining'] ?? 0.0),
            isset($data['redirectUrl']) ? (string) $data['redirectUrl'] : null,
            isset($data['webhookUrl']) ? (string) $data['webhookUrl'] : null,
            (float) ($data['amountChargedBack'] ?? 0.0),
            isset($data['createdAt']) ? (string) $data['createdAt'] : null,
        );
    }

    /**
     * Remaining refundable balance: the full amount minus whatever has already been refunded or
     * charged back. Never negative. Single source of truth for both the admin panel's refund
     * bound ({@see \OxidEsales\Payments\Mollie\Admin\AdminActionBounds}) and Sprint 6's
     * {@see \OxidEsales\Payments\Mollie\Service\RefundService}.
     */
    public function refundableAmount(): float
    {
        return max(0.0, $this->amount->value - $this->amountRefunded - $this->amountChargedBack);
    }

    /**
     * Remaining capturable balance for a two-step (manual capture) payment: `amountRemaining`
     * once a partial capture has already happened, otherwise the full authorized amount.
     */
    public function capturableAmount(): float
    {
        return $this->amountRemaining > 0.0 ? $this->amountRemaining : $this->amount->value;
    }
}
