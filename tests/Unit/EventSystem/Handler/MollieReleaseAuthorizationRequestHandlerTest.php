<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\EventSystem\Handler;

use DomainException;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\EventSystem\Handler\HandlerInterface;
use OxidEsales\Payments\Mollie\Admin\AdminValidationFeedbackInterface;
use OxidEsales\Payments\Mollie\EventSystem\Event\MollieReleaseAuthorizationRequestEvent;
use OxidEsales\Payments\Mollie\EventSystem\Handler\MollieReleaseAuthorizationRequestHandler;
use OxidEsales\Payments\Mollie\Service\LanguageTranslatorInterface;
use OxidEsales\Payments\Mollie\Service\ReleaseAuthorizationServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(MollieReleaseAuthorizationRequestHandler::class)]
final class MollieReleaseAuthorizationRequestHandlerTest extends TestCase
{
    private ReleaseAuthorizationServiceInterface&MockObject $releaseService;

    protected function setUp(): void
    {
        $this->releaseService = $this->createMock(ReleaseAuthorizationServiceInterface::class);
    }

    public function testImplementsHandlerInterfaceAndHandlesTheReleaseEvent(): void
    {
        $handler = new MollieReleaseAuthorizationRequestHandler($this->releaseService);

        self::assertInstanceOf(HandlerInterface::class, $handler);
        self::assertSame(
            MollieReleaseAuthorizationRequestEvent::class,
            MollieReleaseAuthorizationRequestHandler::getHandledEventClass(),
        );
    }

    public function testHandle_DelegatesToTheReleaseService(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $event = new MollieReleaseAuthorizationRequestEvent($contract, 'merchant release');

        $this->releaseService->expects(self::once())->method('release')->with($contract, 'merchant release');

        (new MollieReleaseAuthorizationRequestHandler($this->releaseService))->handle($event);

        self::assertTrue($event->isSuccess());
    }

    public function testHandle_IgnoresOtherEventTypes(): void
    {
        $this->releaseService->expects(self::never())->method('release');

        (new MollieReleaseAuthorizationRequestHandler($this->releaseService))->handle(new \stdClass());
    }

    public function testHandle_OnFailure_TellsTheAdmin(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getOrderId')->willReturn('order-5');
        $event = new MollieReleaseAuthorizationRequestEvent($contract);

        $this->releaseService->method('release')->willThrowException(new DomainException('cannot release'));

        $feedback = $this->createMock(AdminValidationFeedbackInterface::class);
        $translator = $this->createStub(LanguageTranslatorInterface::class);
        $translator->method('translateString')->with('MOLLIE_ADMIN_RELEASE_FAILED')->willReturn('Release failed');
        $feedback->expects(self::once())->method('rejectWithMessage')->with('order-5', 'Release failed');

        (new MollieReleaseAuthorizationRequestHandler($this->releaseService, $feedback, $translator))->handle($event);

        self::assertFalse($event->isSuccess());
        self::assertSame('state_error', $event->getErrorCode());
    }
}
