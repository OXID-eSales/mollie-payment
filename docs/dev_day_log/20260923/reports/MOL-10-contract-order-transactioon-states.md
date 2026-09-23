# MOL-10 — Contract, order and transaction states across Mollie, Stripe and PayPal

**Date:** 2026-09-23
**Ticket:** MOL-10
**Status:** REPORT (analysis only, no code changed)
**Scope:** payment-base `ContractState`, OXID `oxorder.OXTRANSSTATUS` / `OXPAID`, the Mollie audit
transaction rows, and how the three PSP status models (Mollie, Stripe, PayPal) map onto them.

## 1. Question

Mollie test-mode checkout offers a different set of simulated payment statuses per method (iDEAL:
Open/Paid/Failed/Canceled/Expired; PayPal: Pending instead of Open; Bank transfer: no Failed/Canceled;
eps, KBC/CBC, Bancomat Pay: neither Open nor Pending; Pay by Bank: all six). Two questions followed:

1. What is the difference between Mollie `open`, `pending` and `authorized`, and do Stripe / PayPal
   have equivalents?
2. How do those provider statuses align with the payment-base contract lifecycle, the OXID order
   fields and the transaction history rows?

## 2. Mollie statuses

Mollie payments have seven statuses: `open`, `pending`, `authorized`, `paid`, `canceled`, `expired`,
`failed`. The three non-final ones differ in who has committed:

| Mollie status | Who has committed | Money | Next |
|---|---|---|---|
| `open` | nobody — customer has not completed their side | none promised | paid, pending, canceled, failed, expired |
| `pending` | customer — bank/scheme has not confirmed | expected, not guaranteed | paid or failed |
| `authorized` | customer — funds reserved for the merchant | guaranteed, not collected | paid (after capture), canceled (released), expired |

`authorized` only exists for methods with separate authorization and capture: cards with
`captureMode: manual`, Klarna, Riverty, Billie. None of the methods listed in the ticket support it,
which is why "Authorized" never appears in the test screens.

### Why the test-mode option lists differ per method

The Mollie test checkout only offers statuses that the method can really emit via webhook:

- **Instant redirect methods** (iDEAL, Bancontact, eps, KBC/CBC, Bancomat Pay) confirm synchronously,
  so `pending` is not offered. Some drop `open` too because the simulation screen *is* the open state.
- **Asynchronous methods** (PayPal, Przelewy24) confirm later, so they offer `pending` instead of `open`.
- **Pay by Bank** (open banking) has both an unstarted and an initiated-but-unconfirmed state, so all
  six appear.
- **Bank transfer** is offline: the customer cannot fail or cancel it; it is paid within the due date or
  it expires.
- **Card** has no customer-initiated cancel in Mollie's model; abandoned 3DS ends as failed or expired.

Consequence: no test or admin logic may assume that every method can reach every status.

## 3. Stripe and PayPal equivalents

| Mollie | Stripe PaymentIntent | PayPal Orders v2 |
|---|---|---|
| open | `requires_payment_method`, `requires_confirmation`, `requires_action` (Checkout Session status is literally `open`) | order `CREATED` / `PAYER_ACTION_REQUIRED` |
| pending | `processing` (SEPA debit, ACH, slow bank redirects) | capture `PENDING` (eCheck, risk review), authorization `PENDING` |
| authorized | `requires_capture` (`capture_method: manual`) | order `APPROVED` with intent `AUTHORIZE`, authorization `CREATED` |
| paid | `succeeded` | capture `COMPLETED` |
| canceled | `canceled`, `cancellation_reason` = `requested_by_customer` / `abandoned` / `duplicate` / `fraudulent` | order or authorization `VOIDED` |
| expired | `canceled`, `cancellation_reason` = `automatic` (uncaptured auth, ~7 days); Checkout Session `expired` | authorization `EXPIRED` (29 days, 3-day honor period) |
| failed | no distinct status; intent returns to `requires_payment_method` with `last_payment_error`; event `payment_intent.payment_failed` | capture `DECLINED` / `FAILED`, authorization `DENIED` |

Two structural differences:

- **Stripe has no terminal "failed".** A declined card leaves the intent retryable. The integration
  decides when it is dead (Checkout Session expiry or explicit cancel).
- **PayPal splits status across three objects** (order, authorization, capture). Mollie and Stripe put
  everything on one payment object.

## 4. The three internal layers

### 4.1 Contract (`payment-base/src/Contract/ContractState.php`)

Ladder: `draft → not_finished → pending → authorized → ready_to_commit → committed → fulfilled`.
Terminals: `cancelled`, `expired`, `failed`. `authorized` is optional (two-step methods only).

Transitions (`PaymentContract`): `transitionToNotFinished(orderId)`, `transitionToPending()`
(from `not_finished` only), `authorize()` (from `pending` only), `captureAuthorization()`
(`authorized → ready_to_commit`), `fulfillCondition('payment_authorized')` (`pending → ready_to_commit`
once all conditions are met), `commitToOrder(orderId)`, `fulfill()`, `cancel()`, `fail()`, `expire()`.
`expire()` refuses on `committed` (STRP-168); `cancel()`/`fail()` refuse on terminal states only.

### 4.2 Order (`oxorder`)

| Field | Values written by payment-base | Written by |
|---|---|---|
| `OXTRANSSTATUS` | `NOT_FINISHED` (initial, `CreateOrderRequest::initialStatus`), `OK` (on commit), `CANCELLED` (not-finished cleanup / `deleteNotFinishedOrder`) | `OxidShopOrderService`, `ContractCommitmentHandler`, `DoctrineNotFinishedOrderRepository` |
| `OXPAID` | timestamp, once, guarded by `OXPAID = '0000-00-00 00:00:00'` | `OrderPaymentStateService` |
| `OXTRANSID` | provider payment id, only if empty | `OrderPaymentStateService` |
| `OXSTORNO` | `1` together with `OXTRANSSTATUS = 'CANCELLED'` | `DoctrineNotFinishedOrderRepository` |
| `OXFOLDER` | `ORDERFOLDER_NEW` on creation | `OxidShopOrderService` |

The order therefore knows only three payment states: not finished, OK, cancelled. It does not
distinguish pending from open, authorized from paid, or expired/failed from cancelled. That is by design:
the contract is the source of truth; `oxorder` is a projection.

### 4.3 Transaction history rows (Mollie audit, `MollieDefinitions`)

Types: `capture`, `authorization`, `failure`, `expiration`, `cancellation`, `chargeback` (plus refunds via
`PaymentRefundedHandler`). Statuses: `completed`, `failed`. Written by
`WebhookContractFulfillmentHandler` and `ChargebackCreatedHandler`, one row per webhook outcome.

## 5. Alignment table

| Contract state | Meaning | Mollie | Stripe PaymentIntent | PayPal | `OXTRANSSTATUS` | Mollie transaction row |
|---|---|---|---|---|---|---|
| `not_finished` | order row exists, customer redirected, no commitment | `open` | `requires_payment_method`, `requires_confirmation`, `requires_action` | order `CREATED`, `PAYER_ACTION_REQUIRED` | `NOT_FINISHED` | none |
| `pending` | customer committed, network unconfirmed | `pending` | `processing` | capture `PENDING`, authorization `PENDING` | `NOT_FINISHED` | none |
| `authorized` | funds reserved, merchant must capture | `authorized` | `requires_capture` | order `APPROVED` with intent AUTHORIZE, authorization `CREATED` | `NOT_FINISHED` | `authorization` / `completed` |
| `ready_to_commit` | money taken, order not yet committed | `paid` | `succeeded` | capture `COMPLETED` | `NOT_FINISHED` | `capture` / `completed` |
| `committed`, `fulfilled` | shop-internal only | none | none | none | `OK`, `OXPAID` set | none |
| `cancelled` | someone stopped it | `canceled` | `canceled` with reason `requested_by_customer`, `abandoned`, `duplicate`, `fraudulent` | `VOIDED` | `CANCELLED`, `OXSTORNO=1` | `cancellation` / `failed` |
| `expired` | window ran out | `expired` | `canceled` with reason `automatic`, or `checkout.session.expired` | authorization `EXPIRED` | `CANCELLED`, `OXSTORNO=1` | `expiration` / `failed` |
| `failed` | attempt rejected | `failed` | `payment_intent.payment_failed` event, no status | capture `DECLINED`/`FAILED`, authorization `DENIED` | `CANCELLED`, `OXSTORNO=1` | `failure` / `failed` |

Rule of thumb: Mollie `open` and Stripe `requires_*` are `not_finished`; Mollie `pending` and Stripe
`processing` are `pending`; Mollie `authorized`, Stripe `requires_capture` and PayPal authorization
`CREATED` are `authorized`.

## 6. Where the modules diverge today

1. **Mollie collapses `open` and `pending`.** `MollieStatusMapper::map()` returns one
   `MollieOutcome::PENDING` for both, and `MollieReturnResolver` reports that as an unsuccessful
   `ReturnResolution` (`OUTCOME_PENDING` is not in `isSuccessful()`). `ContractPendingTransitioner` only
   moves `not_finished → pending` on a successful return, so a customer who paid via PayPal, Przelewy24 or
   Pay by Bank and is waiting on the bank leaves the contract in `not_finished`. The `paid` webhook later
   climbs from `not_finished`, which works, but the intermediate state is wrong.
2. **Stripe collapses four statuses into pending.** `StripeStatusMapper::STRIPE_TO_NORMALIZED` maps
   `requires_payment_method`, `requires_confirmation`, `requires_action` and `processing` all to
   `NormalizedPaymentStatus::PENDING`. Only `processing` means the customer committed. Every `canceled`
   maps to `CANCELLED`; there is no path to contract `expired` (`cancellation_reason: automatic`,
   `checkout.session.expired`).
3. **PayPal maps straight to contract states and skips distinctions.** `PayPalStatusMapper` sends
   `APPROVED` to `authorized` regardless of intent (for intent `CAPTURE` it means "approved, capture
   now"), sends capture `COMPLETED` to `committed` (a shop-internal state), and folds authorization
   `EXPIRED` into `cancelled`. It has no callers outside tests, so it documents intent, not behaviour.
4. **`NormalizedPaymentStatus` cannot express the split.** It has `PENDING`, `AUTHORIZED`, `CAPTURED`,
   `FAILED`, `CANCELLED`, `REFUNDED`, `PARTIALLY_REFUNDED` — no OPEN, no EXPIRED. Only the Stripe module
   uses it; Mollie has its own `MollieOutcome`, PayPal its own string map.
5. **Not-finished cleanup ignores the distinction.** `NotFinishedOrderCleanupService::isCancellable()`
   cancels any contract that is neither terminal nor `committed`, so `pending` and `authorized`
   contracts are cancellable too. Refining the mappers only helps if the cleanup also skips those states
   and lets the provider's own expiry drive `expired`.
6. **Order projection is coarse on purpose, but lossy for support.** Cancelled, expired and failed all
   land as `OXTRANSSTATUS = 'CANCELLED'`; only the transaction row (Mollie) or the contract tells them
   apart. Stripe and PayPal do not write equivalent typed rows.

## 7. Recommendation

1. **One provider-neutral outcome enum in payment-base** — values `OPEN`, `PENDING`, `AUTHORIZED`,
   `CAPTURED`, `CANCELLED`, `EXPIRED`, `FAILED`, `IGNORED`. Extend `NormalizedPaymentStatus` with `OPEN`
   and `EXPIRED` so the string constants can carry the same set. `MollieOutcome` is already this shape
   minus the open/pending split and can move up largely unchanged; Stripe and PayPal mappers retarget it.
2. **One transition rule table in payment-base**, keyed by that outcome, replacing the per-module
   "ladder" code in `WebhookContractFulfillmentHandler` (Mollie) and its Stripe twin:
   `OPEN` → no-op; `PENDING` → `transitionToPending`; `AUTHORIZED` → pending then `authorize`;
   `CAPTURED` → `fulfillCondition('payment_authorized')` then `commitToOrder`; `CANCELLED` / `EXPIRED` /
   `FAILED` → the matching terminal transition, keeping the existing "settled money is never rewritten"
   guards.
3. **Treat a pending return as its own outcome, not a failure.** `ReturnResolution::OUTCOME_PENDING`
   exists but nothing consumes it. `ContractPendingTransitioner` should handle it (`not_finished →
   pending`) and the storefront should show "payment in progress" rather than the payment step. Mollie's
   `PendingReturnProbe` / `MollieOrderController::onReturnPending()` already does the UI half.
4. **Derive `expired` for Stripe** from `canceled` + `cancellation_reason: automatic` and from
   `checkout.session.expired`; everything else under `canceled` stays `cancelled`.
5. **Make the PayPal mapper read the nested objects** — order intent decides whether `APPROVED` is
   `authorized` (AUTHORIZE) or still `pending` capture (CAPTURE); capture and authorization statuses drive
   pending / captured / expired / failed. Wire it in or delete it.
6. **Cleanup excludes `pending` and `authorized`.** Those contracts have a customer commitment; only the
   PSP's `expired` / `failed` webhook or a documented deadline should end them.
7. **Typed transaction rows for every provider.** Adopt the Mollie type/status vocabulary
   (`capture`, `authorization`, `failure`, `expiration`, `cancellation`, `chargeback`, `refund` ×
   `completed` / `failed`) in payment-base so admin history reads the same for all three PSPs.

## 8. Files referenced

- `payment-base/src/Contract/ContractState.php`, `payment-base/src/Contract/PaymentContract.php`
- `payment-base/src/Adapter/Response/NormalizedPaymentStatus.php`
- `payment-base/src/Return/ReturnResolution.php`, `payment-base/src/Controller/CheckoutReturnResponder.php`,
  `payment-base/src/EventSystem/Handler/ContractPendingTransitioner.php`
- `payment-base/src/Service/OrderPaymentStateServiceInterface.php`, `payment-base/src/Adapter/OxidShopOrderService.php`,
  `payment-base/src/Service/NotFinishedOrderCleanupService.php`, `payment-base/src/Repository/DoctrineNotFinishedOrderRepository.php`
- `mollie-payment/src/Mollie/Adapter/MollieStatusMapper.php`, `mollie-payment/src/Mollie/Adapter/MollieOutcome.php`,
  `mollie-payment/src/Mollie/Service/Return/MollieReturnResolver.php`, `mollie-payment/src/Mollie/Service/Return/PendingReturnProbe.php`,
  `mollie-payment/src/Mollie/Webhook/Handler/WebhookContractFulfillmentHandler.php`, `mollie-payment/src/Mollie/Core/MollieDefinitions.php`
- `stripe/src/Stripe/Adapter/StripeStatusMapper.php`, `stripe/src/Stripe/Service/Return/StripeReturnResolver.php`
- `paypal/src/PayPal/Adapter/PayPalStatusMapper.php`, `paypal/src/PayPal/Service/Return/PayPalReturnResolver.php`
