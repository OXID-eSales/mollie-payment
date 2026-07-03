<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Service;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieAmountDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieCaptureDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MolliePaymentDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieRefundDto;
use OxidEsales\Payments\Mollie\Adapter\MollieCaptureAdapterInterface;
use OxidEsales\Payments\Mollie\Adapter\MolliePaymentsAdapterInterface;
use OxidEsales\Payments\Mollie\Adapter\MollieRefundAdapterInterface;
use OxidEsales\Payments\Mollie\Adapter\MollieStatusMapper;
use OxidEsales\Payments\Mollie\Service\Result\TransactionRow;
use OxidEsales\Payments\Mollie\Service\TransactionHistoryService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(TransactionHistoryService::class)]
final class TransactionHistoryServiceTest extends TestCase
{
    private MolliePaymentsAdapterInterface&MockObject $paymentsAdapter;
    private MollieRefundAdapterInterface&MockObject $refundAdapter;
    private MollieCaptureAdapterInterface&MockObject $captureAdapter;
    private TransactionHistoryService $service;

    protected function setUp(): void
    {
        $this->paymentsAdapter = $this->createMock(MolliePaymentsAdapterInterface::class);
        $this->refundAdapter = $this->createMock(MollieRefundAdapterInterface::class);
        $this->captureAdapter = $this->createMock(MollieCaptureAdapterInterface::class);
        $this->service = new TransactionHistoryService(
            $this->paymentsAdapter,
            $this->refundAdapter,
            $this->captureAdapter,
            new MollieStatusMapper(),
        );
    }

    public function testFetch_WithNoProviderOrderId_ReturnsEmptyList(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn(null);

        self::assertSame([], $this->service->fetch($contract));
    }

    public function testHistory_ListsPaymentRefundsAndCaptures(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn('tr_1');

        $this->paymentsAdapter->method('getPayment')->with('tr_1')->willReturn(new MolliePaymentDto(
            id: 'tr_1',
            status: 'paid',
            amount: MollieAmountDto::fromComponents('EUR', 100.0),
        ));
        $this->captureAdapter->method('listCaptures')->with('tr_1')->willReturn([
            new MollieCaptureDto('cpt_1', 'tr_1', MollieAmountDto::fromComponents('EUR', 100.0), 'succeeded'),
        ]);
        $this->refundAdapter->method('listRefunds')->with('tr_1')->willReturn([
            new MollieRefundDto('re_1', 'tr_1', MollieAmountDto::fromComponents('EUR', 25.0), 'pending'),
        ]);

        $rows = $this->service->fetch($contract);

        self::assertCount(3, $rows);
        self::assertSame(TransactionRow::TYPE_PAYMENT, $rows[0]->type);
        self::assertSame('tr_1', $rows[0]->id);
        self::assertSame(TransactionRow::TYPE_CAPTURE, $rows[1]->type);
        self::assertSame('cpt_1', $rows[1]->id);
        self::assertSame(TransactionRow::TYPE_REFUND, $rows[2]->type);
        self::assertSame('re_1', $rows[2]->id);
        self::assertSame(25.0, $rows[2]->amount);
        self::assertSame('EUR', $rows[2]->currency);
    }

    public function testHistory_MapsStatusesToBadges(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn('tr_2');

        $this->paymentsAdapter->method('getPayment')->willReturn(new MolliePaymentDto(
            id: 'tr_2',
            status: 'paid',
            amount: MollieAmountDto::fromComponents('EUR', 50.0),
        ));
        $this->captureAdapter->method('listCaptures')->willReturn([]);
        $this->refundAdapter->method('listRefunds')->willReturn([
            new MollieRefundDto('re_2', 'tr_2', MollieAmountDto::fromComponents('EUR', 10.0), 'failed'),
        ]);

        $rows = $this->service->fetch($contract);

        self::assertSame('success', $rows[0]->badgeTone(), 'a paid payment row is a success badge');
        self::assertSame('danger', $rows[1]->badgeTone(), 'a failed refund row is a danger badge');
    }

    public function testFetch_WhenPaymentLookupFails_ReturnsEmptyList(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn('tr_3');
        $this->paymentsAdapter->method('getPayment')->willThrowException(new \RuntimeException('boom'));

        self::assertSame([], $this->service->fetch($contract));
    }

    public function testFetch_WhenRefundListingFails_StillReturnsPaymentAndCaptureRows(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn('tr_4');
        $this->paymentsAdapter->method('getPayment')->willReturn(new MolliePaymentDto(
            id: 'tr_4',
            status: 'paid',
            amount: MollieAmountDto::fromComponents('EUR', 10.0),
        ));
        $this->captureAdapter->method('listCaptures')->willReturn([]);
        $this->refundAdapter->method('listRefunds')->willThrowException(new \RuntimeException('boom'));

        $rows = $this->service->fetch($contract);

        self::assertCount(1, $rows);
        self::assertSame(TransactionRow::TYPE_PAYMENT, $rows[0]->type);
    }
}
