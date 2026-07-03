# 02 — Event System

Mollie reuses payment-base's `EventDispatcher`/`EventListenerProvider` verbatim (same classes
Stripe and PayPal use, bound independently in this module's own `services.yaml` — Symfony
tolerates the same interface being rebound by multiple active modules).

## Checkout-time handler chain

One dispatch of `MollieCheckoutSessionRequestEvent` from `PaymentController::execute()` fans out
to three handlers, ordered by `getPriority()` (DESC — higher runs first):

| Priority | Handler | Tag source | Responsibility |
|---|---|---|---|
| 100 | `MollieContractCreationHandler` | self-reported | Extends payment-base's `ContractCreationHandler` template. Creates the DRAFT contract, stamps `mollie_method`/`payment_id` metadata, dispatches `ContractDraftCompletedEvent`. |
| 90  | `EarlyOrderCreationHandler` (payment-base) | tagged explicitly in `services.yaml` (payment-base doesn't self-tag it) | Creates the `NOT_FINISHED` oxorder synchronously, in response to `ContractDraftCompletedEvent` fired above — so the order number exists before... |
| 10  | `MollieCheckoutSessionHandler` | self-reported | ...this handler calls Mollie's create-payment API, validates the returned `checkoutUrl` host (F14), and writes `checkoutUrl`/`mollie_payment_id` onto the contract + event context. |

`PaymentController::execute()` reads `checkoutUrl` back off the context after dispatch and issues
the 302 redirect. If any handler fails the contract (adapter exception or untrusted redirect
host), no `checkoutUrl` is set and the controller shows a generic "unavailable" error instead.

## Admin action handler chain (capture / refund / cancel)

Admin actions go through the **provider-agnostic broker**, not a direct dispatch, so the panel
code never imports a Mollie-specific event type:

```
MolliePaymentPanelProvider::handleAction('refund'/'capture'/'cancel', …)
  → OrderActionDispatcher (src/Mollie/Controller/Admin/) dispatches an abstract
    RefundRequestedEvent / CaptureRequestedEvent / CancelAuthorizationRequestedEvent
    through EventBrokerInterface (payment-base)
  → EventBroker resolves the contract's provider ("mollie") and asks every registered
    ProviderEventTranslatorInterface (tagged oe.payment.event_translator) to translate
  → MollieEventTranslator::translate() maps the abstract event onto the concrete
    MollieRefundRequestEvent / MollieCaptureRequestEvent / MollieCancelAuthorizationRequestEvent,
    stamping a deterministic idempotency key ({contractId}:{action}[:amount]) that flows through
    to the Mollie SDK's idempotency-key header
  → EventDispatcher dispatches the concrete event
  → MollieRefundRequestHandler / MollieCaptureRequestHandler / MollieCancelAuthorizationRequestHandler
    (tagged payment.event_handler) delegate to RefundService / CaptureService / CancelAuthorizationService
```

This indirection is what lets Stripe, PayPal, and Mollie all react to the *same* admin-panel
action without the panel needing to know which provider owns the order.

## Webhook fulfillment handler chain

`MollieWebhookProcessor` (see [04-webhook-processing.md](./04-webhook-processing.md)) routes a
verified event to exactly one status handler via `!tagged_iterator mollie.webhook_handler`,
chosen by `handledStatuses()`:

| Handler | Handles |
|---|---|
| `PaymentPaidHandler` | `paid` |
| `PaymentAuthorizedHandler` | `authorized` (two-step/manual capture) |
| `PaymentFailedHandler` | `failed` |
| `PaymentExpiredHandler` | `expired` |
| `PaymentCanceledHandler` | `canceled` |
| `PaymentRefundedHandler` | `refunded` (derived — see `MollieWebhookProcessor::determineEventType()`) |
| `ChargebackCreatedHandler` | `chargedback` (derived) |

`MollieStatusMapper` also maps Mollie's `open`/`pending` statuses to their own `MollieOutcome`
value, but no handler is registered for it — a webhook delivering that status falls through
`MollieWebhookProcessor::processEvent()`'s loop and returns `WebhookResult::skipped(...)`. This is
intentional: `open`/`pending` carries no new information the contract doesn't already have.

Adding a new status is **open/closed**: register a new class implementing
`MollieWebhookEventHandlerInterface`, tag it `mollie.webhook_handler`, and
`MollieWebhookProcessor` never needs to change.

## Broker vs. direct dispatch — when to use which

- **Direct `EventDispatcherInterface::dispatch()`**: checkout-time chain and webhook status
  handlers, where the event is already Mollie-concrete by construction.
- **`EventBrokerInterface::dispatch()`**: anywhere the caller only knows the *contract*, not the
  provider (admin panel actions) — the broker resolves the provider and finds the right
  translator so the caller stays provider-agnostic.
