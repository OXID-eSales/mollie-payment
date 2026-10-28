<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Controller\Admin;

use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\EventSystem\Broker\EventBrokerInterface;
use OxidEsales\PaymentBase\EventSystem\Event\Request\AbstractProviderRequestEvent;
use OxidEsales\PaymentBase\EventSystem\Event\Request\CancelAuthorizationRequestedEvent;
use OxidEsales\PaymentBase\EventSystem\Event\Request\CaptureRequestedEvent;
use OxidEsales\PaymentBase\EventSystem\Event\Request\RefundRequestedEvent;
use OxidEsales\PaymentBase\EventSystem\Event\EventInterface;
use OxidEsales\PaymentBase\EventSystem\EventDispatcherInterface;
use OxidEsales\Payments\Mollie\EventSystem\Event\MollieReleaseAuthorizationRequestEvent;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\Payments\Mollie\Controller\Admin\OrderActionDispatcher;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(OrderActionDispatcher::class)]
final class OrderActionDispatcherTest extends TestCase
{
    private EventBrokerInterface&MockObject $eventBroker;
    private ContractRepositoryInterface&MockObject $contractRepository;
    private OrderActionDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->eventBroker = $this->createMock(EventBrokerInterface::class);
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->dispatcher = new OrderActionDispatcher($this->eventBroker, $this->contractRepository);
    }

    public function testRefund_DispatchesRefundRequestedEventWithContractAndProviderName(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $this->contractRepository->method('findByOrderId')->with('order-1')->willReturn($contract);

        $this->eventBroker->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(function (AbstractProviderRequestEvent $event) use ($contract): bool {
                self::assertInstanceOf(RefundRequestedEvent::class, $event);
                self::assertSame($contract, $event->getContext()->getContract());
                self::assertSame(MollieDefinitions::PROVIDER_NAME, $event->getContext()->get('providerName'));
                self::assertSame(15.0, $event->getAmount());
                self::assertSame('customer request', $event->getReason());
                return true;
            }))
            ->willReturnArgument(0);

        $this->dispatcher->refund($this->stubOrder('order-1'), 15.0, 'customer request');
    }

    public function testCapture_DispatchesCaptureRequestedEvent(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $this->contractRepository->method('findByOrderId')->willReturn($contract);

        $this->eventBroker->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf(CaptureRequestedEvent::class))
            ->willReturnArgument(0);

        $this->dispatcher->capture($this->stubOrder('order-2'), null, null);
    }

    public function testCancel_DispatchesCancelAuthorizationRequestedEvent(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $this->contractRepository->method('findByOrderId')->willReturn($contract);

        $this->eventBroker->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf(CancelAuthorizationRequestedEvent::class))
            ->willReturnArgument(0);

        $this->dispatcher->cancel($this->stubOrder('order-3'), 'fraud');
    }

    public function testRefund_WhenNoContractFound_StillDispatchesWithoutContract(): void
    {
        $this->contractRepository->method('findByOrderId')->willReturn(null);

        $this->eventBroker->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(function (AbstractProviderRequestEvent $event): bool {
                self::assertNull($event->getContext()->getContract());
                return true;
            }))
            ->willReturnArgument(0);

        $this->dispatcher->refund($this->stubOrder('order-4'), null, null);
    }

    // ── PAY-5: release reports whether Mollie released the remainder ──────────────────────────

    public function testRelease_ReportsTheOutcomeTheHandlerSetOnTheEvent(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $this->contractRepository->method('findByOrderId')->willReturn($contract);
        foreach ([true, false] as $handlerSucceeds) {
            $dispatcher = $this->createMock(EventDispatcherInterface::class);
            $dispatcher->expects(self::once())->method('dispatch')->willReturnCallback(
                static function (EventInterface $event) use ($handlerSucceeds, $contract): EventInterface {
                    self::assertInstanceOf(MollieReleaseAuthorizationRequestEvent::class, $event);
                    self::assertSame($contract, $event->contract);
                    $event->setResult($handlerSucceeds);

                    return $event;
                }
            );

            $released = (new OrderActionDispatcher($this->eventBroker, $this->contractRepository, $dispatcher))
                ->release($this->stubOrder('order-r'), 'done');

            self::assertSame($handlerSucceeds, $released);
        }
    }

    public function testRelease_WithoutAContract_DispatchesNothingAndReportsFailure(): void
    {
        $this->contractRepository->method('findByOrderId')->willReturn(null);
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::never())->method('dispatch');

        self::assertFalse((new OrderActionDispatcher($this->eventBroker, $this->contractRepository, $dispatcher))->release($this->stubOrder('o'), null));
    }

    private function stubOrder(string $id): Order
    {
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn($id);

        return $order;
    }
}
