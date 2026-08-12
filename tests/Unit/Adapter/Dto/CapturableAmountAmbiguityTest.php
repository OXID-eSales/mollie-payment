<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Adapter\Dto;

use OxidEsales\Payments\Mollie\Adapter\Dto\MollieAmountDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MolliePaymentDto;
use OxidEsales\Payments\Mollie\Adapter\MollieStatusMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 11 Story 10 (F11) — `amountRemaining === 0` is ambiguous and must stop being resolved by a
 * guess.
 *
 * The old formula was `amountRemaining > 0 ? amountRemaining : amount->value`, i.e. "zero remaining
 * means no partial capture has happened yet, so the whole amount is capturable". Zero remaining also
 * means **fully captured**, so after a full capture the bound reported the entire amount as
 * capturable again and the local guard in `CaptureService::assertWithinCapturable()` waved a second
 * full capture through. Nothing but Mollie's own status check stopped it — the local guard was a
 * no-op in exactly the case it existed to catch, and the admin panel invited an action that could
 * only end in an error.
 *
 * The payment status disambiguates: only an `authorized` payment has funds waiting to be captured.
 */
#[CoversClass(MolliePaymentDto::class)]
#[Group('F11')]
final class CapturableAmountAmbiguityTest extends TestCase
{
    public function testFullyCapturedPaymentHasNothingLeftToCapture(): void
    {
        // Mollie reports amountRemaining = 0 and the status is no longer `authorized`.
        $payment = $this->payment(status: 'paid', amount: 100.0, remaining: 0.0);

        self::assertSame(0.0, $payment->capturableAmount());
    }

    public function testAuthorizedPaymentWithNoPartialCaptureExposesTheFullAmount(): void
    {
        $payment = $this->payment(status: MollieStatusMapper::STATUS_AUTHORIZED, amount: 100.0, remaining: 0.0);

        self::assertSame(100.0, $payment->capturableAmount());
    }

    public function testPartiallyCapturedAuthorizedPaymentExposesTheRemainder(): void
    {
        $payment = $this->payment(status: MollieStatusMapper::STATUS_AUTHORIZED, amount: 100.0, remaining: 40.0);

        self::assertSame(40.0, $payment->capturableAmount());
    }

    public function testNonAuthorizedStatusesNeverExposeACapturableAmount(): void
    {
        foreach (['paid', 'open', 'pending', 'canceled', 'expired', 'failed', ''] as $status) {
            self::assertSame(
                0.0,
                $this->payment(status: $status, amount: 100.0, remaining: 0.0)->capturableAmount(),
                sprintf('status "%s" must not report a capturable balance', $status),
            );
        }
    }

    private function payment(string $status, float $amount, float $remaining): MolliePaymentDto
    {
        return new MolliePaymentDto(
            id: 'tr_capture',
            status: $status,
            amount: new MollieAmountDto('EUR', $amount),
            amountRemaining: $remaining,
        );
    }
}
