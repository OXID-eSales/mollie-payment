# Sprint 01 — DONE: Mollie dashboard deep-link no longer carries a `test-mode` segment

**Plan:** [`../sprints/01-dashboard-link-drops-test-mode-segment.md`](../sprints/01-dashboard-link-drops-test-mode-segment.md)
**Date:** 2026-09-17

**Definition of Done:** an order paid in test mode shows
`https://my.mollie.com/dashboard/payments/tr_…` (no `test-mode`) as the dashboard link on the admin
*Payment* tab. ✅

## What shipped

| File | Change |
|---|---|
| `src/Mollie/Service/MollieUrlBuilder.php` | One `DASHBOARD_PAYMENTS` constant; constructor and `ModuleConfigurationServiceInterface` dependency removed; PHPDoc explains why the link is mode-agnostic |
| `tests/Unit/Service/MollieUrlBuilderTest.php` | Config mock gone; tests pin the dashboard path, assert `test-mode` is absent, keep the `rawurlencode` case |
| `tests/Unit/Admin/MolliePanelViewDataBuilderTest.php` | Constructs `new MollieUrlBuilder()`; unused import dropped |
| `CHANGELOG.md` | `Unreleased → Fixed` |

No template, `services.yaml` or view-data key changed — DI is autowired via the `Service/` resource
sweep, so the constructor removal needed no wiring edit.

## TDD trail

1. **RED** — rewrote `testPaymentUrl_InTestMode_UsesTestModeDashboard` into
   `…_OmitsTheTestModeSegment`; it failed on `b-7.4.x` with exactly the reported string
   (`…/dashboard/test-mode/payments/tr_abc`).
2. **GREEN** — collapsed the two base URLs into one.
3. **REFACTOR** — the `isTestMode()` read had no remaining caller inside the class, so the
   dependency went too (YAGNI); both construction sites updated.

## Gates

- `./bin/pre-commit-check.sh`: PHPCS ✓, PHPStan ✓, PHPMD ✓, Unit 613/613 ✓ (was 613 before — one
  test rewritten, none added or lost).
- Live container check after `oe:cache:clear`: `MollieUrlBuilder` resolves from the compiled
  container and returns `https://my.mollie.com/dashboard/payments/tr_dWevmaHPVC5gyooRwEuWJ` for the
  ticket's example id. (`MolliePanelViewDataBuilder` is an inlined private service and cannot be
  fetched directly from the container — identical on the unmodified HEAD, unrelated.)

## Decisions confirmed

- **D2 held:** no organisation id in the URL. The bare `/dashboard/payments/{id}` redirects the
  logged-in operator into their organisation, and resolving `org_…` would cost an API call plus a
  cache for a cosmetic gain.

## Follow-ups (not done here)
- None required. The checkout `test-mode` badge (`ViewConfig::isTestMode()`) is about Mollie's
  *checkout* pages, which still live under `mollie.com/checkout/test-mode/…`, and is untouched.
