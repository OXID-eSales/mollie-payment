# Sprint MOL-18: One order per checkout attempt — "Order now" clicked several times

**Date:** 2026-09-23
**Ticket:** MOL-18
**Branches (to be created on approval):** `b-7.4.x-MOL-18-single-order-per-checkout-attempt` in
**payment-base** (server-side fix) and **mollie-payment** (controller hook, frontend guard, e2e, docs).
**Status:** DONE 2026-09-24 — see `../../20260924/done/MOL-18-single-order-per-checkout-attempt.md` and `../../20260924/reports/MOL-18-single-order-per-checkout-attempt.md` (deviations from this plan are listed there).
**Definition of Done (sprint-level):** clicking "Order now" any number of times in quick succession on the
standard order page creates exactly one contract, one order and one Mollie payment; every extra click is
answered by the same redirect to Mollie; no order row without articles is ever written again.

## Problem (ticket)

Final checkout step, terms accepted, Mollie selected. "Order now" clicked several times quickly. Multiple
submissions run and the backend shows several orders for one checkout attempt. Expected: one submission is
processed; further clicks while it is in flight do nothing; one order exists.

## What the investigation found (read before executing)

1. **The button has no guard.** Apex `page/checkout/order.html.twig` renders
   `<button type="button" onclick="document.getElementById('orderConfirmAgbBottom').submit();">` — every
   click fires a full POST to `cl=order&fnc=execute`. Mollie's `mollie-checkout` controller has a
   `disableContinueButton()` that looks for `button[onclick*="requestSubmit"]`; apex uses `.submit()`, so
   that selector never matches (dead code, and it targets the payment step anyway). The inline-card
   `mollie-components#placeOrder` submits on every click too. Only the OPC footer already ignores repeat
   clicks (`MollieCheckoutFooterSubmitState.inFlight()`).

2. **Requests run one after the other, not in parallel.** `session.save_handler = files`: PHP locks the
   session file, so click 2 starts only after click 1 has finished creating contract A, order S and the
   Mollie payment. The damage is therefore deterministic, not a race.

3. **Click 2 cancels the real order and writes a phantom.** In `MollieOrderController::execute()` a new
   contract B is created; payment-base's `EarlyOrderCreationHandler::retirePreviousAttempt()` finds A in the
   `OpenCheckoutAttemptRegistry` (session) and retires it: contract A cancelled, order S set to
   `CANCELLED`/`OXSTORNO=1` (the row stays, to keep the number sequence). Then `finalizeOrder()` runs with
   the **same `sess_challenge`** (core generates it once in `OrderController::render()` and deletes it only
   on the thank-you page): core's `checkOrderExist(S)` sees the row and returns `ORDER_STATE_ORDEREXISTS` —
   core's own "somebody clicked like mad" blocker. payment-base's `OxidShopOrderService::validateOrderState()`
   treats `ORDEREXISTS` as success and `setOrderFieldsAfterCreation()` then **saves the never-loaded
   `Order` object**: a brand-new row with a fresh id, no user, no articles, no payment type, total 0,
   `NOT_FINISHED`, and a consumed order number. Contract B is linked to that phantom; the shopper is
   redirected to Mollie for B and pays for an order that contains nothing.

4. **Local DB proof (no assumption):**

   | `OXPAYMENTTYPE = ''` orders | zero total | articles | first seen | last seen | linked contracts |
   |---|---|---|---|---|---|
   | 53 | 53 | 0 | 2026-07-29 | 2026-09-21 | cancelled 35, failed 6, expired 4, **committed 5, fulfilled 3** |

   Eight phantoms carry a committed/fulfilled contract: money was taken for an empty order. The same path
   fires on every legitimate **retry** (shopper comes back from Mollie unpaid and orders again) because
   `sess_challenge` is never rotated when payment-base retires an attempt — the retire feature (STRP-171)
   only ever "worked" through the phantom.

5. **Mollie owns the click; payment-base owns the guarantee.** The attempt id already exists: it is
   `sess_challenge` = the order id of the attempt. The rule to implement is: *an attempt whose order is still
   `NOT_FINISHED` and whose contract is open with a checkout URL is in flight; a second submission for the same
   attempt and the same basket replays that redirect.* payment-base decides, Mollie's controller asks.

## Approach

| Principle | Application |
|---|---|
| TDD-first | Story 1 lands a red e2e (double click → two orders) and a red payment-base integration test (second `createOrder()` with the same challenge writes a phantom) before any production edit |
| Fix causes, not symptoms | Three server-side causes (phantom save, missing in-flight detection, non-rotating challenge) fixed at their seams; the button guard is UX, not the safety net |
| SRP | `InFlightCheckoutAttemptResolver` answers one question; `OxidShopOrderService` stops inventing orders; the controller only redirects |
| DIP / ISP | Resolver depends on `ContractRepositoryInterface` + `SessionAdapterInterface` + one new `NotFinishedOrderRepositoryInterface` read (≤5 methods) — no new fat interface |
| DRY | Reuses `OpenCheckoutAttemptRegistry::SESSION_KEY`, `ContractRepositoryInterface::findById`, `PaymentContract::getRedirectUrl()/getBasketSnapshot()`, Mollie's `currentBasketSummaryHash()`; no second "previous attempt" concept |
| No overengineering | No DB lock table, no new idempotency keys, no config switch, no time window heuristics — the state machine already says what "in flight" means |
| DevOps-first | Both modules' `pre-commit-check.sh` green per story; payment-base `composer phpstan/phpmd/phpcs`, Unit + Integration; Mollie Unit + standalone stub suite; e2e `mollie-standard` project |

## Out of scope
- OPC (one-page checkout) server-side parity: its footer already blocks repeat clicks client-side, and its
  `MolliePaymentHandler` path reaches the same `EarlyOrderCreationHandler`; adding the resolver call there is
  a follow-up ticket once this sprint proves the seam.
- Cleaning up the 53 existing phantom rows (data fix, merchant decision).
- Stripe / PayPal controllers asking the resolver (they inherit Stories 2–3 for free; the replay redirect is
  per-provider glue).
- Making the theme's core button itself safe (theme repo).
- Mollie's dead `Adapter/OxidShopOrderService` copy (separate cleanup).

## Risks & unknowns
- **Legit retry must keep working** (STRP-171): shopper returns from Mollie unpaid → `checkoutReturn()`
  retires the attempt → next "Order now" must create a *new* order. Today that only works via the phantom.
  Story 3 rotates `sess_challenge` when an attempt is retired, and Story 6 runs the existing
  `PaypalPendingReturn` / `CheckoutPaysAndFinalizes` specs plus a new retry e2e to prove it.
- **Basket changed between clicks** (browser back from Mollie, add an item, order again): replaying the old
  redirect would charge the old amount. The resolver compares the contract's basket snapshot total with the
  live basket total and treats a mismatch as "not in flight" → existing retire-and-recreate path (plus the
  rotated challenge).
- **Expired Mollie checkout URL** on replay (shopper waits >15 min, comes back, clicks again): Mollie shows its
  own "expired" page; `checkoutReturn()` then retires the attempt as today. Accepted; noted in the report.
- **Unit-testing `OxidShopOrderService`** needs an `oxNew(Order)` seam that does not exist yet — Story 2 adds
  a protected `newOrder()` factory (same testable-subclass pattern as Mollie's `loadOrder()` seams).
- **e2e determinism of a "double click":** Playwright's `click({ clickCount: 2 })` is one dblclick event;
  the spec fires two `form.submit()`/button clicks without awaiting navigation in between, which reproduces
  the serialized double POST exactly as the browser does it.

---

## Story 1 — Prove it: red e2e double-click repro + red payment-base characterization test

**Repos:** mollie-payment (e2e), payment-base (integration) · **Estimate:** S

**Tests first (TDD):**
- `mollie-payment/tests/e2e/playwright/tests/MollieStandard/OrderNowDoubleClickCreatesOneOrder.spec.ts`
  - `two rapid "Order now" clicks create exactly one order` — login, basket, payment, order page; read the
    highest order number in admin *before* (or read `oxordernr` via the thank-you page afterwards); click
    the order button twice without awaiting navigation; complete the Mollie test payment; then in admin
    orders list assert: exactly one new order for the customer, `OXSTORNO=0`, non-empty payment type and
    articles (open it: Addresses tab shows a billing name; Main tab shows a total). RED today (two rows: one
    cancelled, one phantom, or similar).
  - Reuse `loginStorefront`, `addFirstFeaturedProductToBasket`, `goToCheckoutPayment`, `selectMolliePaymentMethod`,
    `continueToOrderReview`, `acceptTermsAndConditions`, `pickRedirectMollieMethod`, `completeMollieTestPayment`,
    `loginShopAdmin`, `AdminOrdersPage`. Env: OPC flag off for the run, restored after (Story 1 of sprint 01
    playbook).
- `payment-base/tests/Integration/Adapter/OxidShopOrderServiceSecondSubmissionTest.php` (`@group integration`)
  - `testCreateOrder_SecondCallWithSameSessionChallenge_DoesNotAddAnOrderRow` — fixture user/article/payment
    from `OxidShopOrderServiceShippingAddressTest` (extract the three fixture builders into a small
    `tests/Integration/Support/CheckoutFixture` trait — third use = extraction), call `createOrder()` twice
    with the same `sess_challenge`, count `oxorder` rows for the fixture user: expect 1. RED today (2, the
    second with `OXPAYMENTTYPE=''`).

**Implementation steps:**
1. Write both tests; run; record the red output in `reports/MOL-18-…md` (paste the second row).
2. No production code.

**DevOps gate:** e2e RED for the ticket's reason only; integration RED on the row count only.
**Definition of Done:** both tests exist and fail for exactly the reasons in *Findings 3*.

---

## Story 2 — payment-base: `ORDER_STATE_ORDEREXISTS` must not create a phantom order

**Repo:** payment-base · **Why:** Core's reload blocker returned a clear signal; saving a never-loaded
`Order` on that signal is the bug that writes empty, numbered, sometimes paid orders. · **Estimate:** S

**Tests first (TDD):**
- `tests/Unit/Adapter/OxidShopOrderServiceTest.php`
  - `testCreateOrder_WhenFinalizeReportsOrderExists_ThrowsOrderExistsAndSavesNothing` — testable subclass
    overrides new `protected function newOrder(): Order` to return an `Order` mock whose `finalizeOrder()`
    returns `Order::ORDER_STATE_ORDEREXISTS`; `save` expects `never()`; assert `ShopOrderException` with
    `errorCode === 'order_exists'` and the challenge id in `context`.
  - `testCreateOrder_WhenFinalizeReportsOk_SavesOnce` — same seam, `ORDER_STATE_OK`, `save` once (pins the
    happy path the seam now exposes; `validateBasketAndUser()` also needs a seam for the session basket —
    add `protected function sessionBasket(): ?Basket` alongside, or the test goes through the existing
    integration suite only — pick the smaller change once you see the class).
  - Unit stub: `tests/bootstrap-unit.php` `Order` gains `finalizeOrder()` + `ORDER_STATE_*` constants if
    missing.
- Story 1's integration test turns GREEN (second call throws, row count stays 1).

**Implementation steps:**
1. `finalizeAndValidateOrder()`: if `$orderState === Order::ORDER_STATE_ORDEREXISTS` → throw
   `ShopOrderException(message: 'An order for this checkout attempt already exists', errorCode: 'order_exists',
   context: ['order_id' => $challenge, 'session_id' => …])` **before** `validateOrderState()`.
2. `validateOrderState()`: drop `ORDER_STATE_ORDEREXISTS` from the accepted list; `mapOrderStateToStatus()`
   / `mapOrderStateToErrorCode()` map it to `'order_exists'`.
3. Extract `protected function newOrder(): Order { return oxNew(Order::class); }` (the only oxNew in the
   creation path) so the unit tests above can run without the shop.
4. `EarlyOrderCreationHandler` already rethrows `ShopOrderException` → contract creation fails → Mollie's
   `execute()` shows `MOLLIE_CHECKOUT_UNAVAILABLE` for now (Story 4 makes it a replay instead).

**SOLID/Clean check:** SRP unchanged (order glue); LSP: `ShopOrderServiceInterface::createOrder()` contract
already declares `ShopOrderException` on failure — a new error code, not a new exception type; no-else via
early throw; DRY: reuse `ShopOrderException` shape.
**DevOps gate:** `composer phpcs/phpstan/phpmd/test-unit` ✓; integration suite from the shop root ✓;
`./bin/pre-commit-check.sh --full` ✓ (+ manual `composer phpstan`/`phpmd` on new files — script caveat).
**Definition of Done:** a second `createOrder()` for the same challenge throws `order_exists` and writes no row.

---

## Story 3 — payment-base: know when an attempt is in flight, and rotate the challenge when it is retired

**Repo:** payment-base · **Why:** Without a positive "in flight" answer the controller cannot replay; without
a rotated challenge a legitimate retry hits Story 2's exception forever. · **Estimate:** M

**Tests first (TDD):**
- `tests/Unit/Checkout/InFlightCheckoutAttemptResolverTest.php`
  - `testResolve_WhenNoOpenAttemptInSession_ReturnsNull`
  - `testResolve_WhenContractMissing_ReturnsNull`
  - `testResolve_WhenContractIsTerminal_ReturnsNull` (cancelled / expired / failed)
  - `testResolve_WhenContractHasNoRedirectUrl_ReturnsNull` (payment not yet created)
  - `testResolve_WhenOrderIsNotNotFinished_ReturnsNull` (already OK/CANCELLED)
  - `testResolve_WhenBasketTotalDiffers_ReturnsNull`
  - `testResolve_WhenAttemptIsOpenAndBasketMatches_ReturnsRedirectUrl`
  - Fakes: in-memory `ContractRepositoryInterface` (exists in `tests/Unit/EventSystem/Handler/Support/`),
    array-backed `SessionAdapterInterface`, and a tiny `OrderTransStatusReaderInterface` fake.
- `tests/Unit/Checkout/PreviousCheckoutAttemptCleanerTest.php` (existing or new)
  - `testClean_WhenAttemptRetired_ForgetsTheSessionChallenge` — asserts `session->setVariable('sess_challenge', null)`
    (or `deleteVariable`) is called exactly when `clean()` returns true.
- Story 1 e2e still red (controller not wired yet).

**Implementation steps:**
1. `src/Checkout/InFlightCheckoutAttemptResolver.php` (+ `…Interface` with one method
   `resolve(float $liveBasketTotal): ?string` — one impl, but the Mollie controller in another module is the
   consumer, so the interface is the cross-module contract, same as `OpenCheckoutAttemptRegistryInterface`).
   Logic: read `OpenCheckoutAttemptRegistry::SESSION_KEY` **without consuming it** (add `peek(): ?string`
   to the registry — 3rd method, still ≤5) → `findById` → guards above → `getRedirectUrl()`.
2. `src/Repository/OrderTransStatusReaderInterface.php` + `OxidOrderTransStatusReader` (one method
   `transStatusOf(string $orderId): ?string`; SQL `SELECT OXTRANSSTATUS FROM oxorder WHERE OXID = ?`) —
   check first whether `NotFinishedOrderRepositoryInterface` can grow this read instead (ISP: it has 3
   methods; if it fits semantically, add it there and skip the new interface).
3. Basket match: `PaymentContract::getBasketSnapshot()->getTotalGross()` vs `$liveBasketTotal`, compared
   with `abs(diff) < 0.005`.
4. `PreviousCheckoutAttemptCleaner::clean()` — after a successful retire, `session->setVariable('sess_challenge', null)`
   via the injected `SessionAdapterInterface` (constructor gains it; `services.yaml` explicit argument like
   the others). `OrderController::render()` then issues a fresh challenge on the next order page.
5. `services.yaml`: declare resolver + reader, public where an OXID controller must fetch them
   (`public: true`, same reasoning as the thank-you notice service comment).

**SOLID/Clean check:** SRP one question per class; DIP interfaces only; ISP registry 3 methods, reader 1;
LSP n/a; DRY reuses registry key + contract API; no-else; ≤25-line methods.
**DevOps gate:** payment-base full gates ✓ (+ Integration: extend Story 1's second-submission test with a
retry scenario: retire via `PreviousCheckoutAttemptCleaner`, assert challenge cleared).
**Definition of Done:** resolver answers `null`/URL per the seven cases; a retired attempt leaves no stale
challenge in the session.

---

## Story 4 — Mollie: `execute()` replays the in-flight attempt instead of starting a new one

**Repo:** mollie-payment · **Why:** The controller owns the click; with Stories 2–3 in place the second click
must become "redirect to the same Mollie checkout". · **Estimate:** S

**Tests first (TDD):**
- `tests/Unit/Controller/MollieOrderControllerTest.php` (via `TestableMollieOrderController`)
  - `testExecute_WhenAttemptInFlight_RedirectsToExistingCheckoutUrlWithoutDispatching`
  - `testExecute_WhenNoAttemptInFlight_DispatchesCheckoutSessionEvent` (existing happy path keeps passing)
  - `testExecute_WhenResolverUnavailable_ProceedsAsBefore` (container returns null → no behaviour change)
  - `testExecute_WhenOrderExistsErrorSurfaces_ShowsCheckoutUnavailable` (defensive: Story 2's exception
    reaches the controller only if the resolver missed — still a clean error page, no phantom)
- Standalone CI stub: `tests/bootstrap-unit.php` `MollieOrderController_parent` unchanged unless a new core
  method is used (2026-09-17 note).

**Implementation steps:**
1. After the AGB/basket-hash guards and before `resolveDispatcher()`: `$replay = $this->inFlightCheckoutUrl();`
   → if string, `$this->redirect($replay); return null;`.
2. `protected function inFlightCheckoutUrl(): ?string` — `resolveService(InFlightCheckoutAttemptResolverInterface::class)
   ?->resolve($this->liveBasketTotal())`; `protected function liveBasketTotal(): float` reads
   `Registry::getSession()->getBasket()->getPrice()->getBruttoPrice()`.
3. Log one `info` line with the contract id when replaying (ops visibility).

**SOLID/Clean check:** controller stays orchestration-only; DIP on the payment-base interface; no-else;
`execute()` stays ≤25 lines (extract `guardsPass()` if it grows).
**DevOps gate:** Mollie `./bin/pre-commit-check.sh` ✓ (phpcs, phpstan max, phpmd, Unit), standalone
`tests/phpunit-unit.xml` suite ✓ (CI's isolated job).
**Definition of Done:** a second POST with an open attempt is answered with the first attempt's Mollie URL and
dispatches nothing; Story 1's e2e turns GREEN on the order count.

---

## Story 5 — Mollie: the order button submits once (classic and inline card flow)

**Repo:** mollie-payment · **Why:** Ticket's second expectation ("additional clicks should not trigger further
submissions") is UX; the server is now safe regardless, so this story is small and honest. · **Estimate:** S

**Tests first (TDD):**
- e2e (same spec file as Story 1, second test): `the order button is disabled after the first click` —
  click once, assert the button is `disabled` / has `is-loading` before navigation completes.
- No JS unit runner exists in the module (`package.json` has only esbuild scripts) — do not add one for this.

**Implementation steps:**
1. `resources/js/controllers/mollie_components_controller.js#placeOrder`: an `_inFlight` flag — second call
   returns early; button `disabled` + `is-loading` set before `_submit()`; released only on tokenisation error.
2. Classic redirect flow: Mollie already overrides `checkout_order_next_step_side`; when the payment is
   Mollie and inline is off, render the same button markup as apex but with
   `data-controller="mollie-place-order" data-action="click->mollie-place-order#submit"` and a new
   ~20-line `mollie_place_order_controller.js` that disables itself and calls `form.requestSubmit()` once.
   Keep `{{ parent() }}` for non-Mollie payments.
3. Delete the dead `disableContinueButton()` selector in `mollie_checkout_controller.js` or fix it to the apex
   markup — pick fix, since the payment-step continue button has the same double-submit exposure.
4. `npm run build` → commit `assets/js/mollie-frontend.js` + `.min.js`; bump `getMollieModuleVersion()` cache
   buster if it is not derived from `metadata.php`.

**SOLID/Clean check:** one Stimulus controller per concern; no framework additions; DRY: the two buttons share
the "disable + loading" helper if a third appears (OPC footer already has its own — leave it).
**DevOps gate:** Mollie pre-commit ✓; e2e `mollie-standard` project ✓.
**Definition of Done:** second click on the order button is a no-op in the browser; markup for non-Mollie
payments unchanged.

---

## Story 6 — Prove, log, changelog

**Repos:** both · **Estimate:** S

**Tests first (TDD):**
- Story 1 e2e GREEN (one order, not storno'd, has articles); new e2e
  `OrderRetryAfterUnpaidReturnCreatesNewOrder.spec.ts` GREEN: fail the Mollie test payment, return, order
  again → a second, new order exists and none is a phantom (proves Story 3's challenge rotation).
- Full `--project=mollie-standard` regression: previous 13 specs still green (11 pass + 2 Klarna skips).
- payment-base `./bin/pre-commit-check.sh --full` ✓; Mollie `./bin/pre-commit-check.sh` ✓.

**Implementation steps:**
1. DB sanity after the runs: `SELECT COUNT(*) FROM oxorder WHERE OXPAYMENTTYPE='' AND OXORDERDATE > <sprint start>`
   must be 0; paste into the report.
2. payment-base `CHANGELOG.md` `[Unreleased] / Fixed`: (a) no phantom order on `ORDEREXISTS`, (b) in-flight
   attempt resolver + challenge rotation on retire. Mollie `CHANGELOG.md` `[Unreleased] / Fixed`: replay on
   repeated "Order now", single-submit button.
3. Mollie `reports/MOL-18-…md` (root cause chain, DB evidence, before/after), `done/MOL-18-…md`, `status.md`;
   payment-base `docs/dev_log/20260923/sprints/sprint-11-MOL-18-….md` DONE.
4. Push both branches; CI green; **do not merge**; notify.

**Definition of Done:** ticket steps reproduced by machine and green; retry path proven; no new phantom rows;
CI green on both feature branches; nothing merged.

---

## Suggested order
1. Story 1 — red proofs at both levels; also confirms the exact shape of today's duplicate (cancelled + phantom).
2. Story 2 — smallest, highest-value: stops paid phantoms immediately, even before the replay exists.
3. Story 3 — the positive "in flight" answer + retry safety (largest, payment-base only).
4. Story 4 — Mollie glue; e2e order-count assertion goes green here.
5. Story 5 — UX guard; e2e button assertion.
6. Story 6 — retry e2e, regression, changelogs, docs, push, CI, notify.

## Open questions for the approver (answer inline; defaults in bold)
- Replay window: **no time limit** (state machine decides) vs. a minutes-based cutoff.
- Basket mismatch behaviour: **retire old attempt and create a new one** (today's semantics) vs. block with
  `BASKET_ITEMS_CHANGED_ERROR`.
- OPC server-side parity: **follow-up ticket** vs. include as Story 7 (would exceed the 6-story cap).
