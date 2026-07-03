<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\EventSystem\Handler;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\EventSystem\Handler\HandlerInterface;
use OxidEsales\Payments\Mollie\EventSystem\Event\MollieCancelAuthorizationRequestEvent;
use OxidEsales\Payments\Mollie\EventSystem\Handler\MollieCancelAuthorizationRequestHandler;
use OxidEsales\Payments\Mollie\Service\CancelAuthorizationServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(MollieCancelAuthorizationRequestHandler::class)]
final class MollieCancelAuthorizationRequestHandlerTest extends TestCase
{
    private CancelAuthorizationServiceInterface&MockObject $cancelService;
    private MollieCancelAuthorizationRequestHandler $handler;

    protected function setUp(): void
    {
        $this->cancelService = $this->createMock(CancelAuthorizationServiceInterface::class);
        $this->handler = new MollieCancelAuthorizationRequestHandler($this->cancelService);
    }

    public function testImplementsHandlerInterface(): void
    {
        self::assertInstanceOf(HandlerInterface::class, $this->handler);
    }

    public function testGetHandledEventClass(): void
    {
        self::assertSame(
            MollieCancelAuthorizationRequestEvent::class,
            MollieCancelAuthorizationRequestHandler::getHandledEventClass(),
        );
    }

    public function testHandle_DelegatesToCancelService(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $event = new MollieCancelAuthorizationRequestEvent($contract, 'fraud_suspected');

        $this->cancelService->expects(self::once())
            ->method('cancel')
            ->with($contract, 'fraud_suspected');

        $this->handler->handle($event);

        self::assertTrue($event->isSuccess());
    }

    public function testHandle_IgnoresOtherEventTypes(): void
    {
        $this->cancelService->expects(self::never())->method('cancel');

        $this->handler->handle(new \stdClass());
    }

    public function testHandle_OnDomainError_SetsErrorResult(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $event = new MollieCancelAuthorizationRequestEvent($contract);

        $this->cancelService->method('cancel')->willThrowException(
            new \DomainException('already captured'),
        );

        $this->handler->handle($event);

        self::assertFalse($event->isSuccess());
        self::assertSame('state_error', $event->getErrorCode());
    }
}
