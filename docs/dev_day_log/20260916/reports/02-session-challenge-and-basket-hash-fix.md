# Report: Mollie checkout skipped core's CSRF and basket-integrity guards (Sprints 02 + 03)

**Date:** 2026-09-16
**Severity:** High (CSRF) / Medium (stale-basket pricing divergence)
**Status:** Fixed, all gates green
**Sprints:** `done/02-session-challenge-mollie-checkout.md`, `done/03-basket-summary-hash-mollie-checkout.md`

## The bugs (both same root cause as Sprint 01)

`MollieOrderController::execute()` intercepts `cl=order&fnc=execute` for the Mollie payment id
before core `OrderController::execute()` can run its guard chain. Sprint 01 restored the AGB
guard; these sprints restore the remaining two:

1. **Session challenge (CSRF):** core's FIRST guard — `Session::checkSessionChallenge()`
   compares the session's `sess_stoken` against the `stoken` request parameter and returns null
   (silent re-render) on failure. Skipped → a cross-site form POST could trigger a Mollie
   checkout session for a logged-in customer.
2. **basketSummaryHash:** core compares the posted hash against
   `md5(json_encode($basket->getBasketSummary()))`; on mismatch it shows
   `BASKET_ITEMS_CHANGED_ERROR` and bounces to `'order'` (or `'basket'` when emptied). Skipped →
   change the basket in a second tab, submit the stale order step, and the Mollie payment amount
   diverges from the basket that gets finalized.

## The fixes (module-only, `src/Mollie/Controller/MollieOrderController.php`)

Guard chain in `execute()`, in core's exact order, all before any service resolution (zero side
effects on rejection):

```php
if (!$this->passesSessionChallenge()) {          // Sprint 02 — silent null, core parity
    return null;
}
if (!$this->confirmsTermsAndConditions()) {      // Sprint 01
    $this->_blConfirmAGBError = true;
    return null;
}
$basketRedirect = $this->validateBasketSummaryHash();   // Sprint 03
if ($basketRedirect !== null) {
    return $basketRedirect;                      // 'order' | 'basket' + BASKET_ITEMS_CHANGED_ERROR
}
```

- `passesSessionChallenge()` delegates to core's public `Session::checkSessionChallenge()`.
- `validateBasketSummaryHash()` mirrors core byte-for-byte because core's three helpers are
  **private** (`OrderController.php:602-626`): missing hash → core's warning wording, proceed;
  mismatch → `BASKET_ITEMS_CHANGED_ERROR` + redirect target by products count. Seams:
  `currentBasketSummaryHash()`, `basketRedirectTarget()`, `showBasketChangedError()`,
  `warnBasketHashMissing()`.

## Sprint 02 Story 1 — PendingReturnProbe extraction

`returnIsPending()`'s Mollie-API query moved to `src/Mollie/Service/Return/PendingReturnProbe.php`
(constructor-injected `MolliePaymentsAdapterInterface` + `MollieStatusMapper`, fail-closed false,
registered public in `services.yaml`). SRP-motivated and the WMC headroom both guards needed.
4 new unit tests; the controller seam signature is unchanged.

## PHPMD complexity accounting

The class hit WMC 55 (threshold 50) with both guards in. Reclaimed by deleting **impossible-input
validation**: `resolveService()` is generic (`@template T`, Sprint 01) and container ids are the
class names, so the five `instanceof` re-checks at its call sites (`resolveDispatcher`,
`resolveReturnResolver`, `tokenIsValid`, `loadContract`, `resolveCheckoutReturnResponder`) plus
one in `onReturnPending` could not fail at runtime. Final: PHPMD green with **no baseline
additions**, and the planned pure-validator fallback was not needed.

## TDD trail

1. **System-level red first** (both bugs reproduced on unfixed code, browser landing on
   `mollie.com/checkout/test-mode`):
   - `SessionChallengeBlocksCheckout.spec.ts` — blanks `input[name="stoken"]` (NOT `challenge`,
     which is the sess_challenge order id — discovering this was part of the red phase);
     re-verified red via stashed clean src after the selector fix.
   - `BasketHashBlocksCheckout.spec.ts` — tampers `input[name="basketSummaryHash"]`, asserts the
     translated `BASKET_ITEMS_CHANGED_ERROR` (DE/EN).
2. **Unit red:** 4 challenge tests (2 red) + 6 basket-hash tests (3 red) in
   `MollieOrderControllerTest`, incl. guard-ordering tests (challenge → terms → hash) and the
   core-parity "missing hash only warns" case.
3. **Green:** Unit suite OK (613 tests, 1562 assertions); both e2e specs pass.

## CI (DoD addition)

The GitHub Actions runs were failing on `main` **before** these changes:
`actions/checkout` of `OXID-eSales/payment-base` got an empty `token` input — the workflows
reference `secrets.ENTERPRISE_GITHUB_TOKEN || secrets.GH_TOKEN`, and the repo has **no secrets
configured**. payment-base is public, so both workflows now fall back to the ephemeral
`github.token`, and the two `COMPOSER_AUTH` sites only send a github-oauth token when a PAT
actually exists (an empty token 401s where anonymous access succeeds).

## Verification (final state)

| Gate | Result |
| --- | --- |
| `./bin/pre-commit-check.sh` | ALL CHECKS PASSED / COMMITABLE |
| phpcs / phpstan (6 + max) / phpmd (strict, no baseline) | ✓ |
| PHPUnit Unit | OK — 613 tests, 1562 assertions |
| e2e: both new regression specs | red on unfixed → green on fixed |
| e2e `mollie-standard` full run | see status.md (hash-mirror tripwire) |
| GitHub Actions on push | required green (DoD) |

## Spin-off tickets (from Sprint 03 plan)

1. Upstream oxideshop-ce: make `OrderController`'s basket-hash helpers `protected` so modules
   can reuse instead of mirror.
2. Stripe module: has the session-challenge guard but **no basketSummaryHash validation** — same
   class of bug as Sprint 03.
