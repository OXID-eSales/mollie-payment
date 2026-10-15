<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Service;

use OxidEsales\Eshop\Application\Model\Basket;
use OxidEsales\Eshop\Application\Model\User;
use OxidEsales\PaymentBase\Adapter\Exception\ShopOrderException;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\EventSystem\EventDispatcherInterface;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\EventSystem\Event\MollieCheckoutSessionRequestEvent;
use OxidEsales\Payments\Mollie\Service\BasketBuyabilityValidator;
use OxidEsales\Payments\Mollie\Service\BuyabilityFailure;
use OxidEsales\Payments\Mollie\Service\CheckoutSessionStarter;
use OxidEsales\Payments\Mollie\Service\CheckoutStartRequest;
use OxidEsales\Payments\Mollie\Service\CheckoutUserDataGateInterface;
use OxidEsales\Payments\Mollie\Service\InFlightCheckoutReplayInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Review 2026-10-14 finding A-H1 — the checkout entry ladder (MOL-22 buyability, MOL-15 user-data
 * gate, MOL-18 in-flight replay, event dispatch) lived twice: in MollieOrderController::execute()
 * and, PARTIALLY, in MolliePaymentHandler::processPayment() — the OPC path had silently lost the
 * MOL-15 and MOL-18 guards. This service is the single ladder both entry points consume; these
 * tests pin its order and outcomes.
 */
#[CoversClass(CheckoutSessionStarter::class)]
final class CheckoutSessionStarterTest extends TestCase
{
    public function testANotOrderableItemStopsTheLadderBeforeAnyOtherGuardOrDispatch(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->never())->method('dispatch');
        $gate = $this->createMock(CheckoutUserDataGateInterface::class);
        $gate->expects($this->never())->method('problemsFor');

        $starter = $this->starter(
            dispatcher: $dispatcher,
            buyability: $this->buyabilityFailing(),
            gate: $gate,
        );

        $outcome = $starter->start($this->request());

        self::assertTrue($outcome->isNotOrderable());
        self::assertNotNull($outcome->notOrderable);
    }

    public function testBadUserDataIsNamedBeforeAnyReplayOrDispatch(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->never())->method('dispatch');
        $replay = $this->createMock(InFlightCheckoutReplayInterface::class);
        $replay->expects($this->never())->method('checkoutUrl');

        $starter = $this->starter(
            dispatcher: $dispatcher,
            gate: $this->gateWithProblems(['MOLLIE_VALIDATION_FIELD_INVALID: oxfname']),
            replay: $replay,
        );

        $outcome = $starter->start($this->request());

        self::assertSame(['MOLLIE_VALIDATION_FIELD_INVALID: oxfname'], $outcome->userDataProblems);
    }

    public function testASecondClickRejoinsTheAttemptAlreadyInFlight(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->never())->method('dispatch');
        $replay = $this->createMock(InFlightCheckoutReplayInterface::class);
        $replay->method('checkoutUrl')->willReturn('https://www.mollie.com/checkout/tr_inflight');

        $starter = $this->starter(dispatcher: $dispatcher, replay: $replay);

        $outcome = $starter->start($this->request());

        self::assertTrue($outcome->isRedirect());
        self::assertSame('https://www.mollie.com/checkout/tr_inflight', $outcome->checkoutUrl);
    }

    public function testAHeadlessStartSkipsTheSessionBoundGuardsButKeepsBuyability(): void
    {
        $gate = $this->createMock(CheckoutUserDataGateInterface::class);
        $gate->expects($this->never())->method('problemsFor');
        $replay = $this->createMock(InFlightCheckoutReplayInterface::class);
        $replay->expects($this->never())->method('checkoutUrl');

        $starter = $this->starter(
            buyability: $this->buyabilityFailing(),
            gate: $gate,
            replay: $replay,
        );

        $outcome = $starter->start($this->request(headless: true));

        self::assertTrue($outcome->isNotOrderable(), 'buyability still guards the headless start');
    }

    public function testAFreshStartDispatchesOneEventAndAnswersTheCheckoutUrlAndContractId(): void
    {
        $contract = $this->createStub(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('ctr-7');

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->once())->method('dispatch')
            ->with($this->callback(function (MollieCheckoutSessionRequestEvent $event) use ($contract): bool {
                $context = $event->getContext();
                self::assertSame(MollieDefinitions::PAYMENT_ID, $context->get('paymentId'));
                self::assertSame('user-1', $context->get('userId'));
                self::assertSame('sess-1', $context->get('sessionId'));
                self::assertSame(['payment_authorized'], $context->get('conditionTypes'));
                self::assertSame('ideal', $context->get('selectedMethod'));
                self::assertSame('card-token', $context->get('cardToken'));
                $context->set('checkoutUrl', 'https://www.mollie.com/checkout/tr_new');
                $context->setContract($contract);

                return true;
            }));

        $starter = $this->starter(dispatcher: $dispatcher);

        $outcome = $starter->start($this->request());

        self::assertTrue($outcome->isRedirect());
        self::assertSame('https://www.mollie.com/checkout/tr_new', $outcome->checkoutUrl);
        self::assertSame('ctr-7', $outcome->contractId);
    }

    public function testAHeadlessStartCarriesBasketIdAndReturnUrlIntoTheContext(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->once())->method('dispatch')
            ->with($this->callback(function (MollieCheckoutSessionRequestEvent $event): bool {
                $context = $event->getContext();
                self::assertTrue($context->get('headless'));
                self::assertSame('ub-9', $context->get('basketId'));
                self::assertSame('https://app.example.com/return', $context->get('returnUrl'));
                $context->set('checkoutUrl', 'https://www.mollie.com/checkout/tr_h');

                return true;
            }));

        $starter = $this->starter(dispatcher: $dispatcher);

        $outcome = $starter->start($this->request(
            headless: true,
            basketId: 'ub-9',
            returnUrl: 'https://app.example.com/return',
        ));

        self::assertTrue($outcome->isRedirect());
    }

    public function testANotOrderableDispatchFailureIsNamedNotGenericized(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willThrowException(new ShopOrderException(
            message: 'ERROR_MESSAGE_OUTOFSTOCK_OUTOFSTOCK',
            errorCode: 'article_not_buyable',
        ));

        $outcome = $this->starter(dispatcher: $dispatcher)->start($this->request());

        self::assertTrue($outcome->isNotOrderable(), 'core refusing an item mid-chain is a named failure');
    }

    public function testAnyOtherDispatchFailureIsUnavailable(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willThrowException(new RuntimeException('mollie down'));

        $outcome = $this->starter(dispatcher: $dispatcher)->start($this->request());

        self::assertTrue($outcome->isUnavailable());
    }

    public function testADispatchWithoutACheckoutUrlIsUnavailableButNamesTheContract(): void
    {
        $contract = $this->createStub(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('ctr-stuck');

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(
            function (MollieCheckoutSessionRequestEvent $event) use ($contract): MollieCheckoutSessionRequestEvent {
                $event->getContext()->setContract($contract);

                return $event;
            },
        );

        $outcome = $this->starter(dispatcher: $dispatcher)->start($this->request());

        self::assertTrue($outcome->isUnavailable());
        self::assertSame('ctr-stuck', $outcome->contractId);
    }

    private function starter(
        ?EventDispatcherInterface $dispatcher = null,
        ?BasketBuyabilityValidator $buyability = null,
        ?CheckoutUserDataGateInterface $gate = null,
        ?InFlightCheckoutReplayInterface $replay = null,
    ): CheckoutSessionStarter {
        return new CheckoutSessionStarter(
            $dispatcher ?? $this->createStub(EventDispatcherInterface::class),
            $buyability ?? $this->buyabilityPassing(),
            $gate ?? $this->gateWithProblems([]),
            $replay,
        );
    }

    private function request(bool $headless = false, ?string $basketId = null, ?string $returnUrl = null): CheckoutStartRequest
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn('user-1');

        return new CheckoutStartRequest(
            user: $user,
            basket: $this->createStub(Basket::class),
            sessionId: 'sess-1',
            selectedMethod: 'ideal',
            cardToken: 'card-token',
            headless: $headless,
            basketId: $basketId,
            returnUrl: $returnUrl,
        );
    }

    private function buyabilityPassing(): BasketBuyabilityValidator
    {
        $validator = $this->createStub(BasketBuyabilityValidator::class);
        $validator->method('validate')->willReturn([]);

        return $validator;
    }

    private function buyabilityFailing(): BasketBuyabilityValidator
    {
        $validator = $this->createStub(BasketBuyabilityValidator::class);
        $validator->method('validate')->willReturn([new BuyabilityFailure('shirt-1', 'Shirt')]);

        return $validator;
    }

    /**
     * @param list<string> $problems
     */
    private function gateWithProblems(array $problems): CheckoutUserDataGateInterface
    {
        $gate = $this->createStub(CheckoutUserDataGateInterface::class);
        $gate->method('problemsFor')->willReturn($problems);

        return $gate;
    }
}
