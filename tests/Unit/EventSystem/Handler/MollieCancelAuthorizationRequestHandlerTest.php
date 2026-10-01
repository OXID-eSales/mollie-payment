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
use OxidEsales\Payments\Mollie\Admin\AdminActionFailureReporter;
use OxidEsales\Payments\Mollie\Admin\AdminValidationFeedbackInterface;
use OxidEsales\Payments\Mollie\Service\LanguageTranslatorInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(MollieCancelAuthorizationRequestHandler::class)]
final class MollieCancelAuthorizationRequestHandlerTest extends TestCase
{
    private CancelAuthorizationServiceInterface&MockObject $cancelService;
    private AdminValidationFeedbackInterface&MockObject $feedback;
    private MollieCancelAuthorizationRequestHandler $handler;

    protected function setUp(): void
    {
        $this->cancelService = $this->createMock(CancelAuthorizationServiceInterface::class);
        $this->feedback = $this->createMock(AdminValidationFeedbackInterface::class);
        $translator = $this->createMock(LanguageTranslatorInterface::class);
        $translator->method('translateString')->willReturnArgument(0);
        $this->handler = new MollieCancelAuthorizationRequestHandler(
            $this->cancelService,
            new AdminActionFailureReporter($this->feedback, $translator),
        );
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

    public function testHandle_OnDomainError_SetsErrorResult_AndReportsItToTheOperator(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getOrderId')->willReturn('order-1');
        $event = new MollieCancelAuthorizationRequestEvent($contract);

        $this->cancelService->method('cancel')->willThrowException(
            new \DomainException('already captured'),
        );
        $this->feedback->expects(self::once())->method('rejectWithMessage')->with('order-1', 'already captured');

        $this->handler->handle($event);

        self::assertFalse($event->isSuccess());
        self::assertSame('state_error', $event->getErrorCode());
    }

    public function testHandle_OnMollieRefusal_ReportsItToTheOperator(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getOrderId')->willReturn('order-2');
        $event = new MollieCancelAuthorizationRequestEvent($contract);

        $this->cancelService->method('cancel')->willThrowException(
            new \RuntimeException('Payment can no longer be released'),
        );
        $this->feedback->expects(self::once())->method('rejectWithMessage')
            ->with('order-2', 'Payment can no longer be released');

        $this->handler->handle($event);

        self::assertSame('cancel_failed', $event->getErrorCode());
    }

    public function testHandle_OnSuccess_ReportsNothing(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getOrderId')->willReturn('order-3');
        $this->feedback->expects(self::never())->method('rejectWithMessage');

        $this->handler->handle(new MollieCancelAuthorizationRequestEvent($contract));
    }
}
