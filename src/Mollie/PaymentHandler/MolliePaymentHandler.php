<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\PaymentHandler;

use OxidEsales\Eshop\Application\Model\Basket;
use OxidEsales\Eshop\Application\Model\User;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\PaymentBase\Adapter\PaymentContextInterface;
use OxidEsales\PaymentBase\Adapter\PaymentHandlerInterface;
use OxidEsales\PaymentBase\Adapter\PaymentHandlerResult;
use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use OxidEsales\PaymentBase\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentBase\Service\IframeCheckoutSettingsInterface;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\EventSystem\Event\MollieCheckoutSessionRequestEvent;
use OxidEsales\Payments\Mollie\Module;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Bridges Mollie to one-page-checkout's `PaymentHandlerInterface`.
 *
 * OPC's `CheckoutService::processCheckout()` looks up a handler by `paymentMethodId` from the
 * `oe.payment.handler`-tagged registry. Without a Mollie handler that lookup fails with
 * "No payment handler found for payment method: oe_payments_mollie" and Mollie cannot be paid
 * through the one-page checkout (it works fine in the standard `cl=order` flow).
 *
 * The handler is a thin wrapper around the SAME checkout-session event the standard flow uses:
 * {@see \OxidEsales\Payments\Mollie\Controller\MollieOrderController::execute()} dispatches
 * {@see MollieCheckoutSessionRequestEvent} and redirects to the `checkoutUrl` the handler chain
 * writes onto the context. Here we build the same context, dispatch the same event, and hand the
 * `checkoutUrl` back to OPC as `metadata.redirectUrl` so its frontend redirects the browser to
 * Mollie's hosted checkout (see one-page-checkout `default_checkout_footer_controller.js`).
 *
 * Mirrors PayPal's `PayPalPaymentHandler` at the OPC boundary — both are redirect PSPs driven by a
 * checkout-session event; neither module inherits from the other.
 */
class MolliePaymentHandler implements PaymentHandlerInterface
{
    /**
     * Guards the one-time "iframe requested but Mollie cannot be framed" log so it is not emitted
     * on every checkout render (the handler is a DI singleton, so once per process).
     */
    private bool $iframeFallbackLogged = false;

    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?IframeCheckoutSettingsInterface $iframeSettings = null,
    ) {
    }

    public function getId(): string
    {
        return 'mollie';
    }

    /**
     * OXID module id — read by OPC's `PaymentHandlerRegistry` (rev-56) to
     * resolve which module's settings to read for the UI-topology declaration
     * (`sPaymentHandlerUiTopology`). Not part of PaymentHandlerInterface —
     * that stays untouched. OPC picks this up via `method_exists()` on the
     * concrete class.
     *
     * @since opc-125 rev-56
     */
    public function getModuleId(): string
    {
        return Module::MODULE_ID;
    }

    public function getName(): string
    {
        return 'Mollie Payment';
    }

    public function supports(string $paymentMethodId): bool
    {
        return $paymentMethodId === MollieDefinitions::PAYMENT_ID;
    }

    public function processPayment(PaymentContextInterface $context): PaymentHandlerResult
    {
        try {
            $this->prepareOxidBasket($context);

            $eventContext = $this->buildEventContext($context);
            $this->eventDispatcher->dispatch(new MollieCheckoutSessionRequestEvent($eventContext));

            $checkoutUrl = $eventContext->get('checkoutUrl');
            $contractId = $eventContext->getContract()?->getId() ?? '';

            if (!is_string($checkoutUrl) || $checkoutUrl === '') {
                return PaymentHandlerResult::error(
                    'Mollie did not return a checkout URL for contract ' . $contractId,
                    'MOLLIE_NO_CHECKOUT_URL',
                );
            }

            $this->logger?->info('[MolliePaymentHandler] Mollie checkout session created', [
                'contractId' => $contractId,
            ]);

            return PaymentHandlerResult::success(
                contractId: $contractId,
                clientSecret: null,
                metadata: [
                    'handler' => 'mollie',
                    'requiresRedirect' => true,
                    'redirectUrl' => $checkoutUrl,
                ],
            );
        } catch (Throwable $e) {
            $this->logger?->error('[MolliePaymentHandler] processPayment failed', [
                'error' => $e->getMessage(),
            ]);

            return PaymentHandlerResult::error(
                'Mollie payment processing failed: ' . $e->getMessage(),
                'MOLLIE_PAYMENT_FAILED',
            );
        }
    }

    /**
     * Mollie confirms the outcome via the redirect return + webhook, so there is nothing to do
     * here — mirrors PayPal's/Stripe's OPC handlers.
     */
    public function confirmPayment(string $transactionId): PaymentHandlerResult
    {
        return PaymentHandlerResult::success(
            contractId: $transactionId,
            metadata: ['note' => 'Mollie confirms via redirect return + webhook'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getFrontendConfig(): array
    {
        // IFRAME-03: Mollie is redirect-only. Even when the payment-base "Use iframe instead of
        // checkout button" flag is ON, Mollie's hosted checkout page sends `X-Frame-Options: DENY`
        // and CSP `frame-ancestors 'self'` (verified 2026-07-29), so browsers refuse to render it
        // in a cross-origin iframe on the shop. We therefore always advertise redirect mode and,
        // when the flag is on, log the fallback once — never emitting an iframe renderMode that
        // would produce a blank/broken embed for the customer.
        $this->logIframeFallbackIfRequested();

        $config = [
            'type' => 'mollie',
            'renderMode' => 'redirect',
            'requiresRedirect' => true,
        ];

        // When the merchant enabled inline checkout, OPC loads Mollie's OWN footer widget (inline
        // method selector + Mollie Components card) instead of the generic redirect button. The
        // payment itself still resolves via a redirect (hosted method page / 3-D Secure), so
        // renderMode stays 'redirect' — only the footer UI changes.
        if ($this->iframeSettings?->isEnabled() ?? false) {
            $config['footerWidget'] = 'molliecheckoutfooter';
        }

        return $config;
    }

    /**
     * Emit a single informational log when the merchant enabled iframe checkout but Mollie cannot
     * honor it (its hosted page forbids framing). No-op when the flag is off.
     */
    private function logIframeFallbackIfRequested(): void
    {
        if ($this->iframeFallbackLogged || !($this->iframeSettings?->isEnabled() ?? false)) {
            return;
        }

        $this->iframeFallbackLogged = true;
        $this->logger?->info(
            '[MolliePaymentHandler] Iframe checkout requested but Mollie\'s hosted page cannot be '
            . 'framed (X-Frame-Options: DENY / frame-ancestors); falling back to redirect.',
        );
    }

    /**
     * Wire the OXID basket to the active user + Mollie payment method so the early-order creation
     * inside the checkout-session event chain (EarlyOrderCreationHandler → finalizeOrder) does not
     * reject with an invalid-delivery/user state. The standard `cl=order` flow gets this from its
     * controller guards; the OPC handler runs before them, so it prepares the basket itself.
     *
     * Overridable seam — unit tests stub it to avoid the OXID Registry.
     */
    protected function prepareOxidBasket(PaymentContextInterface $context): void
    {
        $session = Registry::getSession();
        $basket = $session->getBasket();
        if (!$basket instanceof Basket) {
            return;
        }

        $basket->setPayment(MollieDefinitions::PAYMENT_ID);
        $session->setVariable('paymentid', MollieDefinitions::PAYMENT_ID);

        $user = $context->getUser();
        if (!$user instanceof User || !$user->getId()) {
            $user = $session->getUser();
        }
        if ($user instanceof User && $user->getId()) {
            $basket->setBasketUser($user);
            $_POST['sDeliveryAddressMD5'] = $user->getEncodedDeliveryAddress();
        }

        $basket->calculateBasket(true);
    }

    /**
     * Build the checkout EventContext — same shape
     * {@see \OxidEsales\Payments\Mollie\Controller\MollieOrderController::buildCheckoutContext()}
     * assembles in the standard flow.
     *
     * Overridable seam — unit tests stub it to avoid the OXID Registry.
     */
    protected function buildEventContext(PaymentContextInterface $context): EventContext
    {
        $session = Registry::getSession();
        $user = $context->getUser();
        $userId = $user instanceof User ? (string) $user->getId() : '';
        $params = $this->mollieParamsFromContext($context);

        return new EventContext([
            'paymentId' => MollieDefinitions::PAYMENT_ID,
            'userId' => $userId,
            'basket' => $context->getBasket(),
            'user' => $user,
            'sessionId' => (string) $session->getId(),
            'conditionTypes' => ['payment_authorized'],
            // Same keys the standard cl=order flow sets (MollieOrderController::buildCheckoutContext):
            // MollieCheckoutSessionHandler consumes them to pin the chosen method / tokenize a card.
            'selectedMethod' => $params['selectedMethod'],
            'cardToken' => $params['cardToken'],
        ]);
    }

    /**
     * Read the provider-specific fields carried through OPC's agnostic PaymentContext metadata bag
     * (the Mollie OPC footer widget posts `mollieMethod` / `mollieCardToken`). Pure — no Registry.
     *
     * @return array{selectedMethod: ?string, cardToken: ?string}
     */
    protected function mollieParamsFromContext(PaymentContextInterface $context): array
    {
        return [
            'selectedMethod' => $this->normalizeParam($context->getMetadataValue('mollieMethod')),
            'cardToken' => $this->normalizeParam($context->getMetadataValue('mollieCardToken')),
        ];
    }

    private function normalizeParam(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
