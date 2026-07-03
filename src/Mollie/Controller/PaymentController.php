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
use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use OxidEsales\PaymentBase\EventSystem\EventDispatcherInterface;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\EventSystem\Event\MollieCheckoutSessionRequestEvent;
use OxidEsales\Payments\Mollie\Service\OxidUserFieldReader;
use OxidEsales\Payments\Mollie\Service\UserDataValidatorInterface;
use OxidEsales\Payments\Mollie\Service\UserFieldReaderInterface;
use Throwable;

/**
 * Thin extension of OXID's core PaymentController that intercepts Mollie selections.
 *
 * All business logic stays in services/handlers (SRP): this controller only
 *  - builds the request-scoped {@see EventContext},
 *  - dispatches {@see MollieCheckoutSessionRequestEvent},
 *  - redirects to the checkout URL the handler chain wrote back onto the context.
 *
 * Non-Mollie payment methods are untouched (early return / parent delegate — LSP).
 *
 * @phpstan-ignore class.notFound
 */
class PaymentController extends PaymentController_parent
{
    public function execute(): mixed
    {
        $paymentId = $this->getSelectedPaymentId();
        if ($paymentId !== MollieDefinitions::PAYMENT_ID) {
            return $this->delegateToParent();
        }

        if (!$this->userDataIsValid()) {
            $this->showInvalidUserDataError();
            return $this->delegateToParent();
        }

        $dispatcher = $this->resolveDispatcher();
        if ($dispatcher === null) {
            return $this->delegateToParent();
        }

        $context = $this->buildCheckoutContext($paymentId);

        try {
            $dispatcher->dispatch(new MollieCheckoutSessionRequestEvent($context));
        } catch (Throwable $e) {
            Registry::getLogger()->error('MolliePaymentController: checkout session event failed', [
                'error' => $e->getMessage(),
            ]);
            return $this->delegateToParent();
        }

        $checkoutUrl = $context->get('checkoutUrl');
        if (is_string($checkoutUrl) && $checkoutUrl !== '') {
            $this->redirect($checkoutUrl);
            return null;
        }

        $this->showCheckoutUnavailableError();
        return $this->delegateToParent();
    }

    /**
     * Testability seam: real OXID execution delegates to the class-chain parent.
     */
    protected function delegateToParent(): mixed
    {
        // OXID virtual parent: whether PaymentController_parent::execute() exists depends on
        // the active module class chain and can't be resolved statically (mirrors PayPal's
        // identical PaymentController::execute() -> parent call).
        // @phpstan-ignore-next-line staticMethod.notFound
        return parent::execute();
    }

    protected function getSelectedPaymentId(): string
    {
        $paymentId = Registry::getSession()->getVariable('paymentid');

        return is_scalar($paymentId) ? (string) $paymentId : '';
    }

    protected function resolveDispatcher(): ?EventDispatcherInterface
    {
        try {
            $dispatcher = ContainerFacade::get(EventDispatcherInterface::class);
        } catch (Throwable) {
            return null;
        }

        return $dispatcher instanceof EventDispatcherInterface ? $dispatcher : null;
    }

    protected function buildCheckoutContext(string $paymentId): EventContext
    {
        $session = Registry::getSession();
        $basket = $session->getBasket();
        $user = $session->getUser();
        $userId = is_object($user) && method_exists($user, 'getId') ? (string) $user->getId() : '';

        return new EventContext([
            'paymentId' => $paymentId,
            'userId' => $userId,
            'basket' => $basket,
            'user' => is_object($user) ? $user : null,
            'sessionId' => (string) $session->getId(),
            'conditionTypes' => ['payment_authorized'],
            // Sprint 7: the specific Mollie method (iDEAL, card, …) chosen on the storefront
            // selector. MollieCheckoutSessionHandler reads this key when building
            // CreatePaymentRequest; null means "let Mollie's own hosted picker decide".
            'mollieMethod' => $this->selectedMollieMethod(),
        ]);
    }

    /**
     * Reads the storefront method-selector's choice (Sprint 7 Story 5). The field name
     * `mollie_method` matches the radio input in `views/twig/frontend/mollie_methods.html.twig`.
     */
    protected function selectedMollieMethod(): ?string
    {
        $method = Registry::getRequest()->getRequestEscapedParameter('mollie_method');

        return is_string($method) && $method !== '' ? $method : null;
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
     * Guard: the checkout-session handler chain ran but produced no checkout URL (the
     * MollieCheckoutSessionHandler already failed the contract in this case) — surface a
     * user-facing error instead of silently falling through.
     */
    protected function showCheckoutUnavailableError(): void
    {
        Registry::getUtilsView()->addErrorToDisplay('MOLLIE_CHECKOUT_UNAVAILABLE');
    }

    /**
     * Pre-dispatch gate (Story 6): rejects malformed customer data server-side, before the
     * checkout-session event — and therefore before createPayment — ever fires. Fails open
     * (returns true) when the validator or a usable field reader is unavailable, so a wiring
     * problem in the validation subsystem never blocks checkout entirely; the shared
     * character-level rules are defense-in-depth, not the only gate against malformed data.
     */
    protected function userDataIsValid(): bool
    {
        $validator = $this->resolveUserDataValidator();
        $reader = $this->buildUserFieldReader();
        if ($validator === null || $reader === null) {
            return true;
        }

        return $validator->validateForUser($reader) === [];
    }

    protected function resolveUserDataValidator(): ?UserDataValidatorInterface
    {
        try {
            $validator = ContainerFacade::get(UserDataValidatorInterface::class);
        } catch (Throwable) {
            return null;
        }

        return $validator instanceof UserDataValidatorInterface ? $validator : null;
    }

    protected function buildUserFieldReader(): ?UserFieldReaderInterface
    {
        $user = Registry::getSession()->getUser();

        return $user instanceof User ? new OxidUserFieldReader($user) : null;
    }

    protected function showInvalidUserDataError(): void
    {
        Registry::getUtilsView()->addErrorToDisplay('MOLLIE_VALIDATION_INVALID_USER_DATA');
    }
}
