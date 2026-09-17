<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Admin;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\Payments\Mollie\Adapter\MollieOutcome;
use OxidEsales\Payments\Mollie\Admin\RefundedAmountResolver;
use OxidEsales\Payments\Mollie\Service\Result\TransactionRow;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * 2026-09-17 — the panel's "Refunded" row showed 11.31 for a payment on which Mollie holds one
 * CANCELED 10.00 refund and 1.31 of pending ones.
 *
 * The row displayed `contract.getRefundedAmount()`, which only ever accumulates: a refund that
 * Mollie later cancels (or that fails) stays counted forever. The panel already fetches the live
 * refund list for its transaction table, so the displayed total is the sum of exactly the refund
 * rows the operator sees — minus the ones Mollie voided.
 */
#[CoversClass(RefundedAmountResolver::class)]
final class RefundedAmountResolverTest extends TestCase
{
    private RefundedAmountResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new RefundedAmountResolver();
    }

    public function testCanceledAndFailedRefundsAreNotCounted(): void
    {
        $rows = [
            $this->row(TransactionRow::TYPE_PAYMENT, 1156.55, 'paid', MollieOutcome::PAID),
            $this->row(TransactionRow::TYPE_REFUND, 10.0, 'canceled', MollieOutcome::CANCELED),
            $this->row(TransactionRow::TYPE_REFUND, 2.0, 'failed', MollieOutcome::FAILED),
            $this->row(TransactionRow::TYPE_REFUND, 1.0, 'pending', MollieOutcome::PENDING),
            $this->row(TransactionRow::TYPE_REFUND, 0.31, 'refunded', MollieOutcome::IGNORED),
        ];

        self::assertEqualsWithDelta(1.31, $this->resolver->resolve($rows, $this->contract(11.31)), 0.0001);
    }

    public function testCapturesAndThePaymentRowNeverCountAsRefunds(): void
    {
        $rows = [
            $this->row(TransactionRow::TYPE_PAYMENT, 100.0, 'paid', MollieOutcome::PAID),
            $this->row(TransactionRow::TYPE_CAPTURE, 60.0, 'succeeded', MollieOutcome::IGNORED),
        ];

        self::assertSame(0.0, $this->resolver->resolve($rows, $this->contract(10.0)));
    }

    public function testLivePaymentWithNoRefundsShowsZeroEvenIfTheLocalRecordDisagrees(): void
    {
        // Mollie is the source of truth once it answered: the local 10.00 is a stale record
        // (e.g. a refund that was canceled after it had been recorded).
        $rows = [$this->row(TransactionRow::TYPE_PAYMENT, 100.0, 'paid', MollieOutcome::PAID)];

        self::assertSame(0.0, $this->resolver->resolve($rows, $this->contract(10.0)));
    }

    public function testWithoutALivePaymentTheLocalRecordIsTheFallback(): void
    {
        // TransactionHistoryService returns [] when the payment itself could not be read;
        // "unreachable" must not render as "nothing refunded".
        self::assertSame(10.0, $this->resolver->resolve([], $this->contract(10.0)));
        self::assertSame(0.0, $this->resolver->resolve([], $this->contract(null)));
    }

    private function row(string $type, float $amount, string $status, MollieOutcome $outcome): TransactionRow
    {
        return new TransactionRow($type, 'id_' . $status, $amount, 'EUR', $status, $outcome);
    }

    private function contract(?float $refunded): PaymentContractInterface
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getRefundedAmount')->willReturn($refunded);

        return $contract;
    }
}
