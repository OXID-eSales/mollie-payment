<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Adapter\Dto;

use OxidEsales\Payments\Mollie\Adapter\Dto\MollieAmountDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MolliePaymentDto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * 2026-09-17 — a partially captured payment reported its FULL authorized amount as refundable.
 *
 * `refundableAmount()` started from `amount` (what was authorized) and subtracted refunds and
 * chargebacks. Money that was never captured was never charged, so it cannot be refunded — Mollie
 * reports what actually settled in `amountCaptured`. The admin panel showed the authorized total as
 * the refund ceiling and `RefundService` (same formula, by design) let a full refund be requested
 * for it.
 *
 * `amountCaptured` is "only available when this payment supports captures" (Mollie API), so its
 * absence is meaningful and must stay distinguishable from a real 0.00: absent → the payment settled
 * for its full amount; present → that is the settled base, even when it is zero.
 */
#[CoversClass(MolliePaymentDto::class)]
#[Group('partial-capture')]
final class PartialCaptureRefundableAmountTest extends TestCase
{
    public function testPartiallyCapturedPaymentIsRefundableUpToTheCapturedAmountOnly(): void
    {
        $payment = $this->payment(amount: 100.0, captured: 60.0);

        self::assertSame(60.0, $payment->refundableAmount());
    }

    public function testRefundsAndChargebacksAreSubtractedFromTheCapturedAmount(): void
    {
        $payment = $this->payment(amount: 100.0, captured: 60.0, refunded: 10.0, chargedBack: 5.0);

        self::assertSame(45.0, $payment->refundableAmount());
    }

    public function testCapturedZeroMeansNothingHasSettledAndNothingIsRefundable(): void
    {
        // An uncaptured authorization: Mollie sends amountCaptured = 0.00. The right admin
        // action is cancel, not refund.
        $payment = $this->payment(amount: 100.0, captured: 0.0);

        self::assertSame(0.0, $payment->refundableAmount());
    }

    public function testPaymentWithoutCaptureSupportIsRefundableForItsFullAmount(): void
    {
        // iDEAL and friends: Mollie omits amountCaptured; the payment settled in full.
        $payment = $this->payment(amount: 100.0, captured: null, refunded: 20.0);

        self::assertSame(80.0, $payment->refundableAmount());
    }

    public function testNeverNegativeEvenWhenRefundsExceedTheCapturedAmount(): void
    {
        $payment = $this->payment(amount: 100.0, captured: 30.0, refunded: 30.0, chargedBack: 5.0);

        self::assertSame(0.0, $payment->refundableAmount());
    }

    public function testFromArrayMapsAmountCapturedWhenPresent(): void
    {
        $payment = MolliePaymentDto::fromArray([
            'id' => 'tr_1',
            'status' => 'paid',
            'amount' => ['currency' => 'EUR', 'value' => '100.00'],
            'amountCaptured' => '60.00',
        ]);

        self::assertSame(60.0, $payment->amountCaptured);
    }

    public function testFromArrayLeavesAmountCapturedNullWhenAbsent(): void
    {
        $payment = MolliePaymentDto::fromArray([
            'id' => 'tr_1',
            'status' => 'paid',
            'amount' => ['currency' => 'EUR', 'value' => '100.00'],
        ]);

        self::assertNull($payment->amountCaptured);
    }

    private function payment(
        float $amount,
        ?float $captured,
        float $refunded = 0.0,
        float $chargedBack = 0.0,
    ): MolliePaymentDto {
        return new MolliePaymentDto(
            id: 'tr_partial',
            status: 'paid',
            amount: MollieAmountDto::fromComponents('EUR', $amount),
            amountRefunded: $refunded,
            amountChargedBack: $chargedBack,
            amountCaptured: $captured,
        );
    }
}
