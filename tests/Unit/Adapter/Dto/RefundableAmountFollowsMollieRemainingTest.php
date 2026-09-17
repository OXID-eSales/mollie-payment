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
 * 2026-09-17 — right after an admin refund the panel still showed the OLD refundable amount.
 *
 * Observed live on a test-mode payment: one 1.00 refund created → Mollie answers
 * `amountRefunded = 0.00` (the refund is still `pending`) but `amountRemaining = 999.00`. The
 * local arithmetic (captured − refunded − charged back) only moves once refunds SETTLE, so it
 * overstated the bound by every pending refund and invited a second refund Mollie would reject.
 *
 * Mollie documents `amountRemaining` as "the remaining amount that can be refunded" and sends it
 * "only when refunds are available for this payment". When it is present it is the answer; the
 * arithmetic stays as the fallback for payments Mollie sends no such figure for.
 */
#[CoversClass(MolliePaymentDto::class)]
#[Group('refund-bound')]
final class RefundableAmountFollowsMollieRemainingTest extends TestCase
{
    public function testMolliesRemainingRefundableAmountWinsWhilePendingRefundsAreNotYetSettled(): void
    {
        // Live shape: authorized 1156.55, captured 1000.00, one pending 1.00 refund.
        $payment = $this->payment(amount: 1156.55, captured: 1000.0, refunded: 0.0, remaining: 999.0);

        self::assertSame(999.0, $payment->refundableAmount());
    }

    public function testRemainingZeroMeansNothingLeftEvenThoughNoRefundHasSettledYet(): void
    {
        $payment = $this->payment(amount: 100.0, captured: 100.0, refunded: 0.0, remaining: 0.0);

        self::assertSame(0.0, $payment->refundableAmount());
    }

    public function testWithoutARemainingFigureTheCapturedBasedArithmeticStillApplies(): void
    {
        $payment = $this->payment(amount: 100.0, captured: 60.0, refunded: 10.0, remaining: null);

        self::assertSame(50.0, $payment->refundableAmount());
    }

    public function testFromArrayLeavesAmountRemainingNullWhenMollieOmitsIt(): void
    {
        $payment = MolliePaymentDto::fromArray([
            'id' => 'tr_1',
            'status' => 'authorized',
            'amount' => ['currency' => 'EUR', 'value' => '100.00'],
        ]);

        self::assertNull($payment->amountRemaining);
        self::assertSame(100.0, $payment->capturableAmount(), 'absent remaining: the whole authorization is capturable');
    }

    public function testFromArrayMapsAmountRemainingWhenPresent(): void
    {
        $payment = MolliePaymentDto::fromArray([
            'id' => 'tr_1',
            'status' => 'paid',
            'amount' => ['currency' => 'EUR', 'value' => '100.00'],
            'amountRemaining' => '42.00',
        ]);

        self::assertSame(42.0, $payment->amountRemaining);
    }

    private function payment(float $amount, ?float $captured, float $refunded, ?float $remaining): MolliePaymentDto
    {
        return new MolliePaymentDto(
            id: 'tr_live',
            status: 'paid',
            amount: MollieAmountDto::fromComponents('EUR', $amount),
            amountRefunded: $refunded,
            amountRemaining: $remaining,
            amountCaptured: $captured,
        );
    }
}
