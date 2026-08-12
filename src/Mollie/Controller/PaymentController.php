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
use OxidEsales\Payments\Mollie\Service\OxidUserFieldReader;
use OxidEsales\Payments\Mollie\Service\UserDataValidatorInterface;
use OxidEsales\Payments\Mollie\Service\UserFieldReaderInterface;
use Throwable;

/**
 * Thin extension of OXID's core PaymentController.
 *
 * Story 6: gates progression past the payment-selection step when the customer's stored
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

        if (!$this->userDataIsValid()) {
            $this->showInvalidUserDataError();
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
     * Pre-progression gate (Story 6): rejects malformed customer data server-side, before the
     * shopper can proceed to the order-confirmation step. Fails open (returns true) when the
     * validator or a usable field reader is unavailable, so a wiring problem in the validation
     * subsystem never blocks checkout entirely; the shared character-level rules are
     * defense-in-depth, not the only gate against malformed data.
     */
    protected function userDataIsValid(): bool
    {
        $validator = $this->resolveUserDataValidator();
        $reader = $this->buildUserFieldReader();
        if ($validator === null || $reader === null) {
            // Sprint 11 Story 8 (F13): the direction stays fail-open — this is defence-in-depth and
            // must not be the thing that breaks checkout. What changes is that it is no longer
            // invisible: CLAUDE.md makes adopting the shared validation subsystem a requirement for
            // Mollie, so a wiring problem silently retiring it is worse here than in a module where
            // it is optional.
            Registry::getLogger()->warning(
                '[MolliePaymentController] user-data validation unavailable; allowing checkout to '
                . 'proceed without the shared character-level rules',
                ['hasValidator' => $validator !== null, 'hasFieldReader' => $reader !== null],
            );

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
