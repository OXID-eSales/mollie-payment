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
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieCaptureDto;
use OxidEsales\Payments\Mollie\Adapter\Exception\CaptureNotSupportedException;
use OxidEsales\Payments\Mollie\Admin\AdminValidationFeedbackInterface;
use OxidEsales\Payments\Mollie\EventSystem\Event\MollieCaptureRequestEvent;
use OxidEsales\Payments\Mollie\EventSystem\Handler\MollieCaptureRequestHandler;
use OxidEsales\Payments\Mollie\Service\CaptureServiceInterface;
use OxidEsales\Payments\Mollie\Service\LanguageTranslatorInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(MollieCaptureRequestHandler::class)]
final class MollieCaptureRequestHandlerTest extends TestCase
{
    private CaptureServiceInterface&MockObject $captureService;
    private MollieCaptureRequestHandler $handler;

    protected function setUp(): void
    {
        $this->captureService = $this->createMock(CaptureServiceInterface::class);
        $this->handler = new MollieCaptureRequestHandler($this->captureService);
    }

    public function testImplementsHandlerInterface(): void
    {
        self::assertInstanceOf(HandlerInterface::class, $this->handler);
    }

    public function testGetHandledEventClass(): void
    {
        self::assertSame(MollieCaptureRequestEvent::class, MollieCaptureRequestHandler::getHandledEventClass());
    }

    public function testHandle_DelegatesToCaptureService(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $event = new MollieCaptureRequestEvent($contract, 50.0, null, 'contract-1:capture:50.00');

        $this->captureService->expects(self::once())
            ->method('capture')
            ->with($contract, 50.0, 'contract-1:capture:50.00')
            ->willReturn(new MollieCaptureDto('cp_1', 'tr_1', MollieAmountDto::fromComponents('EUR', 50.0), 'succeeded'));

        $this->handler->handle($event);

        self::assertTrue($event->isSuccess());
        self::assertSame('cp_1', $event->getCaptureId());
    }

    public function testHandle_IgnoresOtherEventTypes(): void
    {
        $this->captureService->expects(self::never())->method('capture');

        $this->handler->handle(new \stdClass());
    }

    public function testHandle_OnCaptureNotSupported_SetsErrorResult(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $event = new MollieCaptureRequestEvent($contract);

        $this->captureService->method('capture')->willThrowException(
            new CaptureNotSupportedException('method does not support two-step capture'),
        );

        $this->handler->handle($event);

        self::assertFalse($event->isSuccess());
        self::assertSame('capture_failed', $event->getErrorCode());
    }

    // ---- MOL-32 S5: a refused capture must reach the admin, not be swallowed (MOL-30 channel) ----

    public function testHandle_OnFailure_TellsTheAdminOnTheNextRender(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getOrderId')->willReturn('order-77');
        $event = new MollieCaptureRequestEvent($contract);

        $this->captureService->method('capture')->willThrowException(
            // e.g. Mollie 422 "The pre-authorized payment has a capture marked as final".
            new \RuntimeException('capture marked as final'),
        );

        $feedback = $this->createMock(AdminValidationFeedbackInterface::class);
        $translator = $this->createStub(LanguageTranslatorInterface::class);
        $translator->method('translateString')->with('MOLLIE_ADMIN_CAPTURE_FAILED')->willReturn('Capture failed message');

        $feedback->expects(self::once())
            ->method('rejectWithMessage')
            ->with('order-77', 'Capture failed message');

        $handler = new MollieCaptureRequestHandler($this->captureService, $feedback, $translator);
        $handler->handle($event);

        self::assertFalse($event->isSuccess());
    }

    public function testHandle_OnSuccess_TellsTheAdminNothing(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $event = new MollieCaptureRequestEvent($contract, 50.0);
        $this->captureService->method('capture')->willReturn(
            new MollieCaptureDto('cp_ok', 'tr_1', MollieAmountDto::fromComponents('EUR', 50.0), 'succeeded'),
        );

        $feedback = $this->createMock(AdminValidationFeedbackInterface::class);
        $feedback->expects(self::never())->method('rejectWithMessage');

        (new MollieCaptureRequestHandler(
            $this->captureService,
            $feedback,
            $this->createStub(LanguageTranslatorInterface::class),
        ))->handle($event);

        self::assertTrue($event->isSuccess());
    }
}
