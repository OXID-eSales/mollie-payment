<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use OxidEsales\PaymentBase\EventSystem\EventDispatcherInterface;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\EventSystem\Event\MollieCheckoutSessionRequestEvent;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * THE checkout entry ladder — every way into a Mollie checkout session runs through here
 * (review 2026-10-14, A-H1: the ladder lived twice and had already diverged — MOL-15 and
 * MOL-18 guarded only the standard `cl=order` flow, not the OPC handler).
 *
 * Order of the rungs, and why it is fixed:
 *  1. MOL-22 buyability — an item that turned unorderable since the page rendered is named to
 *     the shopper before anything else; no contract or draft order is created for it. Core's
 *     finalizeOrder() stays the safety net for the race window (see the catch below).
 *  2. MOL-15 user-data gate — the point of no return. Address data is validated with
 *     payment-base's rules for Mollie right before the PSP is called; it can have changed since
 *     the payment step (account page, another tab). BEFORE the replay: data that went bad
 *     between two clicks must be fixed, not replayed into Mollie.
 *  3. MOL-18 in-flight replay — a repeated "Order now" rejoins the attempt already in flight
 *     instead of starting another. Null service = fail-open to "nothing in flight" (older
 *     payment-base without the resolver).
 *  4. Dispatch {@see MollieCheckoutSessionRequestEvent} and read the checkout URL + contract
 *     the handler chain wrote onto the context.
 *
 * Rungs 2 and 3 are session-bound shopper interactions and are skipped on a headless start:
 * payment-base's HeadlessCheckoutService owns validation for API clients, and there is no PHP
 * session whose in-flight attempt could be rejoined.
 *
 * What stays OUT of here, deliberately: the controller's CSRF / AGB / basket-hash guards (core
 * OrderController parity, meaningless for OPC which has its own consent + address guards) and
 * every presentation concern (error slots, redirects, PaymentHandlerResult shapes).
 */
class CheckoutSessionStarter
{
    public function __construct(
        private readonly EventDispatcherInterface $dispatcher,
        private readonly BasketBuyabilityValidator $buyability,
        private readonly CheckoutUserDataGateInterface $userDataGate,
        private readonly ?InFlightCheckoutReplayInterface $replay = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function start(CheckoutStartRequest $request): CheckoutStartOutcome
    {
        $notOrderable = $this->notOrderableFailure($request);
        if ($notOrderable !== null) {
            return CheckoutStartOutcome::notOrderable($notOrderable);
        }

        if (!$request->headless) {
            $problems = $this->userDataGate->problemsFor($request->user);
            if ($problems !== []) {
                return CheckoutStartOutcome::userDataProblems($problems);
            }

            $replayUrl = $this->replay?->checkoutUrl();
            if ($replayUrl !== null) {
                return CheckoutStartOutcome::replay($replayUrl);
            }
        }

        return $this->dispatch($request);
    }

    /**
     * MOL-22: which items of the basket are not orderable right now (null = all fine).
     * Protected as a test seam — entry-point tests script the failure directly.
     */
    protected function notOrderableFailure(CheckoutStartRequest $request): ?NotOrderableCheckoutFailure
    {
        // Fail-open without a basket — core's finalizeOrder() refuses such an item anyway.
        if ($request->basket === null) {
            return null;
        }

        $failures = $this->buyability->validate($request->basket);

        return $failures === [] ? null : NotOrderableCheckoutFailure::fromBuyabilityFailures($failures);
    }

    private function dispatch(CheckoutStartRequest $request): CheckoutStartOutcome
    {
        $context = $this->buildEventContext($request);

        try {
            $this->dispatcher->dispatch(new MollieCheckoutSessionRequestEvent($context));
        } catch (Throwable $e) {
            $this->logger?->error('[CheckoutSessionStarter] checkout session event failed', [
                'error' => $e->getMessage(),
            ]);
            // MOL-22 race window: core refused inside finalizeOrder() because an item is not
            // orderable (out of stock for the ordered quantity, turned unbuyable, removed) —
            // say so, not "unavailable".
            $notOrderable = NotOrderableCheckoutFailure::fromThrowable($e);
            if ($notOrderable !== null) {
                return CheckoutStartOutcome::notOrderable($notOrderable);
            }

            return CheckoutStartOutcome::unavailable();
        }

        $contractId = (string) ($context->getContract()?->getId() ?? '');

        // Set by MollieCheckoutSessionHandler as a string; anything else means "no checkout".
        $checkoutUrl = $context->get('checkoutUrl', '');
        if (!is_string($checkoutUrl) || $checkoutUrl === '') {
            return CheckoutStartOutcome::unavailable($contractId);
        }

        return CheckoutStartOutcome::ready($checkoutUrl, $contractId);
    }

    private function buildEventContext(CheckoutStartRequest $request): EventContext
    {
        $data = [
            'paymentId' => MollieDefinitions::PAYMENT_ID,
            'userId' => (string) $request->user?->getId(),
            'basket' => $request->basket,
            'user' => $request->user,
            'sessionId' => $request->sessionId,
            'conditionTypes' => ['payment_authorized'],
            // IFRAME-04: the inline method selection + (for card) the Mollie Components token.
            // Both null for the classic redirect flow.
            'selectedMethod' => $request->selectedMethod,
            'cardToken' => $request->cardToken,
        ];

        // GRAPH-QL / MS1: EarlyOrderCreationHandler builds the order from `basketId`,
        // MollieCheckoutSessionHandler sends the shopper back to `returnUrl`.
        if ($request->headless) {
            $data['headless'] = true;
            $data['basketId'] = $request->basketId;
            $data['returnUrl'] = $request->returnUrl;
        }

        return new EventContext($data);
    }
}
