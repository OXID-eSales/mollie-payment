<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\PaymentHandler;

use OxidEsales\PaymentBase\Adapter\PaymentContextInterface;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use OxidEsales\PaymentBase\EventSystem\Event\EventInterface;
use OxidEsales\PaymentBase\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentBase\Service\IframeCheckoutSettingsInterface;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\EventSystem\Event\MollieCheckoutSessionRequestEvent;
use OxidEsales\Payments\Mollie\PaymentHandler\MolliePaymentHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * MolliePaymentHandler bridges Mollie to one-page-checkout's PaymentHandlerInterface so OPC's
 * PaymentHandlerRegistry can route `oe_payments_mollie` payments (previously "no payment handler
 * found"). It reuses the standard checkout-session event, so these tests drive the
 * dispatch → checkoutUrl → PaymentHandlerResult contract through Registry-free seams.
 */
#[CoversClass(MolliePaymentHandler::class)]
final class MolliePaymentHandlerTest extends TestCase
{
    public function testSupportsOnlyTheMolliePaymentMethod(): void
    {
        $handler = new TestableMolliePaymentHandler($this->dispatcherThatMutates(static fn () => null));

        self::assertTrue($handler->supports(MollieDefinitions::PAYMENT_ID));
        self::assertFalse($handler->supports('oxidcashondel'));
    }

    public function testExposesItsIdentityAndFrontendConfig(): void
    {
        $handler = new TestableMolliePaymentHandler($this->dispatcherThatMutates(static fn () => null));

        self::assertSame('mollie', $handler->getId());
        self::assertSame('Mollie Payment', $handler->getName());
        self::assertSame(
            ['type' => 'mollie', 'renderMode' => 'redirect', 'requiresRedirect' => true],
            $handler->getFrontendConfig(),
        );
    }

    /**
     * IFRAME-03: with the payment-base iframe flag OFF the frontend config is unchanged and no
     * fallback log is emitted (the flag simply does not apply to a redirect-only PSP).
     */
    public function testFrontendConfigStaysRedirectAndIsSilentWhenIframeFlagOff(): void
    {
        $iframe = $this->createMock(IframeCheckoutSettingsInterface::class);
        $iframe->method('isEnabled')->willReturn(false);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('info');

        $handler = new TestableMolliePaymentHandler(
            $this->dispatcherThatMutates(static fn () => null),
            $logger,
            $iframe,
        );

        self::assertSame(
            ['type' => 'mollie', 'renderMode' => 'redirect', 'requiresRedirect' => true],
            $handler->getFrontendConfig(),
        );
    }

    /**
     * IFRAME-03: with the flag ON, Mollie still advertises redirect (its hosted page forbids
     * framing) and logs the fallback exactly once, no matter how many times the config is read.
     */
    public function testFrontendConfigFallsBackToRedirectAndLogsOnceWhenIframeFlagOn(): void
    {
        $iframe = $this->createMock(IframeCheckoutSettingsInterface::class);
        $iframe->method('isEnabled')->willReturn(true);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info');

        $handler = new TestableMolliePaymentHandler(
            $this->dispatcherThatMutates(static fn () => null),
            $logger,
            $iframe,
        );

        $config = $handler->getFrontendConfig();
        $handler->getFrontendConfig(); // second read must not log again

        self::assertSame('redirect', $config['renderMode']);
        self::assertTrue($config['requiresRedirect']);
    }

    public function testProcessPaymentReturnsTheMollieCheckoutUrlAsRedirect(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('ct_123');

        $dispatcher = $this->dispatcherThatMutates(static function (EventContext $context) use ($contract): void {
            // Simulate MollieCheckoutSessionHandler: create the payment, stash the checkout URL.
            $context->setContract($contract);
            $context->set('checkoutUrl', 'https://mollie.test/checkout/tr_1');
        });

        $result = (new TestableMolliePaymentHandler($dispatcher))
            ->processPayment($this->createMock(PaymentContextInterface::class));

        self::assertTrue($result->isSuccess());
        self::assertSame('ct_123', $result->getContractId());
        self::assertTrue($result->getMetadataValue('requiresRedirect'));
        self::assertSame('https://mollie.test/checkout/tr_1', $result->getMetadataValue('redirectUrl'));
    }

    public function testProcessPaymentFailsWhenNoCheckoutUrlIsReturned(): void
    {
        $dispatcher = $this->dispatcherThatMutates(static fn (EventContext $context) => null);

        $result = (new TestableMolliePaymentHandler($dispatcher))
            ->processPayment($this->createMock(PaymentContextInterface::class));

        self::assertFalse($result->isSuccess());
        self::assertSame('MOLLIE_NO_CHECKOUT_URL', $result->getErrorCode());
    }

    public function testProcessPaymentSwallowsDispatchFailuresAsAnErrorResult(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willThrowException(new RuntimeException('boom'));

        $result = (new TestableMolliePaymentHandler($dispatcher))
            ->processPayment($this->createMock(PaymentContextInterface::class));

        self::assertFalse($result->isSuccess());
        self::assertSame('MOLLIE_PAYMENT_FAILED', $result->getErrorCode());
    }

    /**
     * A dispatcher mock whose dispatch() lets the test mutate the checkout EventContext (as the
     * real handler chain would) and returns the event, matching EventDispatcherInterface.
     */
    private function dispatcherThatMutates(callable $mutate): EventDispatcherInterface
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(
            static function (EventInterface $event) use ($mutate): EventInterface {
                if ($event instanceof MollieCheckoutSessionRequestEvent) {
                    $mutate($event->getContext());
                }

                return $event;
            }
        );

        return $dispatcher;
    }
}
