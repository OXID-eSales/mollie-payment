# Refund action missing after a successful Mollie payment — investigation report

**Date:** 2026-09-24
**Ticket:** MOL-17 — "For orders successfully paid using Mollie Pay by Bank / EPS, the OXID admin
does not provide an option to refund the payment."
**Branch:** mollie-payment `b-7.4.x-MOL-17-refund-after-paid-lost-update` (report, sprint plan, diagnostic specs;
implementation follows on the same branch after approval, 2026-09-24)

## Result in one paragraph

The missing refund action is **not tied to the payment method**. The admin Payment tab shows the Refund form
only when the local contract is in state `fulfilled` **and** Mollie reports a refundable remainder. Every
paid order in this investigation had a refundable remainder at Mollie; the ones without a refund form were
stuck at contract state `committed`. They got there through a **lost update between two concurrent writers**:
the shopper's return leg (`cl=order&fnc=checkoutReturn` → payment-base handler chain) and Mollie's `paid`
webhook. Both load the contract, both mutate their own in-memory copy, both save the whole row without a
lock or a version check. When the webhook lands while the return request is still running, the webhook
moves the contract to `fulfilled` (and writes the CAPTURE audit row), and a moment later the return leg
saves its stale copy — state `committed`, `OXFULFILLEDAT = NULL` — over it. The order looks paid (OXPAID
stamped, transaction history shows Mollie's live `paid`), the CAPTURE row exists, but the contract never
reads `fulfilled` again, so the Refund form never renders. Which methods are hit depends only on **timing**:
methods whose `paid` webhook arrives within the same second as the return (instant redirect methods, and any
method in Mollie test mode) lose the race often; in this run iDEAL, PayPal and KBC lost, Pay by Bank and EPS
won — the ticket saw the opposite. Same defect, same fix.

## How the admin decides (code, read-only trace)

```
mollie_panel.html.twig:172   {% if isRefundable %}                       -> Refund form
MolliePanelViewDataBuilder:95 'isRefundable' => $contract->getState()->isFulfilled() && $refundBound > 0.0
AdminActionBounds:43          refundBound = snapshot?->refundableAmount() ?? 0.0        (live Mollie read)
MolliePaymentDto:108-115      refundableAmount = amountRemaining ?? (amountCaptured ?? amount) - refunded - chargedBack
```

- Gate A: contract state must be **exactly `fulfilled`**. `committed` is not enough.
- Gate B: Mollie's `amountRemaining` (or the arithmetic fallback) must be > 0.
- Nothing in `src/` gates refunds by method; the only method lists are labels, manual-capture methods and
  order-data methods. `OXCAPTUREDAMOUNT` / `OXREFUNDEDAMOUNT` are display only.
- Who sets `fulfilled` for an auto-captured payment: **only** the `paid` webhook
  (`WebhookContractFulfillmentHandler::handlePaymentPaid()` → `ContractFulfillmentService::fulfill()`,
  which requires `committed`). The return leg stops at `committed`
  (`ContractCommitmentHandler::commitContractAndDispatch()`).

## Two concurrent writers, no coordination

| | Return leg (shopper's browser) | `paid` webhook (Mollie's server) |
|---|---|---|
| Entry | `HandlesMollieCheckoutReturn::checkoutReturn()` → `loadContract()` (`findById`) | `MollieWebhookProcessor` → `PaymentPaidHandler` → `findByProviderOrderId` |
| Mutations | `ContractPendingTransitioner` → `PaymentAuthorizedEventHandler` (condition, `ready_to_commit`) → `ContractCommitmentHandler` (`commitToOrder`, **save**, `markOrderAsPaid`) | `advanceToCommitted()` (pending → ready → commit, **save**) → `fulfill()` (**save**) → CAPTURE audit row |
| Locking | none | none |
| Save | `DoctrineContractRepository::saveContract()`: `SELECT COUNT(*)` then `UPDATE … WHERE OXID = ?` with **every column** incl. `OXSTATE`, `OXFULFILLEDAT` | same |

`DoctrineContractRepository` has no version column, no `WHERE OXUPDATED = :loaded`, no `SELECT … FOR UPDATE`,
and neither request runs in a transaction (grep: no `beginTransaction` / `transactional` / `FOR UPDATE` in the
contract write paths of payment-base or mollie-payment). The return leg keeps one `PaymentContract` instance
alive across the whole handler chain, loaded *before* the resolver's Mollie round-trip — the window in which
the webhook arrives.

## Evidence

### A. Database signature (this shop, `example`), Mollie contracts created 2026-09-23/24

| nr | OXSTATE | OXCOMMITTEDAT | OXFULFILLEDAT | OXUPDATED | `paid` webhook | capture rows |
|---|---|---|---|---|---|---|
| 663 bancomatpay | fulfilled | 14:11:40 | 14:11:40 | 14:11:40 | processed 14:11:40 | 1 |
| **662 kbc** | **committed** | 14:11:19 | **NULL** | 14:11:19 | processed 14:11:18 | **1** |
| 660 eps | fulfilled | 14:10:50 | 14:10:50 | 14:10:50 | processed 14:10:50 | 1 |
| 659 bancontact | fulfilled | 14:10:30 | 14:10:30 | 14:10:30 | processed 14:10:30 | 1 |
| 658 paybybank | fulfilled | 14:10:09 | 14:10:09 | 14:10:09 | processed 14:10:10 | 1 |
| **657 paypal** | **committed** | 14:09:43 | **NULL** | 14:09:43 | processed 14:09:43 | **1** |
| 656 banktransfer | fulfilled | 14:09:22 | 14:09:24 | 14:09:24 | processed 14:09:24 | 1 |
| **655 ideal** | **committed** | 14:08:58 | **NULL** | 14:08:58 | processed 14:08:58 | **1** |
| 649, 640, 638, 613, 607, 605 (earlier e2e runs) | **committed** | = OXUPDATED | NULL | | processed | 1 each |

Reading: a CAPTURE audit row is written **only after `fulfill()` succeeded** (`handlePaymentPaid()` lines
66-85). Every `committed` row above has that CAPTURE row and a webhook logged `processed` — the webhook *did*
fulfil the contract. The state on disk is nevertheless `committed`, `OXFULFILLEDAT` is `NULL`, and
`OXUPDATED` equals `OXCOMMITTEDAT` (the return leg's timestamps): the return leg's save came last. The
`fulfilled` rows show the other order: `OXFULFILLEDAT = OXUPDATED = webhook time`, the webhook saved last.
Across 2026-09-23/24: 10 of 24 paid Mollie contracts lost the race (all methods mixed; the two
`committed` rows with an `authorized` webhook and OXPAID `0000-00-00` are manual-capture card payments,
correctly awaiting capture).

### B. Matrix run (`tests/MollieDiagnostic/RefundAvailabilityMatrix.spec.ts`, diagnostic, 2026-09-24 14:08)

One order per method offered on the order page, paid with "Paid" on Mollie's test page; then DB, Mollie API
(`GET /v2/payments/{id}`) and the admin Payment tab.

| method | Mollie status / method | `amountRemaining` | contract state | capture row | admin Refund form |
|---|---|---|---|---|---|
| ideal | paid / ideal | 1369.55 EUR | **committed** | yes | **no** (Captured 0.00, history: PAYMENT paid) |
| banktransfer | paid / banktransfer | 116.50 EUR | fulfilled | yes | yes, bound 116.50 EUR |
| paypal | paid / paypal | 116.50 EUR | **committed** | yes | **no** |
| paybybank | paid / paybybank | 116.50 EUR | fulfilled | yes | yes, bound 116.50 EUR |
| bancontact | paid / bancontact | 116.50 EUR | fulfilled | yes | yes, bound 116.50 EUR |
| eps | paid / eps | 116.50 EUR | fulfilled | yes | yes, bound 116.50 EUR |
| kbc | paid / kbc | 223.00 EUR | **committed** | yes | **no** |
| bancomatpay | paid / bancomatpay | 116.50 EUR | fulfilled | yes | yes, bound 116.50 EUR |
| creditcard | skipped (inline Components flow) | | | | |
| przelewy24 | not paid: Mollie's test page asks for an e-mail address first (helper gap, not a shop defect) | | | | |

Gate B was satisfied for **every** paid method (Mollie returned the full amount as `amountRemaining`). The
only discriminator between "refund form" and "no refund form" is Gate A, the contract state: the admin
inspection (`MollieDiagnostic/RefundAdminInspection.spec.ts`, 8 orders) shows the Refund form exactly on the five `fulfilled`
contracts and on none of the three `committed` ones, while every panel shows the live Mollie row as `paid`
and "Captured 0.00 EUR" (see *Not the cause*). Capture / cancel forms: none (auto-captured payments).

### C. Not the cause (ruled out)

- No method-specific refund gate exists in `src/`.
- Mollie does not withhold `amountRemaining` for any of these methods in test mode.
- Webhook delivery: every affected order has its `paid` webhook logged **processed**. (A secondary weakness
  remains: `AbstractWebhookProcessor` claims the event id `tr_xxx:paid` before processing and a delivery that
  ends `failed` keeps the claim, so Mollie's retries are answered "Already processed". Not what happened
  here, but the same class of orders would be unrecoverable if it did.)
- `OXCAPTUREDAMOUNT` is never written on the `paid` path (only by manual `CaptureService`) — the panel shows
  "Captured 0.00" for every auto-captured payment. Cosmetic, ignored by the gate, worth fixing alongside.

## Side findings

- **MOL-18 replay ignores a changed method.** With inline method selection, choosing another Mollie method
  after coming back from Mollie (same basket) replays the first attempt's checkout URL — the in-flight
  resolver compares basket totals only. Correct for Mollie's hosted method page, surprising with a
  preselected method. Follow-up ticket.
- Mollie's test page for Przelewy24 needs an e-mail before the status page; `completeMollieTestPayment()`
  does not handle it.
- The matrix spec is a useful permanent regression once the fix lands ("every offered method ends
  `fulfilled` with a Refund form"); kept as a diagnostic for now, not part of the `mollie-standard` run
  (it is slow and creates one order per method).

## Fix (sprint `../sprints/MOL-17-refund-after-paid-lost-update.md`, implemented 2026-09-24)

Coordinate the two writers instead of hoping they do not overlap: **optimistic concurrency on the contract
row** in payment-base (`OXVERSION`, `UPDATE … WHERE OXVERSION = :loaded`, `StaleContractException`), with the
return leg treating "somebody newer already committed/fulfilled this" as success and the webhook re-running
its ladder once on a fresh copy. Plus a reconciliation command that fulfils the contracts already stuck at
`committed` whose Mollie payment is `paid`, so merchants get their Refund forms back without touching rows
by hand. Stripe and PayPal share the return responder and the repository, so they inherit the fix.

## Implementation results

| Story | Where | Proof |
|---|---|---|
| 1 red proofs | payment-base `ContractLostUpdateTest`; Mollie e2e `RefundAfterWebhookWinsRace` | integration: `committed` / no exception before the fix; e2e: with versioning alone the webhook side was refused ("expected version 4, row is at 5", order 665 stayed `committed`) |
| 2 `OXVERSION` + `StaleContractException` | payment-base `28b84bc` | integration 3 tests green; unit versioning tests |
| 3 return leg yields, webhook retries once | payment-base `fede907`, Mollie `4a847bb` | responder 4 new unit tests, handler 3 new unit tests; e2e green (see status) |
| 4 `mollie:reconcile-paid` | payment-base `330fcb4`, Mollie `44481db` | dry run then real run on this shop: **13 contracts fulfilled** (orders incl. 605, 607, 613, 638, 640, 649, 655, 657, 662, 665), 6 kept (card authorizations awaiting manual capture), 18 skipped (payments of another Mollie account, 404) |
| 5 captured amount on the paid path | Mollie `4a847bb` | handler unit tests; panel shows the amount for new orders |
| 6 regression, docs, CI | both | see `../status.md` |

### Deviations from the plan

- **The webhook side needed the retry more than expected.** With versioning alone, the webhook lost the
  race in one of two runs (order 665): its refused save became a `failed` webhook, and because the event
  id is claimed before processing, Mollie's retry would have been dropped. The in-request retry (Story 3)
  covers it; the general "failed claims are re-claimable" change stays a follow-up.
- **No public `getVersion()` on `PaymentContract`**: PHPMD's public-member limit (50) is exhausted; the
  version travels through `toArray()`.
- **`StaleContractException` is passed to helpers as its message**, not as the object: payment-base's
  PHPStan rule set forbids concrete-class parameters.
- **The race e2e drives the admin check from a fresh browser context**; sharing the storefront page (with
  its request interceptor) hung the admin navigation.
- **A second defect surfaced in the regression** (`OrderRetryAfterUnpaidReturnCreatesNewOrder`, MOL-18): when
  Mollie's `failed` webhook terminates the attempt before the shopper returns, payment-base's
  `PreviousCheckoutAttemptCleaner` refused to retire the terminal contract and therefore never forgot
  `sess_challenge`; the next "Order now" hit `order_exists` and showed "payment via Mollie not available".
  Before MOL-18 the phantom order masked it. Fixed in payment-base (challenge forgotten for settled
  attempts too; MOL-18's "keeps the challenge for a committed attempt" test superseded).
- **Local migration run blocked** behind open transactions left by interrupted integration runs
  (`ALTER TABLE` metadata lock); killed the stale threads. Noted for operations.
