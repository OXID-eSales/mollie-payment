<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Service;

use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\Payments\Mollie\Service\ContractLinkedOrderUpdaterInterface;
use OxidEsales\Payments\Mollie\Service\OxidContractLinkedOrderUpdater;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(OxidContractLinkedOrderUpdater::class)]
final class OxidContractLinkedOrderUpdaterTest extends TestCase
{
    public function testImplementsInterface(): void
    {
        self::assertInstanceOf(ContractLinkedOrderUpdaterInterface::class, $this->buildUpdater(null));
    }

    public function testMarkCancelled_SetsTransStatusToCancelled(): void
    {
        $order = $this->createMockOrder();
        $order->expects(self::once())->method('save');

        $this->buildUpdater($order)->markCancelled('order-123');

        self::assertSame('CANCELLED', $order->oxorder__oxtransstatus->value);
    }

    public function testMarkCancelled_IsNoOpForEmptyOrderId(): void
    {
        $order = $this->createMockOrder();
        $order->expects(self::never())->method('save');

        $this->buildUpdater($order)->markCancelled('');
    }

    public function testMarkCancelled_IsNoOpWhenOrderNotFound(): void
    {
        $order = $this->createMockOrder();
        $order->expects(self::never())->method('save');

        $this->buildUpdater(null)->markCancelled('nonexistent-order');
    }

    public function testMarkFailed_SetsTransStatusToFailed(): void
    {
        $order = $this->createMockOrder();
        $order->expects(self::once())->method('save');

        $this->buildUpdater($order)->markFailed('order-456', 'payment_declined');

        self::assertSame('FAILED', $order->oxorder__oxtransstatus->value);
    }

    public function testMarkFailed_IsNoOpForEmptyOrderId(): void
    {
        $order = $this->createMockOrder();
        $order->expects(self::never())->method('save');

        $this->buildUpdater($order)->markFailed('', 'some reason');
    }

    public function testMarkFailed_IsNoOpWhenOrderNotFound(): void
    {
        $order = $this->createMockOrder();
        $order->expects(self::never())->method('save');

        $this->buildUpdater(null)->markFailed('nonexistent-order', 'declined');
    }

    private function buildUpdater(?Order $orderStub): OxidContractLinkedOrderUpdater
    {
        return new class ($orderStub) extends OxidContractLinkedOrderUpdater {
            public function __construct(private readonly ?Order $stub)
            {
            }

            protected function loadOrder(string $orderId): ?Order
            {
                if ($orderId === '') {
                    return null;
                }

                return $this->stub;
            }
        };
    }

    /**
     * @return Order&MockObject
     */
    private function createMockOrder(): Order&MockObject
    {
        return $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['save'])
            ->getMock();
    }
}
