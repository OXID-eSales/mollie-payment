<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Controller;

use OxidEsales\Eshop\Application\Model\User;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Core\Di\ContainerFacade;
use OxidEsales\PaymentBase\Controller\HandlesCheckoutReturn;
use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use OxidEsales\PaymentBase\EventSystem\EventDispatcherInterface;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\Service\CheckoutUserDataGate;
use OxidEsales\Payments\Mollie\Service\InFlightCheckoutReplay;
use OxidEsales\Payments\Mollie\EventSystem\Event\MollieCheckoutSessionRequestEvent;
use Throwable;

/**
 * Class-chain extension of OXID's core OrderController (metadata.php `extend`), reachable at
 * `cl=order` — NOT tagged `oxid.view_controller`. payment-base's own services.yaml documents why:
 * tagging a class-chain extension as a service forces the DI compiler to reflect/autoload it,
 * which re-enters the OXID module class-chain build and fails with "Controller namespace
 * duplication" on activation. A pure `extend` map entry (mirroring Stripe's
 * `OrderController::class => StripeOrderController::class`) sidesteps that entirely.
 *
 * Bug fix: core's `PaymentController` has `validatePayment()`, NOT `execute()` — the "Place
 * order" button actually submits to `cl=order&fnc=execute` (core `OrderController::execute()`),
 * which is where a redirect-based PSP must intercept. Mollie is a pure server-side redirect (no
 * JS Checkout like Stripe's), so `execute()` here dispatches the checkout-session event and
 * redirects directly — no PaymentController involvement.
 *
 * The single post-checkout return leg (`checkoutReturn()`) lives in {@see HandlesMollieCheckoutReturn}:
 * Mollie always redirects back to one `redirectUrl` regardless of outcome (there is no separate
 * cancel URL like PayPal's), so {@see MollieReturnResolver} maps every Mollie payment status onto
 * the shared ReturnResolution and this controller just reacts to success/failure. The webhook
 * remains the source of truth for fulfillment; the return only advances safely.
 *
 * @phpstan-ignore class.notFound
 */
class MollieOrderController extends MollieOrderController_parent
{
    use HandlesCheckoutReturn;
    // The Mollie leg fetches the return responder from the container at runtime (OXID controllers
    // get no constructor injection); payment-base's default expects it to be set explicitly.
    use HandlesMollieCheckoutReturn {
        HandlesMollieCheckoutReturn::resolveCheckoutReturnResponder insteadof HandlesCheckoutReturn;
    }

    public function execute(): ?string
    {
        $paymentId = $this->getSelectedPaymentId();
        if ($paymentId !== MollieDefinitions::PAYMENT_ID) {
            return $this->delegateToParent();
        }

        if (!$this->passesSessionChallenge()) {
            return null;
        }

        if (!$this->confirmsTermsAndConditions()) {
            $this->_blConfirmAGBError = true;

            return null;
        }

        $basketRedirect = $this->validateBasketSummaryHash();
        if ($basketRedirect !== null) {
            return $basketRedirect;
        }

        // MOL-15: the point of no return. Address data is validated with payment-base's rules for
        // Mollie right before the PSP is called - the payment step checked it too, but it can have
        // changed since (account page, another tab). Comes BEFORE the in-flight replay: data that
        // went bad between two clicks must be fixed, not replayed into Mollie.
        $problems = $this->userDataProblems();
        if ($problems !== []) {
            $this->showUserDataProblems($problems);

            return 'user';
        }

        // MOL-18: a repeated "Order now" rejoins the attempt already in flight instead of starting
        // another ({@see InFlightCheckoutReplay}). Deliberately AFTER the CSRF / AGB / basket-hash
        // guards - a replay is still an order submission. Fail-open to "nothing in flight" when
        // payment-base predates the resolver: resolveService() answers null.
        $replay = $this->resolveService(InFlightCheckoutReplay::class)?->checkoutUrl();
        if ($replay !== null) {
            $this->redirect($replay);

            return null;
        }

        return $this->startCheckoutSession($paymentId);
    }

    /**
     * A fresh attempt: dispatch the checkout-session event and leave for Mollie.
     */
    private function startCheckoutSession(string $paymentId): ?string
    {
        $dispatcher = $this->resolveDispatcher();
        if ($dispatcher === null) {
            return $this->onCheckoutUnavailable();
        }

        $context = $this->buildCheckoutContext($paymentId);

        try {
            $dispatcher->dispatch(new MollieCheckoutSessionRequestEvent($context));
        } catch (Throwable $e) {
            Registry::getLogger()->error('MollieOrderController: checkout session event failed', [
                'error' => $e->getMessage(),
            ]);
            return $this->onCheckoutUnavailable();
        }

        // Set by MollieCheckoutSessionHandler as a string; anything else means "no checkout".
        $checkoutUrl = (string) $context->get('checkoutUrl', '');
        if ($checkoutUrl !== '') {
            $this->redirect($checkoutUrl);
            return null;
        }

        return $this->onCheckoutUnavailable();
    }

    /**
     * LSP: core OrderController::execute() runs Session::checkSessionChallenge() as its FIRST
     * guard and returns null (silent re-render) on failure. Intercepting execute() for Mollie
     * must preserve that CSRF contract — otherwise a cross-site form POST can trigger a Mollie
     * checkout session for a logged-in customer. Rejection is deliberately silent (core parity).
     *
     * Testability seam: the session is Registry-backed.
     */
    protected function passesSessionChallenge(): bool
    {
        return (bool) Registry::getSession()->checkSessionChallenge();
    }

    /**
     * LSP: core OrderController::execute() never finalizes or leaves the shop without
     * validateTermsAndConditions() passing (blConfirmAGB / ord_agb plus the intangible-product
     * agreements). Intercepting execute() for Mollie must preserve that contract — otherwise a
     * Mollie payment completes without the mandatory AGB acceptance. On rejection the core flag
     * `_blConfirmAGBError` re-renders the order step with the READ_AND_CONFIRM_TERMS error,
     * exactly like a non-Mollie payment.
     *
     * Testability seam: the parent method is Registry-backed.
     */
    protected function confirmsTermsAndConditions(): bool
    {
        return (bool) $this->validateTermsAndConditions();
    }

    /**
     * LSP: core OrderController::execute() never finalizes or leaves the shop when the posted
     * basketSummaryHash no longer matches the live basket ("basket changed in another tab") —
     * it shows BASKET_ITEMS_CHANGED_ERROR and returns to the order (or basket) step. Core's
     * helpers are PRIVATE (OrderController::getBasketSummaryHash() and friends), so the
     * comparison is mirrored here — do not fork the behavior. Core parity throughout: a
     * MISSING hash only logs a warning and proceeds.
     *
     * @return string|null null to proceed; otherwise the controller to return to
     */
    private function validateBasketSummaryHash(): ?string
    {
        $requestHash = $this->readRequestParameter('basketSummaryHash');
        if ($requestHash === null) {
            $this->warnBasketHashMissing();

            return null;
        }

        if ($requestHash === $this->currentBasketSummaryHash()) {
            return null;
        }

        $redirect = $this->basketRedirectTarget();
        $this->showBasketChangedError($redirect);

        return $redirect;
    }

    /**
     * Mirror of core's private OrderController::getBasketSummaryHash() — byte-for-byte, or every
     * legitimate order gets rejected. Testability seam (Registry-backed).
     */
    protected function currentBasketSummaryHash(): string
    {
        return md5((string) json_encode(Registry::getSession()->getBasket()->getBasketSummary()));
    }

    /**
     * Core parity: an emptied basket returns to the basket step, otherwise back to order review.
     */
    protected function basketRedirectTarget(): string
    {
        return Registry::getSession()->getBasket()->getProductsCount() === 0 ? 'basket' : 'order';
    }

    protected function showBasketChangedError(string $redirect): void
    {
        Registry::getUtilsView()->addErrorToDisplay('BASKET_ITEMS_CHANGED_ERROR', false, true, '', $redirect);
    }

    /**
     * Core-parity wording — mirror of the private notifyIfBasketSummaryValidationIsNotPossible().
     */
    protected function warnBasketHashMissing(): void
    {
        Registry::getLogger()->warning(
            'Pricing and payments verification can not be performed, ' .
            'the basketSummaryHash parameter was not sent with request data.'
        );
    }

    /**
     * Testability seam: real OXID execution delegates to the class-chain parent, which performs
     * the standard `finalizeOrder()` flow for non-Mollie payment methods.
     */
    protected function delegateToParent(): ?string
    {
        return parent::execute();
    }

    protected function getSelectedPaymentId(): string
    {
        $paymentId = Registry::getSession()->getVariable('paymentid');

        return is_scalar($paymentId) ? (string) $paymentId : '';
    }

    protected function resolveDispatcher(): ?EventDispatcherInterface
    {
        return $this->resolveService(EventDispatcherInterface::class);
    }

    protected function buildCheckoutContext(string $paymentId): EventContext
    {
        $session = Registry::getSession();
        $basket = $session->getBasket();
        // Base::getUser() returns User|false (anonymous session) — normalize to User|null once.
        $user = $session->getUser() ?: null;

        return new EventContext([
            'paymentId' => $paymentId,
            'userId' => (string) $user?->getId(),
            'basket' => $basket,
            'user' => $user,
            'sessionId' => (string) $session->getId(),
            'conditionTypes' => ['payment_authorized'],
            // IFRAME-04: the inline method selection + (for card) the Mollie Components token,
            // posted with the order form. Both null for the classic redirect flow.
            'cardToken' => $this->readRequestParameter('mollieCardToken'),
            'selectedMethod' => $this->readRequestParameter('mollieMethod'),
        ]);
    }

    /**
     * Testability seam: Registry::getUtils()->redirect() ends the request (exit()), which
     * would kill the PHPUnit process if called directly from execute().
     */
    protected function redirect(string $url): void
    {
        Registry::getUtils()->redirect($url, false);
    }

    /**
     * Testability seam: the translated messages for the session user's address problems, empty when
     * the data may go to Mollie ({@see CheckoutUserDataGate}). Fail-open when the gate is unavailable.
     *
     * @return list<string>
     */
    protected function userDataProblems(): array
    {
        $user = Registry::getSession()->getUser();

        return $this->resolveService(CheckoutUserDataGate::class)
            ?->problemsFor($user instanceof User ? $user : null) ?? [];
    }

    /**
     * @param list<string> $messages
     */
    protected function showUserDataProblems(array $messages): void
    {
        foreach ($messages as $message) {
            Registry::getUtilsView()->addErrorToDisplay($message);
        }
    }

    /**
     * Guard: either the dispatcher/event chain was unavailable, or it ran but produced no
     * checkout URL (MollieCheckoutSessionHandler already failed the contract in that case).
     * Surface a user-facing error and stay on the payment step — the order must NOT finalize
     * without a successful redirect to Mollie.
     */
    protected function onCheckoutUnavailable(): string
    {
        Registry::getUtilsView()->addErrorToDisplay('MOLLIE_CHECKOUT_UNAVAILABLE');

        return 'payment';
    }

    protected function readRequestParameter(string $name): ?string
    {
        $value = Registry::getRequest()->getRequestParameter($name);
        $value = is_scalar($value) ? (string) $value : '';

        return $value !== '' ? $value : null;
    }

    /**
     * @template T of object
     * @param class-string<T> $className
     * @return T|null
     */
    protected function resolveService(string $className): ?object
    {
        try {
            /** @var T $service ContainerFacade::get() is untyped; the id IS the class name here */
            $service = ContainerFacade::get($className);

            return $service;
        } catch (Throwable $e) {
            // Sprint 11 Story 8: every caller handles null with an explicit user-facing error path, so
            // this controller is fail-closed throughout — but five call sites were resolving services
            // in total silence, which made "why did checkout say MOLLIE_CHECKOUT_UNAVAILABLE" an
            // unanswerable question.
            Registry::getLogger()->warning('[MollieOrderController] service unavailable', [
                'service' => $className,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
