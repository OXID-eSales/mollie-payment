<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Service;

use OxidEsales\PaymentBase\Contract\AttemptClosure;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieAmountDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MolliePaymentDto;
use OxidEsales\Payments\Mollie\Adapter\MolliePaymentsAdapterInterface;
use OxidEsales\Payments\Mollie\Service\MollieAttemptCloser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * OPC-175 / S2d — the customer leaves a Mollie attempt for another payment
 * method. A cancelable open payment is cancelled; one Mollie keeps open is
 * reported as Open (late money on it is refunded).
 */
final class MollieAttemptCloserTest extends TestCase
{
    private MolliePaymentsAdapterInterface&MockObject $payments;

    protected function setUp(): void
    {
        $this->payments = $this->createMock(MolliePaymentsAdapterInterface::class);
    }

    public function testProvider_IsMollie(): void
    {
        self::assertSame('mollie', $this->closer()->provider());
    }

    public function testClose_PaidOrAuthorizedPayment_IsPaid(): void
    {
        $this->payments->method('getPayment')->willReturnOnConsecutiveCalls($this->payment('paid'), $this->payment('authorized'));
        $this->payments->expects($this->never())->method('cancelPayment');

        self::assertSame(AttemptClosure::Paid, $this->closer()->close($this->contract('tr_1')));
        self::assertSame(AttemptClosure::Paid, $this->closer()->close($this->contract('tr_1')));
    }

    public function testClose_CancelableOpenPayment_IsCancelledAndClosed(): void
    {
        $this->payments->method('getPayment')->with('tr_1')->willReturn($this->payment('open', cancelable: true));
        $this->payments->expects($this->once())->method('cancelPayment')->with('tr_1')
            ->willReturn($this->payment('canceled'));

        self::assertSame(AttemptClosure::Closed, $this->closer()->close($this->contract('tr_1')));
    }

    public function testClose_OpenPaymentMollieKeepsOpen_IsOpen(): void
    {
        $this->payments->method('getPayment')->willReturn($this->payment('open', cancelable: false));
        $this->payments->expects($this->never())->method('cancelPayment');

        self::assertSame(AttemptClosure::Open, $this->closer()->close($this->contract('tr_1')));
    }

    public function testClose_EndedPayment_IsClosedWithoutACall(): void
    {
        foreach (['canceled', 'expired', 'failed'] as $status) {
            $payments = $this->createMock(MolliePaymentsAdapterInterface::class);
            $payments->method('getPayment')->willReturn($this->payment($status));
            $payments->expects($this->never())->method('cancelPayment');

            self::assertSame(AttemptClosure::Closed, (new MollieAttemptCloser($payments))->close($this->contract('tr_1')), $status);
        }
    }

    public function testClose_AttemptWithoutMolliePayment_IsClosedWithoutACall(): void
    {
        $this->payments->expects($this->never())->method('getPayment');

        self::assertSame(AttemptClosure::Closed, $this->closer()->close($this->contract(null)));
    }

    private function closer(): MollieAttemptCloser
    {
        return new MollieAttemptCloser($this->payments);
    }

    private function contract(?string $paymentId): PaymentContract
    {
        $contract = new PaymentContract(1, 'user-1', BasketSnapshot::fromArray([
            'items' => [], 'discounts' => [], 'totalGross' => 1.0, 'totalNet' => 1.0, 'totalVat' => 0.0, 'currency' => 'EUR',
        ]), 'ctr-1');
        if ($paymentId !== null) {
            $contract->setProvider('mollie', $paymentId);
        }

        return $contract;
    }

    private function payment(string $status, bool $cancelable = false): MolliePaymentDto
    {
        return new MolliePaymentDto(
            id: 'tr_1',
            status: $status,
            amount: MollieAmountDto::fromArray(['currency' => 'EUR', 'value' => '1.00']),
            isCancelable: $cancelable,
        );
    }
}
