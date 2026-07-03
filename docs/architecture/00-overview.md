# Mollie Module — Architecture Overview

**Date:** 2026-07-03
**Target:** OXID eShop 7.4+, PHP 8.2+
**Module id:** `oe_payments_mollie` (`MollieDefinitions::MODULE_ID` === `MollieDefinitions::PAYMENT_ID`)

---

## Module relationship

```
┌───────────────────────────────────────────────────────────────────────┐
│                    oxid-esales/mollie-payment                         │
│  (Provider-specific implementation — this module)                     │
│                                                                       │
│  ┌──────────────────────────────────────────────────────────────────┐│
│  │  Implements : SessionAdapterInterface, ShopAdapterInterface,      ││
│  │               ShopOrderServiceInterface, HandlerInterface[]       ││
│  │  Extends    : ContractCreationHandler (payment-base)              ││
│  │  Wraps      : mollie/mollie-api-php ^2                            ││
│  └──────────────────────────────────────────────────────────────────┘│
│                              ↓ uses                                   │
├───────────────────────────────────────────────────────────────────────┤
│                        oxid-esales/payment-base                       │
│  (Provider-agnostic core, shared with Stripe and PayPal)              │
│                                                                       │
│  ┌──────────────────────────────────────────────────────────────────┐│
│  │  Defines : PaymentContract state machine, EventDispatcher,        ││
│  │            ContractRepository, TransactionRepository,             ││
│  │            WebhookLogRepository, central validation subsystem     ││
│  │  Tables  : oe_payments_contract, oe_payments_transaction,         ││
│  │            oe_payments_webhooklogs (see payment-base migrations)  ││
│  └──────────────────────────────────────────────────────────────────┘│
└───────────────────────────────────────────────────────────────────────┘
```

Three payment modules (Stripe, PayPal, Mollie) share one `payment-base` package. Mollie is the
**redirect-only** provider of the three: there is no client-side SDK/embedded widget — the
customer is sent to a Mollie-hosted checkout page and comes back to one return URL regardless of
outcome.

## Smart-Contract payment flow

"Place Order" does **not** create an OXID order directly — it creates a **contract** first. The
order is minted shortly after, in `NOT_FINISHED` state, so an order number exists before Mollie's
create-payment API call (Mollie shows this number as the payment description in its dashboard).

```
Customer selects Mollie + a method (iDEAL, card, …) and clicks "Continue"
  → MolliePaymentController::execute() dispatches MollieCheckoutSessionRequestEvent
      → MollieContractCreationHandler   (priority 100) — DRAFT contract, method+payment id metadata
      → EarlyOrderCreationHandler       (priority 90, payment-base) — NOT_FINISHED oxorder, order number
      → MollieCheckoutSessionHandler    (priority 10)  — Mollie create-payment API call;
                                                          F14 host-check on the returned checkoutUrl
  → 302 redirect to Mollie's hosted checkout (`checkoutUrl`)
Customer pays (or cancels/times out) on Mollie's page
  → Mollie 302s back to ONE redirect URL for every outcome:
    index.php?cl=MollieOrderController&fnc=checkoutReturn&contract_id=…&contract_token=…
  → MollieOrderController validates the HMAC contract_token (F20), loads the contract,
    calls MollieReturnResolver, and — on success — dispatches the shared checkout-return chain
Mollie sends a webhook (payment id only, no signature)
  → MollieWebhookController → guard chain (HTTPS/payload-size/rate-limit)
  → MollieWebhookProcessor: verification IS the re-fetch of the payment from Mollie's API (F18)
  → status-specific handler (PaymentPaidHandler, PaymentFailedHandler, …)
  → contract → committed → fulfilled; OXPAID set
```

See [04-webhook-processing.md](./04-webhook-processing.md) for why Mollie has no signature step
at all, and [docs/security/f-matrix.md](../security/f-matrix.md) for the full security parity
sweep this design was measured against.

## Contract lifecycle

```
      ┌─────────┐
      │  DRAFT  │  contract created; no oxorder yet
      └────┬────┘
           │ EarlyOrderCreationHandler creates oxorder
           ▼
 ┌───────────────────┐
 │   NOT_FINISHED    │  oxorder exists, order_number usable
 └─────────┬─────────┘
           │ transitionToPending (checkout-return chain, or webhook if return never lands)
           ▼
      ┌─────────┐
      │ PENDING │  Mollie payment created, awaiting outcome
      └────┬────┘
           │ webhook: payment authorized (two-step methods only, e.g. card w/ manual capture)
           ▼
    ┌────────────┐
    │ AUTHORIZED │  admin can capture (Sprint 6 CaptureService)
    └─────┬──────┘
          │ captureAuthorization()
          ▼
 ┌─────────────────┐
 │ READY_TO_COMMIT │  captured, awaiting commit
 └────────┬────────┘
          │ webhook: payment paid (auto-capture is the common path straight from PENDING)
          ▼
     ┌───────────┐
     │ COMMITTED │  order flipped OK
     └─────┬─────┘
           ▼
     ┌───────────┐
     │ FULFILLED │  terminal success — OXPAID set, refunds now allowed
     └───────────┘

Alternative endings: CANCELLED · EXPIRED · FAILED (payment failed/expired/canceled webhook,
or F14's untrusted-redirect-host guard failing the contract before checkout even starts)
```

Auto-capture (the module default, `sMollieCaptureMode=automatic`) means every payment goes
`PENDING → COMMITTED → FULFILLED` on the single `paid` webhook.

Manual/two-step capture (`sMollieCaptureMode=manual`): Mollie's `authorized` webhook status is
handled by `PaymentAuthorizedHandler`, which drives `WebhookContractFulfillmentHandler::handlePaymentAuthorized()`
to advance the contract `NOT_FINISHED/PENDING → AUTHORIZED` (never setting OXPAID or fulfilling —
funds aren't captured yet). This is the state `CaptureService`/`CancelAuthorizationService` and the
admin panel's capture/cancel actions require. The checkout-return path still does not dispatch the
shared `PaymentAuthorizedEvent` for Mollie (a deliberate choice, documented in `services.yaml`, to
leave commit/fulfillment entirely to the webhook) — the `authorized` webhook is the sole path to
this transition, mirroring how `paid` is the sole path to COMMITTED/FULFILLED.

## Where things live

| Concern | Key file |
|---------|----------|
| State machine, event dispatch, guard chain primitives | `payment-base` package (see [payment-base's own docs]) |
| Mollie SDK wrapping (the only two classes allowed to import the SDK) | `src/Mollie/Adapter/{MollieAdapter,MollieClientFactory}.php` |
| Lazy, ISP-segregated adapter interfaces | `src/Mollie/Adapter/Mollie{Payments,Capture,Refund,Webhook,Methods}AdapterInterface.php`, `LazyMollieAdapter.php` |
| Checkout orchestration | `src/Mollie/EventSystem/Handler/MollieCheckoutSessionHandler.php`, `src/Mollie/Service/CheckoutPaymentService.php` |
| Return-URL security | `src/Mollie/Service/ContractTokenService.php` |
| Webhook pipeline | `src/Mollie/Controller/Webhook/*`, `src/Mollie/Webhook/*` |
| Admin panel | `src/Mollie/Admin/*.php`, `src/Mollie/Controller/Admin/OrderActionDispatcher.php`, `views/twig/admin/panel/mollie_panel.html.twig` |
| Frontend method selector | `resources/js/controllers/mollie_checkout_controller.js`, `views/twig/frontend/mollie_methods.html.twig` |
| Logging control | `src/Mollie/Service/Factory/MollieWebhookFileLoggerFactory.php`, `resources/js/debug.js` |

## Deferred / future work

The following were explicitly scoped **out** of Sprint 8 — see the individual for_developer docs
for the rationale, not built as speculative work:

- **Vaulting / mandates (recurring payments).** No first-payment-mandate flow, no
  `MollieCustomerService`. There is no present product requirement for recurring Mollie
  payments; building it now would be speculative (violates the module's own "no
  overengineering" rule). Revisit if/when a merchant requests subscription support.
- **Mollie Connect (OAuth) / multi-merchant onboarding.** No `Controller/Admin/MollieConnect.php`.
  This module targets single-merchant shops with a directly-configured API key
  (`sMollieTestKey`/`sMollieLiveKey`); OAuth onboarding is only needed for a marketplace/
  platform use case, which is out of scope for this module today.

Both are one-sprint-sized additions on top of the existing adapter/event-handler architecture if
prioritized later — see [for_developer/03-extending-the-mollie-module.md](../for_developer/03-extending-the-mollie-module.md)
for the extension points they would use.
