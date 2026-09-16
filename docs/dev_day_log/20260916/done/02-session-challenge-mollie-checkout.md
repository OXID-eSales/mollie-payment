# Sprint plan: Mollie checkout must reject "Order now" when the session challenge (CSRF token) is invalid

**Definition of Done (sprint-level):** A `cl=order&fnc=execute` request with a missing or wrong `challenge` value and Mollie selected creates no checkout session and no redirect — it re-renders silently, exactly like core does for every other payment method.

## Background

Follow-up 1 from `done/01-agb-validation-mollie-checkout.md`. Core `OrderController::execute()` runs `$session->checkSessionChallenge()` as its **first** guard and returns `null` on failure (silent re-render, no error message). `MollieOrderController::execute()` intercepts before ever reaching it, so a cross-site form POST can trigger a Mollie checkout session for a logged-in customer. Stripe already guards this via `ControllerRequestHelper::isSessionValid()` (`extensions/stripe/src/Stripe/Controller/ControllerRequestHelper.php:224`).

## Hard constraint
**Module-only change set.** Every file touched lives under `source/extensions/mollie-payment/`. No OXID core, vendor, payment-base, or Stripe files are modified — core APIs are only *called* (`Session::checkSessionChallenge()` is public).

## Out of scope
- `basketSummaryHash` validation — Sprint 03 (depends on this sprint's Story 1 for complexity headroom).
- Core's `!$user → return 'user'` guard — the contract-creation handler already fails without a user; no observed defect.
- Stripe module — its guard exists and passes.

## Risks & unknowns
- **PHPMD `ExcessiveClassComplexity`:** the class sits at the 50-point threshold after Sprint 01. Any new guard (+2 WMC) fires the gate. Story 1 creates the headroom *first* by extracting the pending-return probe — do not reorder.
- Rejection is deliberately **silent** (core parity). Confirm in review that silence is acceptable UX for a CSRF rejection; if PO wants a message, that is a core-behavior change, not this sprint.

---

## Story 1 — Extract the pending-return probe from the controller into a service

**Why:** SRP — "query Mollie once to tell a pending payment from a failed one" is an API concern living inside the controller (`returnIsPending()`, ~6 WMC points). Present requirement forcing it now: the PHPMD complexity gate blocks Story 2 and Sprint 03 without this headroom.
**Estimate:** S

**Tests first (TDD):**
- `tests/Unit/Service/Return/PendingReturnProbeTest.php`
  - `testIsPendingWhenContractHasNoProviderOrderIdReturnsFalse`
  - `testIsPendingWhenMollieReportsPendingStatusReturnsTrue`
  - `testIsPendingWhenMollieReportsPaidStatusReturnsFalse`
  - `testIsPendingWhenAdapterThrowsFailsClosedToFalse`
- `tests/Unit/Controller/MollieOrderControllerTest.php`
  - existing `testCheckoutReturnWhenPaymentIsPendingLandsOnThankYouNotError` stays green (seam unchanged from the test's perspective)

**Implementation steps:**
1. New `src/Mollie/Service/Return/PendingReturnProbe.php`: `isPending(PaymentContractInterface $contract): bool`, constructor-injected `MolliePaymentsAdapterInterface` + `MollieStatusMapper` (proper DI instead of the controller's `resolveService()` reach-ins).
2. Move the body of `MollieOrderController::returnIsPending()` into the probe verbatim (fail-closed `false` on throw stays).
3. Controller `returnIsPending()` shrinks to: resolve probe via `resolveService()`, return `false` when unavailable, else delegate — the seam signature is unchanged, so `TestableMollieOrderController` needs no edit.
4. Register the probe in `services.yaml`.

**SOLID/Clean check:**
- SRP: probe = "classify a returned contract's live Mollie status as pending or not", one sentence, one public method.
- DIP: depends on `MolliePaymentsAdapterInterface` (segregated adapter, per module rule) — not the SDK.
- DRY: pure move, no duplication; controller seam kept so the testable subclass is untouched.
- No overengineering: no interface for the probe — one implementation, one consumer (module rule: one impl before an interface).

**DevOps gate:** `composer phpcs` ✓ · `composer phpstan` ✓ · `composer phpmd` ✓ (WMC must now be ≤ 45 — verify, this is the point of the story) · Unit suite ✓ · `./bin/pre-commit-check.sh` ✓ (runs PHPStan at level max).

**Definition of Done:** Controller no longer imports `MolliePaymentsAdapterInterface`/`MollieStatusMapper`/`MollieOutcome`; probe unit-tested in isolation; all existing tests green.

---

## Story 2 — Session-challenge guard in `MollieOrderController::execute()`

**Why:** Restores core's first-line CSRF protection the class-chain interception bypassed.
**Estimate:** S

**Tests first (TDD):**
- `tests/Unit/Controller/MollieOrderControllerTest.php`
  - `testExecuteWhenSessionChallengeInvalidDoesNotDispatchCheckoutSessionEvent`
  - `testExecuteWhenSessionChallengeInvalidReturnsNullSilentlyWithoutSideEffects` — result `null`, no redirect, no AGB flag, no "unavailable" error (dispatcher deliberately unavailable to prove guard order)
  - `testExecuteWhenSessionChallengeValidProceedsToAgbValidation` — challenge ok + terms rejected still flags the AGB error (proves guard ordering matches core: challenge → terms)
  - `testExecuteWhenNonMollieMethodDelegatesToParentWhichRunsItsOwnChallengeCheck`
- e2e `tests/e2e/playwright/tests/MollieStandard/SessionChallengeBlocksCheckout.spec.ts`
  - walk to order review (reuse `shop-helpers.ts` incl. `acceptTermsAndConditions`), pick a redirect `mollieMethod` (PayPal — same inline-components workaround as `AgbRequiredBlocksCheckout.spec.ts`), then blank the hidden `input[name="challenge"]` via `page.evaluate`, click Order now → assert URL never matches `mollie.com` and the order step re-renders. Written FIRST: must fail on unfixed code by landing on Mollie.

**Implementation steps:**
1. Seam `protected function passesSessionChallenge(): bool` → `return Registry::getSession()->checkSessionChallenge();` with an LSP PHPDoc note (parent contract: no finalize/redirect without a valid challenge).
2. Guard in `execute()` directly after the payment-id early return, **before** `confirmsTermsAndConditions()` (core's ordering): `if (!$this->passesSessionChallenge()) { return null; }`.
3. `TestableMollieOrderController`: constructor param `bool $challengeValid = true` + seam override (mirrors the Sprint-01 `termsAccepted` pattern; existing tests untouched by the default).

**SOLID/Clean check:**
- SRP: controller routes; validation itself is core `Session::checkSessionChallenge()` — no new validation logic.
- LSP: override *restores* the parent's guard contract, documented on the seam.
- DRY: reuses core's public session API; no Mollie-side token handling. Stripe's helper-object variant is not extracted into a shared class — second call site with a different controller shape; revisit at a third PSP.
- No-else / early return: guard is a 3-line early return.

**DevOps gate:** `composer phpcs` ✓ · `composer phpstan` ✓ · `composer phpmd` ✓ (headroom from Story 1) · Unit suite ✓ · e2e spec red-then-green · `./bin/pre-commit-check.sh` ✓.

**Definition of Done:** A tampered/absent challenge never reaches the event dispatcher (unit) and never leaves the shop (e2e); a valid challenge still redirects to Mollie (existing happy-path specs stay green).

---

## Suggested order
1. Story 2's failing e2e spec first (bug reproduction), then Story 1 (headroom — blocks everything), then Story 2 red/green, re-run the spec.
2. Run the full `mollie-standard` e2e project at the end — the same suite-wide sanity Sprint 01 used.
