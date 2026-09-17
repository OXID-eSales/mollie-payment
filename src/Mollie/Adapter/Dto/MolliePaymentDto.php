<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Adapter\Dto;

use OxidEsales\Payments\Mollie\Adapter\MollieStatusMapper;

/**
 * Immutable snapshot of a Mollie payment as the rest of the module sees it. No Mollie SDK type
 * crosses this boundary — the adapter maps the SDK Payment into this DTO.
 *
 * Sprint 136: `cardBrand`, `cardLast4` and `walletType` come from Mollie's `details` bag and feed
 * the admin panel's "payment method used" row. All three are null for every method that has no
 * card behind it, which is most of them — `$method` alone answers those.
 *
 * `amountCaptured` is nullable on purpose. Mollie sends it "only when this payment supports
 * captures", so null means "settled for the full amount" (iDEAL, PayPal, …) while 0.00 means "an
 * authorization nothing has been captured from yet". Collapsing the two into 0.0 would make every
 * non-capture payment look unrefundable — the same shape of ambiguity F11 fixed for
 * {@see capturableAmount()}.
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
        public ?string $cardBrand = null,
        public ?string $cardLast4 = null,
        public ?string $walletType = null,
        public ?float $amountCaptured = null,
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
     *     createdAt?: string|null,
     *     amountCaptured?: string|int|float|null
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
            amountCaptured: isset($data['amountCaptured']) ? (float) $data['amountCaptured'] : null,
        );
    }

    /**
     * Remaining refundable balance: what actually settled minus whatever has already been refunded
     * or charged back. Never negative. Single source of truth for both the admin panel's refund
     * bound ({@see \OxidEsales\Payments\Mollie\Admin\AdminActionBounds}) and Sprint 6's
     * {@see \OxidEsales\Payments\Mollie\Service\RefundService}.
     *
     * "What settled" is `amountCaptured` when Mollie reports it and the full `amount` otherwise.
     * Starting from `amount` unconditionally reported a partially captured payment (authorized
     * 100.00, captured 60.00) as 100.00 refundable — money the customer was never charged.
     */
    public function refundableAmount(): float
    {
        return max(0.0, $this->settledAmount() - $this->amountRefunded - $this->amountChargedBack);
    }

    private function settledAmount(): float
    {
        return $this->amountCaptured ?? $this->amount->value;
    }

    /**
     * Remaining capturable balance for a two-step (manual capture) payment.
     *
     * Only an `authorized` payment has funds waiting to be captured. That check is load-bearing, not
     * decorative: `amountRemaining === 0` means *both* "no partial capture yet" and "fully captured",
     * and the previous formula (`amountRemaining > 0 ? amountRemaining : amount->value`) resolved the
     * ambiguity towards the first — so a fully captured payment reported its whole amount as
     * capturable again and the local bound guard became a no-op in precisely the case it was written
     * for. See Sprint 11 Story 10 / F11.
     */
    public function capturableAmount(): float
    {
        if ($this->status !== MollieStatusMapper::STATUS_AUTHORIZED) {
            return 0.0;
        }

        return $this->amountRemaining > 0.0 ? $this->amountRemaining : $this->amount->value;
    }
}
