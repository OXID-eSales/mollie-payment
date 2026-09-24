<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Admin;

use OxidEsales\Payments\Mollie\Adapter\Dto\MolliePaymentDto;

/**
 * What the customer actually paid with, as the Mollie admin panel needs to show it.
 *
 * Sprint 136: `OXPAYMENTTYPE` only ever says `mollie_payment`. The real answer —
 * iDEAL, Klarna, PayPal, a card, a wallet — lives on the Mollie payment, and it
 * changes what an operator does next (settlement times and dispute channels
 * differ per method). This value object is the projection of that payment onto
 * the two strings the panel renders: a label and an optional card detail.
 *
 * "Unknown" is a real, expected state (no payment yet, or Mollie unreachable)
 * and renders as an em dash — never as a guess.
 *
 * Mirrors the sibling PSP modules' payment-method descriptor by
 * contract, not by inheritance: the two modules ship independently and share no
 * code, only the wording in their lang files.
 */
final readonly class PaymentMethodDescriptor
{
    private const MASK = '••••';

    private function __construct(
        public ?string $rawType,
        public ?string $cardBrand,
        public ?string $cardLast4,
        public ?string $walletType,
    ) {
    }

    public static function fromPayment(?MolliePaymentDto $payment): self
    {
        if ($payment === null) {
            return new self(null, null, null, null);
        }

        return new self(
            $payment->method,
            $payment->cardBrand,
            $payment->cardLast4,
            $payment->walletType,
        );
    }

    /**
     * False when Mollie has not told us the method: no payment exists yet, or
     * the API read failed.
     */
    public function isKnown(): bool
    {
        return $this->rawType !== null && $this->rawType !== '';
    }

    /**
     * The code that names this payment for the operator. A wallet outranks the
     * card it fronted: Mollie reports Apple Pay as `method=creditcard` with a
     * wallet marker, and an Apple Pay payment is not "a card payment" to the
     * operator on the phone.
     */
    public function displayType(): ?string
    {
        if (!$this->isKnown()) {
            return null;
        }

        if ($this->walletType !== null && $this->walletType !== '') {
            return $this->walletType;
        }

        return $this->rawType;
    }

    /**
     * Language key for {@see displayType()}, or null when the method is unknown
     * or unmapped — the caller then shows the raw code verbatim.
     */
    public function labelKey(): ?string
    {
        $type = $this->displayType();

        if ($type === null) {
            return null;
        }

        return PaymentMethodLabels::keyFor($type);
    }

    /**
     * Card sub-detail: "Visa •••• 4242", "Visa", "•••• 4242" — or null for any
     * method that has no card behind it. Mollie's `cardLabel` already arrives
     * properly cased, so brands are passed through untouched.
     */
    public function detail(): ?string
    {
        $brand = ($this->cardBrand !== null && $this->cardBrand !== '') ? $this->cardBrand : null;
        $last4 = ($this->cardLast4 !== null && $this->cardLast4 !== '') ? $this->cardLast4 : null;

        if ($brand !== null && $last4 !== null) {
            return $brand . ' ' . self::MASK . ' ' . $last4;
        }

        if ($last4 !== null) {
            return self::MASK . ' ' . $last4;
        }

        return $brand;
    }
}
