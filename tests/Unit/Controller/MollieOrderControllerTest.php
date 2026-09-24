<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Controller;

use OxidEsales\PaymentBase\Adapter\Exception\ShopOrderException;
use OxidEsales\Eshop\Application\Model\Basket;
use OxidEsales\Eshop\Core\Price;
use OxidEsales\PaymentBase\Adapter\SessionAdapterInterface;
use OxidEsales\PaymentBase\Checkout\InFlightCheckoutAttemptResolverInterface;
use OxidEsales\Payments\Mollie\Service\InFlightCheckoutReplay;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Controller\CheckoutReturnResponder;
use OxidEsales\PaymentBase\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Return\ReturnResolverInterface;
use OxidEsales\PaymentBase\Service\TokenServiceInterface;
use OxidEsales\Payments\Mollie\Controller\MollieOrderController;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\EventSystem\Event\MollieCheckoutSessionRequestEvent;
use OxidEsales\Payments\Mollie\Tests\Unit\Support\PreloadsModuleClassChain;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(MollieOrderController::class)]
final class MollieOrderControllerTest extends TestCase
{
    use PreloadsModuleClassChain;

    // TestableMollieOrderController extends the concrete MollieOrderController chain member — build
    // the chain first so instantiating it does not re-enter ModuleChainsGenerator (see trait).
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::preloadModuleClassChain('order');
    }

    public function testExecuteWhenMollieSelectedDispatchesCheckoutSessionRequestEventAndRedirects(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf(MollieCheckoutSessionRequestEvent::class))
            ->willReturnCallback(function (MollieCheckoutSessionRequestEvent $event) {
                $event->getContext()->set('checkoutUrl', 'https://mollie.test/checkout/tr_1');
                return $event;
            });

        $controller = $this->executeController(MollieDefinitions::PAYMENT_ID, $dispatcher);

        $result = $controller->execute();

        self::assertNull($result);
        self::assertSame(['https://mollie.test/checkout/tr_1'], $controller->redirectedTo);
        self::assertFalse($controller->delegatedToParent);
        self::assertFalse($controller->unavailableErrorShown);
    }

    public function testExecuteWhenNonMollieMethodDelegatesToParentSoTheOrderFinalizesNormally(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::never())->method('dispatch');

        $controller = $this->executeController('oxidcashondel', $dispatcher);

        $controller->execute();

        self::assertTrue($controller->delegatedToParent);
        self::assertSame([], $controller->redirectedTo);
    }

    public function testExecuteWhenDispatcherUnavailableShowsErrorAndDoesNotFinalize(): void
    {
        $controller = $this->executeController(MollieDefinitions::PAYMENT_ID, null);

        $result = $controller->execute();

        self::assertSame('payment', $result);
        self::assertTrue($controller->unavailableErrorShown);
        self::assertFalse($controller->delegatedToParent);
        self::assertSame([], $controller->redirectedTo);
    }

    public function testExecuteWhenDispatchThrowsShowsErrorAndDoesNotFinalize(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willThrowException(new RuntimeException('boom'));

        $controller = $this->executeController(MollieDefinitions::PAYMENT_ID, $dispatcher);

        $result = $controller->execute();

        self::assertSame('payment', $result);
        self::assertTrue($controller->unavailableErrorShown);
        self::assertFalse($controller->delegatedToParent);
        self::assertSame([], $controller->redirectedTo);
    }

    public function testExecuteWhenNoCheckoutUrlShowsErrorAndDoesNotFinalize(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        // MollieCheckoutSessionHandler already failed the contract in this scenario; the
        // context simply carries no checkoutUrl back to the controller.
        $dispatcher->method('dispatch')->willReturnArgument(0);

        $controller = $this->executeController(MollieDefinitions::PAYMENT_ID, $dispatcher);

        $result = $controller->execute();

        self::assertSame('payment', $result);
        self::assertTrue($controller->unavailableErrorShown);
        self::assertFalse($controller->delegatedToParent);
        self::assertSame([], $controller->redirectedTo);
    }

    public function testExecuteWhenSessionChallengeInvalidDoesNotDispatchCheckoutSessionEvent(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::never())->method('dispatch');

        $controller = $this->executeController(MollieDefinitions::PAYMENT_ID, $dispatcher, challengeValid: false);

        $controller->execute();
    }

    public function testExecuteWhenSessionChallengeInvalidReturnsNullSilentlyWithoutSideEffects(): void
    {
        // Dispatcher deliberately unavailable: the guard must fire BEFORE service resolution.
        // Core parity: rejection is a silent null — no AGB flag, no error, no redirect.
        $controller = $this->executeController(MollieDefinitions::PAYMENT_ID, null, challengeValid: false);

        $result = $controller->execute();

        self::assertNull($result);
        self::assertFalse((bool) $controller->isConfirmAGBError());
        self::assertSame([], $controller->redirectedTo);
        self::assertFalse($controller->delegatedToParent);
        self::assertFalse($controller->unavailableErrorShown);
    }

    public function testExecuteWhenSessionChallengeValidProceedsToAgbValidation(): void
    {
        // Guard ordering mirrors core: challenge first, then terms — a valid challenge with
        // rejected terms must land in the AGB error path, not the silent challenge rejection.
        $controller = $this->executeController(
            MollieDefinitions::PAYMENT_ID,
            null,
            termsAccepted: false,
            challengeValid: true,
        );

        $result = $controller->execute();

        self::assertNull($result);
        self::assertTrue($controller->isConfirmAGBError() == 1);
    }

    public function testExecuteWhenNonMollieMethodDelegatesToParentWhichRunsItsOwnChallengeCheck(): void
    {
        $controller = $this->executeController('oxidcashondel', null, challengeValid: false);

        $controller->execute();

        self::assertTrue($controller->delegatedToParent);
    }

    public function testExecuteWhenBasketHashMismatchesDoesNotDispatchCheckoutSessionEvent(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::never())->method('dispatch');

        $controller = $this->executeController(
            MollieDefinitions::PAYMENT_ID,
            $dispatcher,
            requestParams: ['basketSummaryHash' => 'stale-hash'],
        );

        $controller->execute();
    }

    public function testExecuteWhenBasketHashMismatchesShowsBasketChangedErrorAndReturnsOrder(): void
    {
        $controller = $this->executeController(
            MollieDefinitions::PAYMENT_ID,
            null,
            requestParams: ['basketSummaryHash' => 'stale-hash'],
        );

        $result = $controller->execute();

        self::assertSame('order', $result);
        self::assertSame(['order'], $controller->basketErrorsShown);
        self::assertSame([], $controller->redirectedTo);
        self::assertFalse($controller->unavailableErrorShown);
    }

    public function testExecuteWhenBasketHashMismatchesOnEmptyBasketReturnsBasket(): void
    {
        $controller = $this->executeController(
            MollieDefinitions::PAYMENT_ID,
            null,
            requestParams: ['basketSummaryHash' => 'stale-hash'],
            basketEmpty: true,
        );

        self::assertSame('basket', $controller->execute());
        self::assertSame(['basket'], $controller->basketErrorsShown);
    }

    public function testExecuteWhenBasketHashMissingLogsWarningAndProceedsToRedirect(): void
    {
        // Core parity: a missing hash only warns — it must NOT block the checkout.
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')
            ->willReturnCallback(function (MollieCheckoutSessionRequestEvent $event) {
                $event->getContext()->set('checkoutUrl', 'https://mollie.test/checkout/tr_3');
                return $event;
            });

        $controller = $this->executeController(MollieDefinitions::PAYMENT_ID, $dispatcher, requestParams: []);

        self::assertNull($controller->execute());
        self::assertSame(['https://mollie.test/checkout/tr_3'], $controller->redirectedTo);
        self::assertSame([], $controller->basketErrorsShown);
    }

    public function testExecuteWhenBasketHashMatchesProceedsToRedirect(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')
            ->willReturnCallback(function (MollieCheckoutSessionRequestEvent $event) {
                $event->getContext()->set('checkoutUrl', 'https://mollie.test/checkout/tr_4');
                return $event;
            });

        $controller = $this->executeController(
            MollieDefinitions::PAYMENT_ID,
            $dispatcher,
            requestParams: ['basketSummaryHash' => TestableMollieOrderController::LIVE_BASKET_HASH],
        );

        self::assertNull($controller->execute());
        self::assertSame(['https://mollie.test/checkout/tr_4'], $controller->redirectedTo);
        self::assertSame([], $controller->basketErrorsShown);
    }

    public function testExecuteGuardOrderIsChallengeThenTermsThenBasketHash(): void
    {
        // Terms rejected + mismatched hash: core validates terms FIRST — the AGB error must win
        // and the basket-hash guard must not have run.
        $controller = $this->executeController(
            MollieDefinitions::PAYMENT_ID,
            null,
            termsAccepted: false,
            requestParams: ['basketSummaryHash' => 'stale-hash'],
        );

        $result = $controller->execute();

        self::assertNull($result);
        self::assertTrue($controller->isConfirmAGBError() == 1);
        self::assertSame([], $controller->basketErrorsShown);
    }

    public function testExecuteWhenAgbNotAcceptedDoesNotDispatchCheckoutSessionEvent(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::never())->method('dispatch');

        $controller = $this->executeController(MollieDefinitions::PAYMENT_ID, $dispatcher, termsAccepted: false);

        $controller->execute();
    }

    public function testExecuteWhenAgbNotAcceptedReturnsNullAndFlagsConfirmAgbError(): void
    {
        // Dispatcher deliberately unavailable: the guard must fire BEFORE service resolution,
        // so an invalid request has zero side effects and no "checkout unavailable" error.
        $controller = $this->executeController(MollieDefinitions::PAYMENT_ID, null, termsAccepted: false);

        $result = $controller->execute();

        self::assertNull($result);
        // Loose == 1: core sets int 1, the module bool true; the Apex template checks `== 1`.
        self::assertTrue($controller->isConfirmAGBError() == 1);
        self::assertSame([], $controller->redirectedTo);
        self::assertFalse($controller->delegatedToParent);
        self::assertFalse($controller->unavailableErrorShown);
    }

    public function testExecuteWhenAgbAcceptedProceedsToCheckoutRedirect(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')
            ->willReturnCallback(function (MollieCheckoutSessionRequestEvent $event) {
                $event->getContext()->set('checkoutUrl', 'https://mollie.test/checkout/tr_2');
                return $event;
            });

        $controller = $this->executeController(MollieDefinitions::PAYMENT_ID, $dispatcher, termsAccepted: true);

        self::assertNull($controller->execute());
        self::assertSame(['https://mollie.test/checkout/tr_2'], $controller->redirectedTo);
    }

    public function testExecuteWhenNonMollieMethodSkipsMollieAgbGuardAndDelegatesToParent(): void
    {
        // The parent's own execute() validates terms for non-Mollie payments — the Mollie guard
        // must not run (and must not block delegation) for them.
        $controller = $this->executeController('oxidcashondel', null, termsAccepted: false);

        $controller->execute();

        self::assertTrue($controller->delegatedToParent);
    }

    /**
     * @param array<string, string> $requestParams
     */
    private function executeController(
        string $paymentId,
        ?EventDispatcherInterface $dispatcher,
        bool $termsAccepted = true,
        bool $challengeValid = true,
        array $requestParams = [],
        bool $basketEmpty = false,
        ?InFlightCheckoutReplay $inFlightReplay = null,
        array $userDataProblems = [],
    ): TestableMollieOrderController {
        return new TestableMollieOrderController(
            requestParams: $requestParams,
            tokenService: $this->createMock(TokenServiceInterface::class),
            contractRepository: $this->createMock(ContractRepositoryInterface::class),
            resolver: null,
            responder: null,
            paymentId: $paymentId,
            dispatcher: $dispatcher,
            termsAccepted: $termsAccepted,
            challengeValid: $challengeValid,
            basketEmpty: $basketEmpty,
            inFlightReplay: $inFlightReplay,
            userDataProblems: $userDataProblems,
        );
    }

    private const LIVE_BASKET_TOTAL = 116.5;

    /**
     * The real service around a scripted payment-base resolver, reading a session basket worth
     * LIVE_BASKET_TOTAL - the controller is exercised with the collaborator it really calls.
     */
    private function replayAnswering(?string $checkoutUrl): InFlightCheckoutReplay
    {
        $resolver = $this->createMock(InFlightCheckoutAttemptResolverInterface::class);
        $resolver->expects(self::once())->method('resolve')->with(self::LIVE_BASKET_TOTAL)->willReturn($checkoutUrl);

        return new InFlightCheckoutReplay($resolver, $this->sessionWithBasketWorth(self::LIVE_BASKET_TOTAL));
    }

    private function replayNeverAsked(): InFlightCheckoutReplay
    {
        $resolver = $this->createMock(InFlightCheckoutAttemptResolverInterface::class);
        $resolver->expects(self::never())->method('resolve');

        return new InFlightCheckoutReplay($resolver, $this->sessionWithBasketWorth(self::LIVE_BASKET_TOTAL));
    }

    private function sessionWithBasketWorth(float $gross): SessionAdapterInterface
    {
        $price = $this->createMock(Price::class);
        $price->method('getBruttoPrice')->willReturn($gross);
        $basket = $this->createMock(Basket::class);
        $basket->method('getPrice')->willReturn($price);
        $session = $this->createMock(SessionAdapterInterface::class);
        $session->method('getBasket')->willReturn($basket);

        return $session;
    }

    // ── MOL-15: user data is validated at the order step, exactly as the Stripe module does ─────

    public function testExecuteWhenUserDataInvalidShowsFieldMessagesReturnsToAddressStepAndDispatchesNothing(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::never())->method('dispatch');

        $controller = $this->executeController(
            MollieDefinitions::PAYMENT_ID,
            $dispatcher,
            userDataProblems: ['The street field is not valid. Allowed symbols are: letters, digits, spaces'],
        );

        self::assertSame('user', $controller->execute());
        self::assertSame(
            ['The street field is not valid. Allowed symbols are: letters, digits, spaces'],
            $controller->userDataProblemsShown,
        );
        self::assertSame([], $controller->redirectedTo);
    }

    public function testExecuteChecksUserDataOnlyAfterTheCoreGuardsPassed(): void
    {
        $controller = $this->executeController(
            MollieDefinitions::PAYMENT_ID,
            null,
            termsAccepted: false,
            userDataProblems: ['problem'],
        );

        self::assertNull($controller->execute());
        self::assertSame([], $controller->userDataProblemsShown);
    }

    public function testExecuteWhenUserDataInvalidDoesNotReplayAnInFlightAttempt(): void
    {
        // The address went bad between two clicks: the shopper must fix it, not be sent to Mollie.
        $controller = $this->executeController(
            MollieDefinitions::PAYMENT_ID,
            null,
            inFlightReplay: $this->replayNeverAsked(),
            userDataProblems: ['problem'],
        );

        self::assertSame('user', $controller->execute());
        self::assertSame([], $controller->redirectedTo);
    }

    // ── MOL-18: a repeated "Order now" rejoins the attempt already in flight ──────────────────────

    public function testExecuteWhenAttemptInFlightRedirectsToExistingCheckoutUrlWithoutDispatching(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::never())->method('dispatch');

        $controller = $this->executeController(
            MollieDefinitions::PAYMENT_ID,
            $dispatcher,
            inFlightReplay: $this->replayAnswering('https://mollie.test/checkout/tr_first'),
        );

        self::assertNull($controller->execute());
        self::assertSame(['https://mollie.test/checkout/tr_first'], $controller->redirectedTo);
        self::assertFalse($controller->unavailableErrorShown);
    }

    public function testExecuteWhenNoAttemptInFlightDispatchesCheckoutSessionEvent(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(function (MollieCheckoutSessionRequestEvent $event) {
                $event->getContext()->set('checkoutUrl', 'https://mollie.test/checkout/tr_new');
                return $event;
            });

        $controller = $this->executeController(MollieDefinitions::PAYMENT_ID, $dispatcher, inFlightReplay: $this->replayAnswering(null));

        self::assertNull($controller->execute());
        self::assertSame(['https://mollie.test/checkout/tr_new'], $controller->redirectedTo);
    }

    public function testExecuteWhenResolverUnavailableProceedsAsBefore(): void
    {
        // payment-base older than the resolver: no behaviour change, a new attempt is started.
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(function (MollieCheckoutSessionRequestEvent $event) {
                $event->getContext()->set('checkoutUrl', 'https://mollie.test/checkout/tr_new');
                return $event;
            });

        $controller = $this->executeController(MollieDefinitions::PAYMENT_ID, $dispatcher, inFlightReplay: null);

        self::assertNull($controller->execute());
        self::assertSame(['https://mollie.test/checkout/tr_new'], $controller->redirectedTo);
    }

    public function testExecuteAsksForTheInFlightAttemptOnlyAfterTheGuardsPassed(): void
    {
        // A replay is still an order submission: CSRF, AGB and basket-hash guards come first.
        $inFlight = $this->replayNeverAsked();

        $challengeFails = $this->executeController(MollieDefinitions::PAYMENT_ID, null, challengeValid: false, inFlightReplay: $inFlight);
        self::assertNull($challengeFails->execute());

        $agbFails = $this->executeController(MollieDefinitions::PAYMENT_ID, null, termsAccepted: false, inFlightReplay: $inFlight);
        self::assertNull($agbFails->execute());

        $basketChanged = $this->executeController(
            MollieDefinitions::PAYMENT_ID,
            null,
            requestParams: ['basketSummaryHash' => 'stale'],
            inFlightReplay: $inFlight,
        );
        self::assertSame('order', $basketChanged->execute());
    }

    public function testExecuteWhenOrderExistsErrorSurfacesShowsCheckoutUnavailableWithoutRedirect(): void
    {
        // Defensive: payment-base now refuses a second createOrder() for the same challenge. That
        // only reaches this controller if the resolver missed - and then it is a clean error
        // page, never a redirect to pay for a phantom order.
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willThrowException(new ShopOrderException(
            message: 'An order for this checkout attempt already exists',
            errorCode: 'order_exists',
        ));

        $controller = $this->executeController(MollieDefinitions::PAYMENT_ID, $dispatcher, inFlightReplay: $this->replayAnswering(null));

        self::assertSame('payment', $controller->execute());
        self::assertTrue($controller->unavailableErrorShown);
        self::assertSame([], $controller->redirectedTo);
    }

    public function testCheckoutReturnWithValidTokenDispatchesReturnFlowAndGoesToThankyou(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $resolver = $this->createMock(ReturnResolverInterface::class);

        $responder = $this->createMock(CheckoutReturnResponder::class);
        $responder->expects(self::once())
            ->method('respond')
            ->with(MollieDefinitions::PROVIDER_NAME, $contract, $resolver, [])
            ->willReturn('order-42');

        $controller = $this->controller(
            requestParams: ['contract_id' => 'contract-1', 'contract_token' => 'good-token'],
            tokenValid: true,
            contract: $contract,
            resolver: $resolver,
            responder: $responder,
        );

        self::assertSame('thankyou', $controller->checkoutReturn());
    }

    public function testCheckoutReturnWithTamperedTokenIsRejected(): void
    {
        $responder = $this->createMock(CheckoutReturnResponder::class);
        $responder->expects(self::never())->method('respond');

        $controller = $this->controller(
            requestParams: ['contract_id' => 'contract-1', 'contract_token' => 'bad-token'],
            tokenValid: false,
            responder: $responder,
        );

        self::assertSame('payment', $controller->checkoutReturn());
    }

    public function testCheckoutReturnWithMissingParametersIsRejected(): void
    {
        $responder = $this->createMock(CheckoutReturnResponder::class);
        $responder->expects(self::never())->method('respond');

        $controller = $this->controller(requestParams: [], tokenValid: false, responder: $responder);

        self::assertSame('payment', $controller->checkoutReturn());
    }

    public function testCheckoutReturnWithUnknownContractIsRejected(): void
    {
        $responder = $this->createMock(CheckoutReturnResponder::class);
        $responder->expects(self::never())->method('respond');

        $controller = $this->controller(
            requestParams: ['contract_id' => 'contract-missing', 'contract_token' => 'good-token'],
            tokenValid: true,
            contract: null,
            responder: $responder,
        );

        self::assertSame('payment', $controller->checkoutReturn());
    }

    public function testCheckoutReturnWhenResponderReturnsNullIsTreatedAsUnfinalised(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $resolver = $this->createMock(ReturnResolverInterface::class);

        $responder = $this->createMock(CheckoutReturnResponder::class);
        $responder->method('respond')->willReturn(null);

        $controller = $this->controller(
            requestParams: ['contract_id' => 'contract-1', 'contract_token' => 'good-token'],
            tokenValid: true,
            contract: $contract,
            resolver: $resolver,
            responder: $responder,
        );

        self::assertSame('payment', $controller->checkoutReturn());
    }

    public function testCheckoutReturnWhenPaymentIsPendingLandsOnThankYouNotError(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getOrderId')->willReturn('order-42');
        $resolver = $this->createMock(ReturnResolverInterface::class);

        // Responder returns null (no committed order) — but the payment is still pending, not failed.
        $responder = $this->createMock(CheckoutReturnResponder::class);
        $responder->method('respond')->willReturn(null);

        $controller = $this->controller(
            requestParams: ['contract_id' => 'contract-1', 'contract_token' => 'good-token'],
            tokenValid: true,
            contract: $contract,
            resolver: $resolver,
            responder: $responder,
        );
        $controller->pendingReturn = true;

        self::assertSame('thankyou', $controller->checkoutReturn());
    }

    /**
     * @param array<string, string> $requestParams
     */
    private function controller(
        array $requestParams,
        bool $tokenValid,
        ?PaymentContractInterface $contract = null,
        ?ReturnResolverInterface $resolver = null,
        ?CheckoutReturnResponder $responder = null,
    ): TestableMollieOrderController {
        $tokenService = $this->createMock(TokenServiceInterface::class);
        $tokenService->method('validateToken')->willReturn($tokenValid);

        $contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $contractRepository->method('findById')->willReturn($contract);

        return new TestableMollieOrderController(
            $requestParams,
            $tokenService,
            $contractRepository,
            $resolver,
            $responder,
        );
    }
}
