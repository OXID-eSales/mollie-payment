<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Service;

use OxidEsales\PaymentBase\Service\OrderPaymentStateServiceInterface;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieAmountDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MolliePaymentDto;
use OxidEsales\Payments\Mollie\Adapter\MolliePaymentsAdapterInterface;
use OxidEsales\Payments\Mollie\Service\OxpaidReconciliationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(OxpaidReconciliationService::class)]
final class OxpaidReconciliationServiceTest extends TestCase
{
    private MolliePaymentsAdapterInterface&MockObject $paymentsAdapter;
    private OrderPaymentStateServiceInterface&MockObject $orderPaymentStateService;
    private OxpaidReconciliationService $service;

    protected function setUp(): void
    {
        $this->paymentsAdapter = $this->createMock(MolliePaymentsAdapterInterface::class);
        $this->orderPaymentStateService = $this->createMock(OrderPaymentStateServiceInterface::class);
        $this->service = new OxpaidReconciliationService($this->paymentsAdapter, $this->orderPaymentStateService);
    }

    public function testReconcile_WhenMolliePaidButOxpaidZero_SetsOxpaid(): void
    {
        $this->paymentsAdapter->method('getPayment')->with('tr_1')->willReturn(
            new MolliePaymentDto('tr_1', 'paid', MollieAmountDto::fromComponents('EUR', 50.0)),
        );

        $this->orderPaymentStateService->expects(self::once())
            ->method('updatePaidTimestamp')
            ->with('order-1')
            ->willReturn(true);

        $healed = $this->service->reconcile('order-1', 'tr_1');

        self::assertTrue($healed);
    }

    public function testReconcile_WhenConsistent_NoOp(): void
    {
        $this->paymentsAdapter->method('getPayment')->with('tr_2')->willReturn(
            new MolliePaymentDto('tr_2', 'open', MollieAmountDto::fromComponents('EUR', 50.0)),
        );

        $this->orderPaymentStateService->expects(self::never())->method('updatePaidTimestamp');

        $healed = $this->service->reconcile('order-2', 'tr_2');

        self::assertFalse($healed);
    }

    public function testReconcile_WhenAlreadyPaidOnOrder_UpdateIsNoOp(): void
    {
        $this->paymentsAdapter->method('getPayment')->willReturn(
            new MolliePaymentDto('tr_3', 'paid', MollieAmountDto::fromComponents('EUR', 50.0)),
        );

        // OrderPaymentStateService's own SQL guard (WHERE OXPAID = zero-date) means it returns
        // false when the order is already marked paid — nothing further for us to do.
        $this->orderPaymentStateService->method('updatePaidTimestamp')->willReturn(false);

        $healed = $this->service->reconcile('order-3', 'tr_3');

        self::assertFalse($healed);
    }
}
