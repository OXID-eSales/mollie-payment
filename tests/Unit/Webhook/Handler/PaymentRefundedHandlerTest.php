<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Webhook\Handler;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Refund\ProviderRefundLedgerInterface;
use OxidEsales\PaymentBase\Refund\RefundBooking;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Webhook\WebhookEvent;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieAmountDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieRefundDto;
use OxidEsales\Payments\Mollie\Adapter\MollieRefundAdapterInterface;
use OxidEsales\Payments\Mollie\Webhook\Handler\PaymentRefundedHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * VR-21 F6 — Mollie's `refunded` webhook books each refund of the payment through payment-base's
 * ledger, by its Mollie refund id. The admin refund books the same id; whichever comes second is a
 * duplicate and changes nothing. Before, the webhook booked "API total minus contract total": it
 * raced the admin refund (stale save after the money moved) and answered `refund_recorded` while
 * recording nothing on a contract that was not FULFILLED.
 */
#[CoversClass(PaymentRefundedHandler::class)]
final class PaymentRefundedHandlerTest extends TestCase
{
    private ContractRepositoryInterface&MockObject $contracts;
    private MollieRefundAdapterInterface&MockObject $refunds;
    private ProviderRefundLedgerInterface&MockObject $ledger;
    private PaymentRefundedHandler $handler;

    protected function setUp(): void
    {
        $this->contracts = $this->createMock(ContractRepositoryInterface::class);
        $this->refunds = $this->createMock(MollieRefundAdapterInterface::class);
        $this->ledger = $this->createMock(ProviderRefundLedgerInterface::class);
        $this->handler = new PaymentRefundedHandler($this->contracts, $this->refunds, $this->ledger);
    }

    public function testHandledStatuses_ReturnsRefunded(): void
    {
        self::assertSame(['refunded'], $this->handler->handledStatuses());
    }

    public function testEachRefundOfThePaymentIsBookedByItsMollieId(): void
    {
        $this->contracts->method('findByProviderOrderId')->with('tr_refund')->willReturn($this->contract());
        $this->refunds->method('listRefunds')->with('tr_refund')->willReturn([
            $this->refund('re_1', 30.0, 'refunded'),
            $this->refund('re_2', 20.0),
        ]);
        $booked = [];
        $this->ledger->method('book')->willReturnCallback(
            static function (string $contractId, string $refundId, float $amount, string $currency, string $source, bool $succeeded) use (&$booked): RefundBooking {
                $booked[] = [$contractId, $refundId, $amount, $currency, $source, $succeeded];

                return RefundBooking::booked(50.0);
            }
        );

        $outcome = $this->handler->handle($this->event('tr_refund'));

        self::assertSame([
            ['contract-1', 're_1', 30.0, 'EUR', 'webhook', true],
            ['contract-1', 're_2', 20.0, 'EUR', 'webhook', false],
        ], $booked);
        self::assertSame('refund_recorded', $outcome->result->action);
        self::assertSame('contract-1', $outcome->contractId);
    }

    public function testRefundsTheAdminAlreadyBookedAreSkippedHonestly(): void
    {
        $this->contracts->method('findByProviderOrderId')->willReturn($this->contract());
        $this->refunds->method('listRefunds')->willReturn([$this->refund('re_1', 30.0)]);
        $this->ledger->method('book')->willReturn(RefundBooking::duplicate(30.0));

        $outcome = $this->handler->handle($this->event('tr_refund'));

        self::assertSame('skipped', $outcome->result->action);
    }

    public function testARefundMollieRefusedToRunIsNotBooked(): void
    {
        $this->contracts->method('findByProviderOrderId')->willReturn($this->contract());
        $this->refunds->method('listRefunds')->willReturn([
            $this->refund('re_failed', 30.0, 'failed'),
            $this->refund('re_canceled', 10.0, 'canceled'),
        ]);
        $this->ledger->expects(self::never())->method('book');

        self::assertSame('skipped', $this->handler->handle($this->event('tr_refund'))->result->action);
    }

    public function testHandle_WhenContractNotFound_IsSkipped(): void
    {
        $this->contracts->method('findByProviderOrderId')->willReturn(null);
        $this->refunds->expects(self::never())->method('listRefunds');

        self::assertSame('skipped', $this->handler->handle($this->event('tr_unknown'))->result->action);
    }

    public function testHandle_WhenPaymentIdMissing_ReturnsFailure(): void
    {
        $outcome = $this->handler->handle(new WebhookEvent(id: 'x', type: 'refunded', data: [], created: time()));

        self::assertTrue($outcome->result->isFailure());
    }

    private function contract(): PaymentContractInterface
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('contract-1');

        return $contract;
    }

    private function refund(string $id, float $amount, string $status = 'pending'): MollieRefundDto
    {
        return new MollieRefundDto($id, 'tr_refund', MollieAmountDto::fromComponents('EUR', $amount), $status);
    }

    private function event(string $paymentId): WebhookEvent
    {
        return new WebhookEvent(
            id: $paymentId,
            type: 'refunded',
            data: ['object' => ['id' => $paymentId, 'amountRefunded' => 50.0]],
            created: time(),
        );
    }
}
