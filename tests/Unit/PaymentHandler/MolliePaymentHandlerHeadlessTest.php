<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\PaymentHandler;

use OxidEsales\Eshop\Application\Model\Basket;
use OxidEsales\Eshop\Application\Model\User;
use OxidEsales\PaymentBase\Adapter\ContractFirstPaymentHandlerInterface;
use OxidEsales\PaymentBase\Adapter\PaymentContext;
use OxidEsales\PaymentBase\Adapter\PaymentContextInterface;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use OxidEsales\PaymentBase\EventSystem\Event\EventInterface;
use OxidEsales\PaymentBase\EventSystem\EventDispatcherInterface;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\EventSystem\Event\MollieCheckoutSessionRequestEvent;
use OxidEsales\Payments\Mollie\PaymentHandler\MolliePaymentHandler;
use OxidEsales\Payments\Mollie\Service\BasketBuyabilityValidator;
use PHPUnit\Framework\TestCase;

/**
 * The handler with its two PHP-session seams recorded instead of executed.
 */
final class SessionRecordingMollieHandler extends MolliePaymentHandler
{
    /** @var list<string> */
    public array $sessionCalls = [];

    protected function prepareOxidBasket(PaymentContextInterface $context): void
    {
        $this->sessionCalls[] = 'prepareOxidBasket';
    }

    protected function sessionId(): string
    {
        $this->sessionCalls[] = 'sessionId';

        return 'sid-twig';
    }
}

/**
 * GRAPH-QL / MS1 — the handler payment-base's HeadlessCheckoutService drives.
 * A headless PaymentContext (metadata.headless) has no PHP session: basket and
 * user come with the context, the event context names the persisted basket and
 * the client's return URL, and nothing is read from or written to the session.
 * The Twig / OPC path is byte-identical to before.
 */
final class MolliePaymentHandlerHeadlessTest extends TestCase
{
    private ?EventContext $dispatched = null;

    public function testDeclaresItselfContractFirst(): void
    {
        self::assertInstanceOf(ContractFirstPaymentHandlerInterface::class, $this->handler());
    }

    public function testHeadlessStartNeverTouchesTheSessionAndNamesBasketSessionAndReturnUrl(): void
    {
        $handler = $this->handler();

        $result = $handler->processPayment($this->context(headless: true, metadata: ['mollieMethod' => 'ideal']));

        self::assertTrue($result->isSuccess());
        self::assertSame('contract-1', $result->getContractId());
        self::assertSame('https://www.mollie.com/checkout/tr_1', $result->getMetadata()['redirectUrl'] ?? null);
        self::assertSame([], $handler->sessionCalls, 'no session read or write on the headless path');

        $context = $this->dispatched;
        self::assertNotNull($context);
        self::assertSame('headless:ub-1', $context->get('sessionId'));
        self::assertSame('ub-1', $context->get('basketId'));
        self::assertTrue($context->get('headless'));
        self::assertSame('https://app.example.com/return', $context->get('returnUrl'));
        self::assertSame('ideal', $context->get('selectedMethod'), 'the client\'s method hint reaches the chain');
        self::assertSame(MollieDefinitions::PAYMENT_ID, $context->get('paymentId'));
        self::assertSame('user-1', $context->get('userId'));
    }

    public function testTheTwigAndOpcPathStillPreparesTheBasketAndReadsTheSessionId(): void
    {
        $handler = $this->handler();

        $result = $handler->processPayment($this->context(headless: false));

        self::assertTrue($result->isSuccess());
        self::assertSame(['prepareOxidBasket', 'sessionId'], $handler->sessionCalls);
        self::assertSame('sid-twig', $this->dispatched?->get('sessionId'));
        self::assertNull($this->dispatched?->get('headless'));
        self::assertNull($this->dispatched?->get('basketId'));
    }

    public function testAnUnsupportedUiModeIsRefusedBeforeAnythingIsCreated(): void
    {
        $handler = $this->handler();

        $result = $handler->processPayment($this->context(headless: true, metadata: ['uiMode' => 'embedded']));

        self::assertFalse($result->isSuccess());
        self::assertSame(MolliePaymentHandler::ERROR_UI_MODE_UNSUPPORTED, $result->getErrorCode());
        self::assertNull($this->dispatched, 'no event, no contract, no Mollie payment');
        self::assertSame([], $handler->sessionCalls);
    }

    public function testHostedIsTheDefaultUiMode(): void
    {
        $result = $this->handler()->processPayment($this->context(headless: true, metadata: []));

        self::assertTrue($result->isSuccess());
    }

    private function handler(): SessionRecordingMollieHandler
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(
            function (EventInterface $event): EventInterface {
                if ($event instanceof MollieCheckoutSessionRequestEvent) {
                    $context = $event->getContext();
                    $contract = $this->createMock(PaymentContractInterface::class);
                    $contract->method('getId')->willReturn('contract-1');
                    $context->setContract($contract);
                    $context->set('checkoutUrl', 'https://www.mollie.com/checkout/tr_1');
                    $this->dispatched = $context;
                }

                return $event;
            }
        );
        $buyability = $this->createMock(BasketBuyabilityValidator::class);
        $buyability->method('validate')->willReturn([]);

        return new SessionRecordingMollieHandler($dispatcher, null, null, $buyability);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function context(bool $headless, array $metadata = []): PaymentContext
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('user-1');

        return new PaymentContext(
            basket: $this->createMock(Basket::class),
            user: $user,
            paymentMethodId: MollieDefinitions::PAYMENT_ID,
            providerTransactionId: null,
            returnUrl: 'https://app.example.com/return',
            cancelUrl: 'https://app.example.com/cancel',
            metadata: $headless
                ? array_merge($metadata, ['headless' => true, 'basketId' => 'ub-1', 'sessionId' => 'headless:ub-1'])
                : $metadata
        );
    }
}
