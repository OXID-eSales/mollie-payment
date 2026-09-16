# Sprint plan: Mollie checkout must reject "Order now" when Terms and Conditions (AGB) are not accepted

**Definition of Done (sprint-level):** With `blConfirmAGB` active and the AGB checkbox unchecked, selecting Mollie and clicking "Order now" re-renders the order step with the AGB error — no Mollie checkout session is created, no redirect happens. ✅ DONE

## Root cause

`MollieOrderController::execute()` (`src/Mollie/Controller/MollieOrderController.php`) intercepts the request whenever Mollie is the selected payment and dispatches `MollieCheckoutSessionRequestEvent` + redirects to Mollie **without ever reaching the core guards** that `OrderController::execute()` runs: `validateTermsAndConditions()` (which covers `blConfirmAGB`/`ord_agb` **and** the downloadable/service-product agreements) plus `checkSessionChallenge()`. Non-Mollie payments delegate to the parent and are unaffected.

Stripe already solved the identical problem — `StripeOrderController::ensureAgbAccepted()` guards before any side effects. Mollie differs in one way: it's a classic form POST, not an AJAX/JSON flow, so the correct rejection is core's own behavior (set `_blConfirmAGBError`, return `null` to re-render the order step) rather than an HTTP 400 JSON body.

OPC (one-page checkout) is unaffected: it POSTs to `cl=OeCheckoutApi&fnc=processCheckout`, whose `CheckoutService` already rejects unless `confirmTermsAndConditions` is true.

## Out of scope (file as separate tickets)
- `checkSessionChallenge()` (CSRF token) is **also** skipped by the Mollie execute() path — a separate security finding.
- `basketSummaryHash` validation, likewise skipped.
- Two pre-existing e2e failures on the inline-components-enabled shop, verified identical on clean main: `CheckoutPaysAndFinalizes` (Components JS intercepts submit when no `mollieMethod` is selected) and `FrontendLoggingGated` (checkout-walk timeout).

---

## Story 1 — Guard `MollieOrderController::execute()` with core terms-and-conditions validation ✅

**Tests first (TDD)** — `tests/Unit/Controller/MollieOrderControllerTest.php` (red before fix, green after):
- `testExecuteWhenAgbNotAcceptedDoesNotDispatchCheckoutSessionEvent`
- `testExecuteWhenAgbNotAcceptedReturnsNullAndFlagsConfirmAgbError` (dispatcher deliberately unavailable — proves the guard fires before service resolution, zero side effects)
- `testExecuteWhenAgbAcceptedProceedsToCheckoutRedirect`
- `testExecuteWhenNonMollieMethodSkipsMollieAgbGuardAndDelegatesToParent`

**Implementation:**
1. New protected seam `confirmsTermsAndConditions(): bool` delegating to core `validateTermsAndConditions()` (covers AGB + intangible-product agreements; LSP note in PHPDoc).
2. Guard in `execute()` immediately after the payment-id check, **before** dispatcher resolution: on rejection set `_blConfirmAGBError = true`, return `null` (core re-renders the order step with `READ_AND_CONFIRM_TERMS`).
3. `TestableMollieOrderController` gains `bool $termsAccepted = true` + seam override.

**Gate fallout fixed en route (all inside the touched file):**
- PHPStan: `_blConfirmAGBError` is typed `bool` → assign `true`, not core's `1` (Apex template checks `== 1` loosely; unit test asserts `== 1`).
- PHPMD `ExcessiveClassComplexity` (class was at the 50 threshold): reclaimed 2 points in `buildCheckoutContext()` by dropping the impossible-input `method_exists` check (`Base::getUser()` returns `User|false`) — elvis + nullsafe instead of three ternaries.
- PHPStan level max (pre-commit runs `--level=max`; error pre-existed on main): `resolveService()` got `@template T of object` generics, fixing `object::retire()` at the `AbandonedAttemptCleanup` call site with zero added complexity.

## Story 2 — E2E regression: unchecked AGB blocks the Mollie redirect ✅

`tests/e2e/playwright/tests/MollieStandard/AgbRequiredBlocksCheckout.spec.ts` (repo convention `MollieStandard/`, not the originally planned `checkout/` path):
- Written FIRST as the bug reproduction — failed on unfixed code with the browser landing on `https://www.mollie.com/checkout/test-mode?method=paypal&…` despite unchecked AGB.
- Handles the inline-components shop: picks a redirect method (PayPal) so the form POSTs natively to `cl=order&fnc=execute`; skips loudly when `blConfirmAGB` is off or only card is offered.
- Green after the fix: stays on `cl=order`, shows "Bitte bestätigen Sie unsere Allg. Geschäftsbedingungen!", Order-now button still usable.

**Suite adaptation (specs relied on the bug):** new `acceptTermsAndConditions(page)` helper in `fixtures/shop-helpers.ts`, called before every Order-now click in `CheckoutPaysAndFinalizes`, `InlineMethodRedirect`, `KlarnaEndToEnd`, `KlarnaOrderData`, `InlineCardComponents`, `PaypalPendingReturn`. (OPC specs already tick `#confirmTermsCheckout`.)

---

## DevOps gate — final state
- `phpcs` ✓ · `phpstan` (level 6 **and** level max) ✓ · `phpmd` (no baseline additions) ✓
- PHPUnit Unit suite: **OK (599 tests, 1531 assertions)** ✓
- `./bin/pre-commit-check.sh` → **ALL CHECKS PASSED / COMMITABLE** ✓
- e2e `mollie-standard` project: 6 passed incl. all order-placing specs; 2 failures verified pre-existing on clean main (see out of scope).
