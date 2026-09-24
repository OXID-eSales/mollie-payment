# Sprint: Refund action after a successful payment — no lost update between return leg and webhook

**Date:** 2026-09-24
**Ticket:** (id to be filled in) — paid Pay by Bank / EPS orders offer no refund in admin
**Report:** `../reports/refund-availability-after-paid.md` (root cause: a lost update; not method-specific)
**Repos:** payment-base (concurrency guard, return leg, reconciliation) and mollie-payment (webhook side,
captured amount, e2e, docs). Branches on approval: `b-7.4.x-refund-after-paid-lost-update` in both repos.
**Status:** PLANNED — **do not start without the product owner's approval.**
**Definition of Done (sprint-level):** an order paid through any Mollie method ends with contract state
`fulfilled` and a Refund form in the admin Payment tab no matter whether Mollie's `paid` webhook arrives
before, during or after the shopper's return; a newer contract state is never overwritten by an older
in-memory copy; contracts already stuck at `committed` with a paid Mollie payment are repaired by a command.

## Root cause (from the report)

Return leg and `paid` webhook each load the contract, mutate their own copy and save the whole row with no
lock or version check. Webhook writes `fulfilled` first, return leg overwrites with `committed`. The Refund
form requires `fulfilled`. Timing decides which methods are hit; the ticket's Pay by Bank / EPS are examples,
this run's iDEAL / PayPal / KBC are others.

## Approach

| Principle | Application |
|---|---|
| Fix the cause | make the *second* writer notice the *first*: optimistic concurrency on the contract row (payment-base), then let each writer react correctly |
| TDD-first | Story 1 lands a red integration test that reproduces the lost update with two repository-loaded instances, and a red deterministic e2e (webhook forced to win) |
| SRP | version check lives in the repository; "what to do when stale" lives in the two writers (responder, webhook handler); repair lives in a command |
| DIP / ISP | one new exception type; `ContractRepositoryInterface::save()` gains a documented `@throws`, no new method; the reconciliation reads Mollie through the existing `MolliePaymentsAdapterInterface` |
| DRY | the webhook's existing `handlePaymentPaid()` ladder is the one fulfilment path — the reconciliation command and the stale-retry both call it |
| No overengineering | no row locks / transactions spanning HTTP, no queue, no new state; a version column and two catch blocks |
| Independence | Stripe / PayPal inherit Stories 2–3 through payment-base and need no change |
| DevOps-first | both modules' gates (phpcs, phpstan max, phpmd, Unit, Integration), `mollie-standard` e2e, standalone unit suite |

Alternative considered and rejected: `SELECT … FOR UPDATE` around each writer. The return leg loads the
contract in the controller and mutates it across a chain of handlers and a Mollie API round-trip; a lock
that long is a lock across HTTP. Optimistic versioning costs one column and detects exactly the case at hand.

## Out of scope
- MOL-18 replay ignoring a changed inline method (own ticket).
- Przelewy24 e-mail step on Mollie's test page (e2e helper).
- Webhook claim-before-process making a *failed* delivery unrecoverable (Story 3 fixes the retry path for
  the stale case only; a general "failed claims are re-claimable" change is noted as follow-up — small, but
  it changes the idempotency contract of every provider and deserves its own red test set).

## Risks
- **Migration on a live table.** `OXVERSION INT NOT NULL DEFAULT 0` is additive; existing rows start at 0.
- **Every save site now can throw.** Callers that ignore the exception behave as before (unhandled → 500);
  the two concurrent writers handle it. Grep for `->save($contract)` in both repos in Story 2 and list the
  sites in the sprint doc; only the responder chain and the webhook handlers need behaviour.
- **Retry loop.** The webhook re-runs its ladder once; a second stale hit returns `Failed` (non-200) and is
  logged — Mollie retries later. Fine, because a fresh delivery re-loads the row.

---

## Story 1 — Prove it: red integration test for the lost update, red deterministic e2e

**Repos:** payment-base (integration), mollie-payment (e2e) · **Estimate:** S

**Tests first:**
- `payment-base/tests/Integration/Repository/ContractLostUpdateTest.php`:
  `testASaveFromAStaleCopyDoesNotOverwriteANewerState` — save contract in `pending`; load copy R (return leg)
  and copy W (webhook); on W: `fulfillCondition` → `commitToOrder` → `fulfill` → save; on R: `fulfillCondition`
  → `commitToOrder` → save. Assert the row reads `fulfilled` and `OXFULFILLEDAT` is set. RED today
  (`committed`, NULL). A second test pins that R's save raises `StaleContractException` (Story 2).
- `mollie-payment/tests/e2e/playwright/tests/MollieStandard/RefundAfterWebhookWinsRace.spec.ts`: pay with one
  redirect method; hold the return request (`page.route` on `fnc=checkoutReturn` delaying 8 s) so the `paid`
  webhook is guaranteed to land first; then admin → Payment tab must show the Refund form and the contract
  must be `fulfilled` (DB helper). RED today.
- Promote the diagnostic `RefundAvailabilityMatrix.spec.ts` into an asserting spec later (Story 6), keep it
  as diagnostic for now.

**Definition of Done:** both proofs red for the lost-update reason.

## Story 2 — payment-base: optimistic concurrency on the contract row

**Estimate:** M

**Tests first:**
- `tests/Unit/Repository/DoctrineContractRepositoryVersioningTest.php` (fake DBAL connection): insert writes
  `OXVERSION = 0`; update runs `… WHERE OXID = :id AND OXVERSION = :expected` and sets `OXVERSION = expected+1`;
  0 affected rows on an existing id → `StaleContractException` carrying contract id, expected and current
  version; hydration reads the column; `PaymentContract::getVersion()` increments after a successful save.
- Story 1's integration test: the stale save throws; the row stays `fulfilled`.

**Implementation:**
1. Migration `migration/data/Version20260924…php`: `ALTER TABLE oe_payments_contract ADD OXVERSION INT NOT NULL DEFAULT 0`.
2. `PaymentContract`: private `int $version = 0`, `getVersion()`, `toArray()['version']`, `fromArray`.
3. `Repository\StaleContractException` (extends `RuntimeException`).
4. `DoctrineContractRepository::saveContract()`: drop the `SELECT COUNT(*)`; try `UPDATE … WHERE OXID AND
   OXVERSION`; if 0 rows: `SELECT OXVERSION WHERE OXID` — missing → INSERT (version 0), present → throw. Bump
   the in-memory version after a successful write (private property via the existing reflection helper).
5. `ContractRepositoryInterface::save()`: `@throws StaleContractException`.

**SOLID/Clean:** one reason to change per class; no new interface method; ≤25-line methods.
**Definition of Done:** a stale copy can no longer overwrite a newer row; all existing repository tests green.

## Story 3 — payment-base: the return leg yields to a newer truth; the webhook retries once

**Estimate:** M

**Tests first:**
- `tests/Unit/Controller/CheckoutReturnResponderTest.php`: when a handler in the chain throws
  `StaleContractException`, the responder reloads the contract; if it is `committed` or `fulfilled` (or has an
  order id), `respond()` returns that order id and writes `sess_challenge` (thank-you works) without a second
  dispatch; if it is still open, the chain runs once more with the fresh instance; a second stale failure
  returns null (existing "resolver failure" path).
- Mollie `tests/Unit/Webhook/Handler/WebhookContractFulfillmentHandlerTest.php` (or the existing
  PaymentPaidHandler test): `handlePaymentPaid` catches `StaleContractException`, re-loads, re-runs the ladder
  once; a second stale → `FulfillmentOutcome::Failed`; same for authorized / failed / expired / canceled paths
  through one private `withFreshContract()` helper.

**Implementation:**
1. `CheckoutReturnResponder::respond()`: wrap dispatch in `try … catch (StaleContractException)`, private
   `settleOnNewerContract()` → `ContractRepositoryInterface::findById()`; decide as above; log one info line
   ("return leg yielded to the webhook").
2. `WebhookContractFulfillmentHandler`: private `attemptTwice(callable)` around each `handle*()` body.
3. payment-base `docs/validation-system.md`-style note in `docs/engineering/contract-concurrency.md`: the two
   writers, the version column, what each writer does when stale.

**Definition of Done:** Story 1's integration test and e2e GREEN; both orderings (webhook first / return
first) end `fulfilled` with a Refund form; Stripe / PayPal return specs still green (they use the responder).

## Story 4 — Repair what is already stuck: reconciliation command

**Repos:** mollie-payment (command), payment-base (nothing new) · **Estimate:** S

**Tests first:**
- `tests/Unit/Command/ReconcilePaidContractsCommandTest.php`: for each `committed` Mollie contract with a
  provider order id, the command reads the Mollie payment; `paid` → `handlePaymentPaid()` is invoked and the
  result counted; anything else → listed, untouched; `--dry-run` lists only; unreachable Mollie → logged, skipped.
- Integration: a `committed` contract with a CAPTURE row and a stubbed `paid` payment ends `fulfilled`.

**Implementation:**
1. `src/Mollie/Command/ReconcilePaidContractsCommand.php` (`oe:mollie:reconcile-paid`, options `--dry-run`,
   `--limit`), registered like `CleanupNotFinishedOrdersCommand` in payment-base (console tag). Uses
   `ContractRepositoryInterface` (needs `findByState('committed', provider)` — check whether an existing
   finder fits; if not, one method on a small new `ContractStateQueryInterface`, ≤2 methods),
   `MolliePaymentsAdapterInterface::getPayment()`, `WebhookContractFulfillmentHandler::handlePaymentPaid()`.
2. Run it on this shop for the report: expected to repair 655, 657, 662, 649, 640, 638, 613, 607, 605 (+ the
   earlier ones from the count of 34 `committed`; manual-capture card holds stay).

**Definition of Done:** the stuck orders show the Refund form; the command is idempotent.

## Story 5 — Mollie: record the captured amount on the paid path

**Estimate:** S

**Tests first:** `WebhookContractFulfillmentHandlerTest`: after `handlePaymentPaid`, `getCapturedAmount()`
equals the payment amount; `MolliePanelViewDataBuilderTest`: "Captured" shows the amount for an
auto-captured fulfilled contract.

**Implementation:** in `handlePaymentPaid()`, after `fulfill()`, `CaptureRefundTracker::addCapturedAmount()`
(or `PaymentContract::setCapturedAmount()`) with the contract amount, saved once with the fulfilment. Keep the
audit row as is. Purely additive; the refund gate does not read it, merchants do.

## Story 6 — Prove, regress, document, CI

**Estimate:** S

- `RefundAvailabilityMatrix.spec.ts` becomes an asserting spec (every offered method ends `fulfilled` with a
  Refund form; Przelewy24 and card explicitly skipped with reasons), tagged so the standard run stays fast
  (`--grep-invert @slow`), run once for the report.
- `mollie-standard` regression, payment-base Unit + Integration, Mollie Unit + Integration + standalone suite.
- Changelogs (payment-base: Added `OXVERSION` / `StaleContractException`, Fixed lost update; Mollie: Fixed
  refund after paid, captured amount, Added reconcile command), report update, done record, status.
- Push both branches; payment-base CI green first (Mollie CI cannot pin a payment-base feature branch —
  `composer.json` requires `>=v1.2`; see MOL-18 report), then Mollie CI; **no merge without approval**.

## Suggested order
1 → 2 → 3 (e2e green) → 4 (repair this shop, evidence) → 5 → 6.

## Open questions for the approver (defaults in bold)
- Ticket id for file names and commits: **fill in** (files currently use `refund-availability-after-paid`).
- Stuck contracts on merchants' shops: **console command** (Story 4) vs. also a lazy self-heal when the admin
  opens the Payment tab (rejected by default: a read should not write).
- Story 5 (captured amount cosmetics): **in this sprint** vs. separate ticket.
