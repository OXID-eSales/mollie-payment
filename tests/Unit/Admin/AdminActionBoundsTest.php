<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Admin;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\Payments\Mollie\Admin\AdminActionBounds;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieAmountDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MolliePaymentDto;
use OxidEsales\Payments\Mollie\Adapter\MolliePaymentsAdapterInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(AdminActionBounds::class)]
final class AdminActionBoundsTest extends TestCase
{
    private MolliePaymentsAdapterInterface&MockObject $paymentsAdapter;
    private AdminActionBounds $bounds;

    protected function setUp(): void
    {
        $this->paymentsAdapter = $this->createMock(MolliePaymentsAdapterInterface::class);
        $this->bounds = new AdminActionBounds($this->paymentsAdapter, new NullLogger());
    }

    public function testRefundBound_IsTheLivePaymentsRefundableAmount(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn('tr_1');
        $this->paymentsAdapter->method('getPayment')->with('tr_1')->willReturn(new MolliePaymentDto(
            id: 'tr_1',
            status: 'paid',
            amount: MollieAmountDto::fromComponents('EUR', 100.0),
            amountRefunded: 40.0,
        ));

        self::assertSame(60.0, $this->bounds->refundBound($contract));
    }

    public function testCaptureBound_IsTheLivePaymentsCapturableAmount(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn('tr_2');
        $this->paymentsAdapter->method('getPayment')->with('tr_2')->willReturn(new MolliePaymentDto(
            id: 'tr_2',
            status: 'authorized',
            amount: MollieAmountDto::fromComponents('EUR', 100.0),
            amountRemaining: 30.0,
        ));

        self::assertSame(30.0, $this->bounds->captureBound($contract));
    }

    public function testRefundBound_WithoutProviderOrderId_IsZero(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn(null);

        self::assertSame(0.0, $this->bounds->refundBound($contract));
    }

    public function testCaptureBound_WhenPaymentLookupFails_IsZero(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn('tr_3');
        $this->paymentsAdapter->method('getPayment')->willThrowException(new \RuntimeException('boom'));

        self::assertSame(0.0, $this->bounds->captureBound($contract));
    }

    public function testIsAuthorizedHold_WhenPaymentAuthorized_True(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn('tr_a');
        $this->paymentsAdapter->method('getPayment')->with('tr_a')->willReturn(new MolliePaymentDto(
            id: 'tr_a',
            status: 'authorized',
            amount: MollieAmountDto::fromComponents('EUR', 100.0),
        ));

        self::assertTrue($this->bounds->isAuthorizedHold($contract));
    }

    public function testIsAuthorizedHold_WhenPaymentCaptured_False(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn('tr_p');
        $this->paymentsAdapter->method('getPayment')->with('tr_p')->willReturn(new MolliePaymentDto(
            id: 'tr_p',
            status: 'paid',
            amount: MollieAmountDto::fromComponents('EUR', 100.0),
        ));

        self::assertFalse($this->bounds->isAuthorizedHold($contract));
    }

    public function testIsAuthorizedHold_WhenLookupFails_False(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn('tr_x');
        $this->paymentsAdapter->method('getPayment')->willThrowException(new \RuntimeException('boom'));

        self::assertFalse($this->bounds->isAuthorizedHold($contract));
    }
}
