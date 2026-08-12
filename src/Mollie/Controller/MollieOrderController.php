<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Controller;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Core\Di\ContainerFacade;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Controller\CheckoutReturnResponder;
use OxidEsales\PaymentBase\Controller\HandlesCheckoutReturn;
use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use OxidEsales\PaymentBase\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Controller\SessionWriterInterface;
use OxidEsales\PaymentBase\Return\ReturnResolverInterface;
use OxidEsales\PaymentBase\Service\TokenServiceInterface;
use OxidEsales\Payments\Mollie\Adapter\MollieOutcome;
use OxidEsales\Payments\Mollie\Adapter\MolliePaymentsAdapterInterface;
use OxidEsales\Payments\Mollie\Adapter\MollieStatusMapper;
use OxidEsales\Payments\Mollie\Service\ContractTokenService;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\EventSystem\Event\MollieCheckoutSessionRequestEvent;
use OxidEsales\Payments\Mollie\Service\Return\MollieReturnResolver;
use RuntimeException;
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
 * Also handles the single post-checkout return leg on this same class (`checkoutReturn()`):
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

    public function execute(): ?string
    {
        $paymentId = $this->getSelectedPaymentId();
        if ($paymentId !== MollieDefinitions::PAYMENT_ID) {
            return $this->delegateToParent();
        }

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

        $checkoutUrl = $context->get('checkoutUrl');
        if (is_string($checkoutUrl) && $checkoutUrl !== '') {
            $this->redirect($checkoutUrl);
            return null;
        }

        return $this->onCheckoutUnavailable();
    }

    public function checkoutReturn(): string
    {
        $contractId = $this->readRequestParameter('contract_id');
        $contractToken = $this->readRequestParameter('contract_token');
        if ($contractId === null || $contractToken === null) {
            return $this->onReturnError('missing_token');
        }

        if (!$this->tokenIsValid($contractToken, $contractId)) {
            return $this->onReturnError('invalid_token');
        }

        $contract = $this->loadContract($contractId);
        if ($contract === null) {
            return $this->onReturnError('unknown_contract');
        }

        $resolver = $this->resolveReturnResolver();
        if ($resolver === null) {
            return $this->onReturnError('return_service_unavailable');
        }

        $orderId = $this->dispatchCheckoutReturn(
            providerName: MollieDefinitions::PROVIDER_NAME,
            contract: $contract,
            resolver: $resolver,
        );

        if ($orderId === null) {
            // A null order id covers TWO cases the responder can't distinguish here: a hard failure,
            // and a still-open/pending payment left for the webhook (common for PayPal / bank-style
            // methods). Query Mollie once to tell them apart — a pending payment is NOT an error: the
            // order was placed and the webhook will finalize it, so land on thank-you with a notice.
            if ($this->returnIsPending($contract)) {
                return $this->onReturnPending($contract);
            }
            return $this->onReturnError('return_not_finalised');
        }

        return 'thankyou';
    }

    protected function resolveCheckoutReturnResponder(): CheckoutReturnResponder
    {
        $responder = $this->resolveService(CheckoutReturnResponder::class);

        return $responder instanceof CheckoutReturnResponder
            ? $responder
            : throw new RuntimeException('CheckoutReturnResponder not available');
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
        $dispatcher = $this->resolveService(EventDispatcherInterface::class);

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

    private function resolveReturnResolver(): ?ReturnResolverInterface
    {
        $resolver = $this->resolveService(MollieReturnResolver::class);

        return $resolver instanceof ReturnResolverInterface ? $resolver : null;
    }

    private function tokenIsValid(string $contractToken, string $contractId): bool
    {
        // Resolve Mollie's CONCRETE token service, not the shared
        // PaymentBase\TokenServiceInterface: that interface is single-valued in the merged DI
        // container and, when another PSP is active, resolves to the wrong provider's HMAC.
        $tokenService = $this->resolveService(ContractTokenService::class);

        return $tokenService instanceof TokenServiceInterface
            && $tokenService->validateToken($contractToken, $contractId);
    }

    private function loadContract(string $contractId): ?PaymentContractInterface
    {
        $repository = $this->resolveService(ContractRepositoryInterface::class);

        return $repository instanceof ContractRepositoryInterface ? $repository->findById($contractId) : null;
    }

    protected function readRequestParameter(string $name): ?string
    {
        $value = Registry::getRequest()->getRequestParameter($name);
        $value = is_scalar($value) ? (string) $value : '';

        return $value !== '' ? $value : null;
    }

    private function onReturnError(string $code): string
    {
        Registry::getLogger()->warning('MollieOrderController: checkout return failed', ['reason' => $code]);
        Registry::getUtilsView()->addErrorToDisplay('MOLLIE_RETURN_' . strtoupper($code));

        return 'payment';
    }

    /**
     * True when the Mollie payment is still open/pending on return (not paid/authorized yet, but not
     * failed) — the webhook will finalize it. Overridable seam; production queries Mollie once.
     */
    protected function returnIsPending(PaymentContractInterface $contract): bool
    {
        $paymentId = $contract->getProviderOrderId();
        if ($paymentId === null || $paymentId === '') {
            return false;
        }

        $adapter = $this->resolveService(MolliePaymentsAdapterInterface::class);
        $mapper = $this->resolveService(MollieStatusMapper::class);
        if (!$adapter instanceof MolliePaymentsAdapterInterface || !$mapper instanceof MollieStatusMapper) {
            return false;
        }

        try {
            return $mapper->map($adapter->getPayment($paymentId)->status) === MollieOutcome::PENDING;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Pending payment on return: the order is placed (NOT_FINISHED) and the webhook will confirm it.
     * Show the thank-you page with a "payment is being processed" notice — never the error screen.
     */
    private function onReturnPending(PaymentContractInterface $contract): string
    {
        Registry::getLogger()->info(
            'MollieOrderController: payment pending on return — order placed, awaiting webhook confirmation',
        );

        $orderId = $contract->getOrderId();
        $writer = $this->resolveService(SessionWriterInterface::class);
        if (is_string($orderId) && $orderId !== '' && $writer instanceof SessionWriterInterface) {
            $writer->writeSessChallenge($orderId);
        }

        Registry::getUtilsView()->addErrorToDisplay('MOLLIE_RETURN_PENDING');

        return 'thankyou';
    }

    /**
     * @param class-string $className
     */
    protected function resolveService(string $className): ?object
    {
        try {
            return ContainerFacade::get($className);
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
