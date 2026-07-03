<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Controller;

use OxidEsales\PaymentBase\EventSystem\EventDispatcherInterface;
use OxidEsales\Payments\Mollie\Controller\PaymentController;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\EventSystem\Event\MollieCheckoutSessionRequestEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(PaymentController::class)]
final class MolliePaymentControllerTest extends TestCase
{
    public function testExecuteWhenMollieMethodSelectedDispatchesCheckoutSessionRequestEvent(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf(MollieCheckoutSessionRequestEvent::class))
            ->willReturnCallback(function (MollieCheckoutSessionRequestEvent $event) {
                $event->getContext()->set('checkoutUrl', 'https://mollie.test/checkout/tr_1');
                return $event;
            });

        $controller = $this->controller(MollieDefinitions::PAYMENT_ID, $dispatcher);

        $controller->execute();

        self::assertSame(['https://mollie.test/checkout/tr_1'], $controller->redirectedTo);
        self::assertFalse($controller->delegatedToParent);
    }

    public function testExecuteWhenNonMollieMethodDelegatesToParent(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::never())->method('dispatch');

        $controller = $this->controller('oxidcashondel', $dispatcher);

        $controller->execute();

        self::assertTrue($controller->delegatedToParent);
        self::assertSame([], $controller->redirectedTo);
    }

    public function testExecuteWhenDispatcherUnavailableDelegatesToParent(): void
    {
        $controller = $this->controller(MollieDefinitions::PAYMENT_ID, null);

        $controller->execute();

        self::assertTrue($controller->delegatedToParent);
    }

    public function testExecuteWhenDispatchThrowsDelegatesToParent(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willThrowException(new RuntimeException('boom'));

        $controller = $this->controller(MollieDefinitions::PAYMENT_ID, $dispatcher);

        $controller->execute();

        self::assertTrue($controller->delegatedToParent);
        self::assertSame([], $controller->redirectedTo);
    }

    public function testExecuteWhenUserDataInvalidBlocksCheckoutDispatch(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::never())->method('dispatch');

        $controller = new TestableMolliePaymentController(
            MollieDefinitions::PAYMENT_ID,
            $dispatcher,
            userDataValid: false,
        );

        $controller->execute();

        self::assertTrue($controller->invalidUserDataErrorShown);
        self::assertTrue($controller->delegatedToParent);
        self::assertSame([], $controller->redirectedTo);
    }

    public function testBuildCheckoutContext_IncludesTheSelectedMollieMethod(): void
    {
        $controller = new TestableMolliePaymentController(
            MollieDefinitions::PAYMENT_ID,
            null,
            true,
            'ideal',
        );

        $context = $controller->realBuildCheckoutContext(MollieDefinitions::PAYMENT_ID);

        self::assertSame('ideal', $context->get('mollieMethod'));
    }

    public function testBuildCheckoutContext_WithNoMethodSelected_IsNull(): void
    {
        $controller = new TestableMolliePaymentController(MollieDefinitions::PAYMENT_ID, null);

        $context = $controller->realBuildCheckoutContext(MollieDefinitions::PAYMENT_ID);

        self::assertNull($context->get('mollieMethod'));
    }

    private function controller(
        string $paymentId,
        ?EventDispatcherInterface $dispatcher,
    ): TestableMolliePaymentController {
        return new TestableMolliePaymentController($paymentId, $dispatcher);
    }
}
