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

/**
 * Story 4: the controller's redirect leg. Story 3's handler is what actually populates (or
 * omits) `checkoutUrl` on the context — these tests exercise the controller's reaction to both
 * outcomes without depending on the handler chain.
 */
#[CoversClass(PaymentController::class)]
final class MollieRedirectTest extends TestCase
{
    public function testExecuteRedirectsToStoredCheckoutUrl(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(
            function (MollieCheckoutSessionRequestEvent $event) {
                $event->getContext()->set('checkoutUrl', 'https://mollie.test/checkout/tr_redirect');
                return $event;
            },
        );

        $controller = new TestableMolliePaymentController(MollieDefinitions::PAYMENT_ID, $dispatcher);

        $controller->execute();

        self::assertSame(['https://mollie.test/checkout/tr_redirect'], $controller->redirectedTo);
        self::assertFalse($controller->unavailableErrorShown);
    }

    public function testExecuteWhenNoCheckoutUrlShowsErrorAndDelegatesToParent(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        // MollieCheckoutSessionHandler already failed the contract in this scenario (Story 3);
        // the context simply carries no checkoutUrl back to the controller.
        $dispatcher->method('dispatch')->willReturnArgument(0);

        $controller = new TestableMolliePaymentController(MollieDefinitions::PAYMENT_ID, $dispatcher);

        $controller->execute();

        self::assertSame([], $controller->redirectedTo);
        self::assertTrue($controller->unavailableErrorShown);
        self::assertTrue($controller->delegatedToParent);
    }
}
