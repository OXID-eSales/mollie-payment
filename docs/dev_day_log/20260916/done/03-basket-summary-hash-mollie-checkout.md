# Sprint plan: Mollie checkout must reject "Order now" when the basket changed since the order step was rendered

**Definition of Done (sprint-level):** With Mollie selected, a `basketSummaryHash` request value that no longer matches the live basket re-renders with `BASKET_ITEMS_CHANGED_ERROR` instead of creating a Mollie checkout session; a missing hash logs core's warning and proceeds (core parity).

## Background

Follow-up 2 from `done/01-agb-validation-mollie-checkout.md`. Core `OrderController::execute()` compares the posted `basketSummaryHash` against `md5(json_encode($basket->getBasketSummary()))`; on mismatch it shows `BASKET_ITEMS_CHANGED_ERROR` and returns `'basket'` (empty basket) or `'order'`. The Mollie interception skips this, so a customer can alter the basket in a second tab and still send the stale order to Mollie — pricing on the Mollie payment then diverges from the basket that gets finalized.

**Constraint that shapes the design:** core's three helpers (`getBasketSummaryHash()`, `notifyIfBasketSummaryValidationIsNotPossible()`, `addBasketSummaryValidationError()`) are **`private`** (`source/Application/Controller/OrderController.php:602-626`) — a class-chain child cannot call them. The comparison logic must be mirrored in the module.

## Hard constraint
**Module-only change set.** Every file touched lives under `source/extensions/mollie-payment/`. No OXID core, vendor, payment-base, or Stripe files are modified — core's private helpers are *mirrored* in the module precisely because changing core is not an option here; the visibility change is an upstream ticket only.

## Depends on
- Sprint 02 Story 1 (pending-return probe extraction) — the controller has no WMC headroom without it. Do not start this sprint before that story is merged.

## Out of scope
- Making core's helpers `protected` so modules can reuse them — right fix long-term, but an oxideshop-ce contribution with its own branch/release cycle. Record as an upstream ticket; the module-side mirror carries a `// core OrderController keeps these private — mirror, do not fork behavior` comment referencing it.
- Stripe module — it skips this validation too (no `basketSummaryHash` handling anywhere in `extensions/stripe/src/`). Same class of bug; file its own ticket, don't fix it here.
- Any behavior beyond core parity (e.g. rejecting when the hash is *missing* — core only warns; tightening that is a product decision).

## Risks & unknowns
- `getBasketSummary()` may be locale/float-representation sensitive; the mirror must hash **exactly** like core (`md5(json_encode(...))` on the same basket object) or every legitimate order gets rejected. The happy-path e2e specs are the tripwire — run the full `mollie-standard` project before calling it done.
- WMC: validation adds ~5 points to the controller. Post-Sprint-02 budget (~45) absorbs it; if PHPMD still fires, the fallback is a pure `BasketSummaryValidator` value-in/value-out class in `src/Mollie/Service/` — do NOT baseline.

---

## Story 1 — Basket-summary-hash guard in `MollieOrderController::execute()`

**Why:** The posted order must be the order the customer saw; core enforces it for every other payment method.
**Estimate:** M

**Tests first (TDD):**
- `tests/Unit/Controller/MollieOrderControllerTest.php`
  - `testExecuteWhenBasketHashMismatchesDoesNotDispatchCheckoutSessionEvent`
  - `testExecuteWhenBasketHashMismatchesShowsBasketChangedErrorAndReturnsOrder` — non-empty basket → `'order'`
  - `testExecuteWhenBasketHashMismatchesOnEmptyBasketReturnsBasket` — products count 0 → `'basket'`
  - `testExecuteWhenBasketHashMissingLogsWarningAndProceedsToRedirect` — core parity: warn, don't block
  - `testExecuteWhenBasketHashMatchesProceedsToRedirect`
  - `testExecuteGuardOrderIsChallengeThenTermsThenBasketHash` — hash guard must not fire when terms already rejected (mirrors core's sequence)
- e2e `tests/e2e/playwright/tests/MollieStandard/BasketHashBlocksCheckout.spec.ts`
  - walk to order review (reuse `shop-helpers.ts` incl. `acceptTermsAndConditions`, pick redirect `mollieMethod` — same inline-components workaround as `AgbRequiredBlocksCheckout.spec.ts`), tamper the hidden `input[name="basketSummaryHash"]` via `page.evaluate`, click Order now → assert URL never matches `mollie.com` and `BASKET_ITEMS_CHANGED_ERROR`'s translated text is shown. Written FIRST: must fail on unfixed code by landing on Mollie.

**Implementation steps:**
1. Seam `protected function currentBasketSummaryHash(): string` → `md5(json_encode($basket->getBasketSummary()))` mirroring core byte-for-byte (comment pointing at `OrderController::getBasketSummaryHash()` and the upstream-visibility ticket).
2. Private `validateBasketSummaryHash(Basket $basket): ?string` — returns `null` to proceed, or the redirect controller (`'basket'`/`'order'`); missing param → warning via `Registry::getLogger()` (core wording) and `null`; mismatch → `Registry::getUtilsView()->addErrorToDisplay('BASKET_ITEMS_CHANGED_ERROR', false, true, '', $redirect)` and the redirect.
3. Guard in `execute()` after the terms guard (core's ordering): `$redirect = $this->validateBasketSummaryHash($basket); if ($redirect !== null) { return $redirect; }` — before dispatcher resolution, zero side effects on rejection.
4. `TestableMollieOrderController`: override `currentBasketSummaryHash()` (fixed string) + route the error display through a recordable seam, mirroring the existing `unavailableErrorShown` pattern; `requestParams['basketSummaryHash']` drives the three cases through the already-overridden `readRequestParameter()`.

**SOLID/Clean check:**
- SRP: one private method = one validation; `execute()` stays a guard chain + dispatch.
- DRY: **deliberate duplication** of ~4 lines of core logic, forced by core's `private` visibility — documented at the call site with the upstream ticket; do not "abstract" around it.
- LSP: override restores the parent's "no finalize/redirect on a stale basket" contract — note on the seam.
- No-else: `elseif` from core becomes early returns.

**DevOps gate:** `composer phpcs` ✓ · `composer phpstan` ✓ (level 6 + `--level=max` via pre-commit) · `composer phpmd` ✓ (no baseline additions — extract the pure validator instead if WMC fires) · Unit suite ✓ · new e2e spec red-then-green · full `mollie-standard` e2e project (happy paths must still reach Mollie — the hash-mirror tripwire) · `./bin/pre-commit-check.sh` ✓.

**Definition of Done:** A tampered hash never reaches the event dispatcher (unit) and never leaves the shop, showing `BASKET_ITEMS_CHANGED_ERROR` (e2e); an untampered checkout still redirects to Mollie (existing specs green); a missing hash only warns.

---

## Suggested order
1. The failing e2e spec first (bug reproduction), then unit red/green, then the full `mollie-standard` run.
2. File the two spin-off tickets before closing: (a) upstream oxideshop-ce — make the basket-hash helpers `protected`; (b) Stripe module — same missing validation.
