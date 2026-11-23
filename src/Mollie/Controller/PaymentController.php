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
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\Service\CheckoutUserDataGate;
use Throwable;

/**
 * Thin extension of OXID's core PaymentController.
 *
 * Gates progression past the payment-selection step when the customer's stored
 * billing/delivery data fails the shared character-level validation rules — mirrors Stripe's
 * `validatePayment()` override.
 *
 * This controller does NOT dispatch the checkout session or redirect to Mollie: core's
 * `PaymentController` has `validatePayment()`, not `execute()` — the "Place order" button
 * actually submits to `cl=order&fnc=execute` (core `OrderController::execute()`). That is why
 * the checkout-session dispatch + redirect logic lives in
 * {@see \OxidEsales\Payments\Mollie\Controller\MollieOrderController::execute()} instead.
 *
 * @phpstan-ignore class.notFound
 */
class PaymentController extends PaymentController_parent
{
    public function validatePayment()
    {
        $result = $this->delegateValidatePayment();

        if ($this->getSelectedPaymentId() !== MollieDefinitions::PAYMENT_ID) {
            return $result;
        }

        // MOL-15: per-field messages from the shared gate, exactly as at the order step.
        $problems = $this->userDataProblems();
        if ($problems !== []) {
            $this->showUserDataProblems($problems);

            return 'payment';
        }

        return $result;
    }

    /**
     * Testability seam: real OXID execution delegates to the class-chain parent.
     */
    protected function delegateValidatePayment(): mixed
    {
        // OXID virtual parent: whether PaymentController_parent::validatePayment() exists
        // depends on the active module class chain and can't be resolved statically.
        // @phpstan-ignore-next-line staticMethod.notFound
        return parent::validatePayment();
    }

    protected function getSelectedPaymentId(): string
    {
        $paymentId = Registry::getSession()->getVariable('paymentid');

        return is_scalar($paymentId) ? (string) $paymentId : '';
    }
    /**
     * Testability seam: the translated messages for the session user's address problems, empty when
     * the data may go to Mollie ({@see CheckoutUserDataGate}). Sprint 149 (AW): fail closed when the
     * gate is unavailable - data that was not validated does not go to Mollie - with an error log line.
     *
     * @return list<string>
     */
    protected function userDataProblems(): array
    {
        $gate = $this->resolveGate();
        if ($gate === null) {
            // Sprint 149 (AW): fail closed, as the gate itself does. The data is not validated, so it
            // does not go to Mollie. The cause is in the log line of resolveGate().
            return ['MOLLIE_CHECKOUT_UNAVAILABLE'];
        }

        $user = Registry::getSession()->getUser();

        return $gate->problemsFor($user instanceof User ? $user : null);
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

    private function resolveGate(): ?CheckoutUserDataGate
    {
        try {
            $gate = ContainerFacade::get(CheckoutUserDataGate::class);
        } catch (Throwable $failure) {
            // The exception text can carry container internals: the class is enough to find the cause.
            Registry::getLogger()->error(
                '[MolliePaymentController] user-data validation unavailable; checkout refused',
                ['exception' => $failure::class]
            );

            return null;
        }

        return $gate instanceof CheckoutUserDataGate ? $gate : null;
    }
}
