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
 * `amountCaptured` and `amountRemaining` are nullable on purpose — Mollie sends each only when it
 * applies, and absence carries information the SDK's `getAmount…()` accessors (0.0 for null) throw
 * away. `amountCaptured`: "only when this payment supports captures", so null means "settled for
 * the full amount" (iDEAL, PayPal, …) while 0.00 means "an authorization nothing has been captured
 * from yet". `amountRemaining`: "only when refunds are available for this payment", so null means
 * "Mollie offers no refundable figure" while 0.00 means "nothing left to refund" — even while the
 * refunds that exhausted it are still pending. Same shape of ambiguity F11 fixed for
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
        public ?float $amountRemaining = null,
        public ?string $redirectUrl = null,
        public ?string $webhookUrl = null,
        public float $amountChargedBack = 0.0,
        public ?string $createdAt = null,
        public ?string $cardBrand = null,
        public ?string $cardLast4 = null,
        public ?string $walletType = null,
        public ?float $amountCaptured = null,
        // OPC-175: whether Mollie lets the shop cancel the payment now (`isCancelable`).
        public bool $isCancelable = false,
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
            isset($data['amountRemaining']) ? (float) $data['amountRemaining'] : null,
            isset($data['redirectUrl']) ? (string) $data['redirectUrl'] : null,
            isset($data['webhookUrl']) ? (string) $data['webhookUrl'] : null,
            (float) ($data['amountChargedBack'] ?? 0.0),
            isset($data['createdAt']) ? (string) $data['createdAt'] : null,
            amountCaptured: isset($data['amountCaptured']) ? (float) $data['amountCaptured'] : null,
        );
    }

    /**
     * Remaining refundable balance. Never negative. Single source of truth for both the admin
     * panel's refund bound ({@see \OxidEsales\Payments\Mollie\Admin\AdminActionBounds}) and
     * Sprint 6's {@see \OxidEsales\Payments\Mollie\Service\RefundService}.
     *
     * Mollie's own `amountRemaining` ("the remaining amount that can be refunded") is the answer
     * whenever Mollie sends it: it already accounts for refunds that are still *pending*, which
     * `amountRefunded` does not — right after an admin refund Mollie reports `amountRefunded 0.00`
     * but `amountRemaining` already reduced, so local arithmetic overstated the bound by every
     * pending refund and the panel kept showing the pre-refund amount (2026-09-17).
     *
     * Without that figure: what actually settled minus what was refunded or charged back. "What
     * settled" is `amountCaptured` when Mollie reports it and the full `amount` otherwise —
     * starting from `amount` unconditionally reported a partially captured payment (authorized
     * 100.00, captured 60.00) as 100.00 refundable, money the customer was never charged.
     */
    public function refundableAmount(): float
    {
        if ($this->amountRemaining !== null) {
            return max(0.0, $this->amountRemaining);
        }

        return max(0.0, $this->settledAmount() - $this->amountRefunded - $this->amountChargedBack);
    }

    private function settledAmount(): float
    {
        return $this->amountCaptured ?? $this->amount->value;
    }

    /**
     * Remaining capturable balance for a two-step (manual capture) payment.
     *
     * Only an `authorized` payment has funds waiting to be captured — that status check is
     * load-bearing: a `paid` payment (single-capture account, or one fully captured) has nothing
     * left to capture.
     *
     * The remaining amount is `amount − amountCaptured` (MOL-32). Under multi-capture the payment
     * stays `authorized` after a partial capture with `amountCaptured` set, so this correctly
     * decreases with each capture and reaches 0 when fully captured. Before any capture
     * `amountCaptured` is null → the full authorized amount.
     *
     * Mollie's `amountRemaining` is deliberately NOT used here: it is refund-oriented ("the
     * remaining amount that can be refunded, only available when refunds are available" — Mollie
     * get-payment reference), so on a partially-captured payment it reports the refundable remainder
     * of the captured part, not what is still capturable. The pre-MOL-32 formula
     * (`amountRemaining > 0 ? amountRemaining : amount`) fell back to the full `amount` after a
     * partial capture and offered the whole authorization for capture a second time (Sprint 11
     * Story 10 / F11 fixed only the fully-captured/non-authorized case, not the multi-capture one,
     * which could not occur until multi-capture was enabled on the account).
     */
    public function capturableAmount(): float
    {
        if ($this->status !== MollieStatusMapper::STATUS_AUTHORIZED) {
            return 0.0;
        }

        return max(0.0, $this->amount->value - ($this->amountCaptured ?? 0.0));
    }
}
