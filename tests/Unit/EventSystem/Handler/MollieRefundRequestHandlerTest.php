<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\EventSystem\Handler;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\EventSystem\Handler\HandlerInterface;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieAmountDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieRefundDto;
use OxidEsales\Payments\Mollie\EventSystem\Event\MollieRefundRequestEvent;
use OxidEsales\Payments\Mollie\EventSystem\Handler\MollieRefundRequestHandler;
use OxidEsales\Payments\Mollie\Service\RefundServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(MollieRefundRequestHandler::class)]
final class MollieRefundRequestHandlerTest extends TestCase
{
    private RefundServiceInterface&MockObject $refundService;
    private MollieRefundRequestHandler $handler;

    protected function setUp(): void
    {
        $this->refundService = $this->createMock(RefundServiceInterface::class);
        $this->handler = new MollieRefundRequestHandler($this->refundService);
    }

    public function testImplementsHandlerInterface(): void
    {
        self::assertInstanceOf(HandlerInterface::class, $this->handler);
    }

    public function testGetHandledEventClass(): void
    {
        self::assertSame(MollieRefundRequestEvent::class, MollieRefundRequestHandler::getHandledEventClass());
    }

    public function testHandle_DelegatesToRefundService(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $event = new MollieRefundRequestEvent($contract, 25.0, 'requested by admin', 'contract-1:refund:25.00');

        $this->refundService->expects(self::once())
            ->method('refund')
            ->with($contract, 25.0, 'requested by admin', 'contract-1:refund:25.00')
            ->willReturn(new MollieRefundDto('re_1', 'tr_1', MollieAmountDto::fromComponents('EUR', 25.0), 'pending'));

        $this->handler->handle($event);

        self::assertTrue($event->isSuccess());
        self::assertSame('re_1', $event->getRefundId());
    }

    public function testHandle_IgnoresOtherEventTypes(): void
    {
        $this->refundService->expects(self::never())->method('refund');

        $this->handler->handle(new \stdClass());
    }

    public function testHandle_OnValidationError_SetsErrorResult(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $event = new MollieRefundRequestEvent($contract, 999.0);

        $this->refundService->method('refund')->willThrowException(
            new \InvalidArgumentException('amount exceeds refundable'),
        );

        $this->handler->handle($event);

        self::assertFalse($event->isSuccess());
        self::assertSame('validation_error', $event->getErrorCode());
    }

    public function testHandle_OnDomainError_SetsErrorResult(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $event = new MollieRefundRequestEvent($contract);

        $this->refundService->method('refund')->willThrowException(
            new \DomainException('not fulfilled'),
        );

        $this->handler->handle($event);

        self::assertFalse($event->isSuccess());
        self::assertSame('state_error', $event->getErrorCode());
    }
}
