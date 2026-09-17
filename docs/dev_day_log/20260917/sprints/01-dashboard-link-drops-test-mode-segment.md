# Sprint 01: Admin "Payment" tab — Mollie dashboard deep-link must not carry a `test-mode` path segment

**Date:** 2026-09-17
**Status: DONE.** Outcome in [`../done/01-dashboard-link-drops-test-mode-segment.md`](../done/01-dashboard-link-drops-test-mode-segment.md)

## Problem

On the *Payment* tab of an order paid with Mollie, the provider payment id is rendered as a link into
the Mollie merchant dashboard (`views/twig/admin/panel/mollie_panel.html.twig`,
`data-testid="mollie-dashboard-link"`). With the module in test mode the link reads

```
https://my.mollie.com/dashboard/org_19561800/test-mode/payments/tr_dWevmaHPVC5gyooRwEuWJ
```

but the dashboard's actual payment page is

```
https://my.mollie.com/dashboard/org_19561800/payments/tr_dWevmaHPVC5gyooRwEuWJ
```

Mollie's dashboard no longer encodes test/live in the URL path — the mode is a toggle inside the
dashboard, and the same `/payments/{id}` path resolves both test and live payments. The `test-mode`
segment we emit is stale and breaks the link for every test-mode order.

## Root cause

`src/Mollie/Service/MollieUrlBuilder.php` picks between two base URLs on
`ModuleConfigurationServiceInterface::isTestMode()`:

| mode | base |
|---|---|
| test | `https://my.mollie.com/dashboard/test-mode/payments/` ← wrong |
| live | `https://my.mollie.com/dashboard/payments/` ← right |

The live branch is already the correct shape. The mode branch as a whole is the defect.

## Decisions

- **D1 — mode-agnostic builder.** One base URL, no `isTestMode()` read. The
  `ModuleConfigurationServiceInterface` constructor dependency goes with it (it existed only for the
  branch). DI is autowired (`services.yaml` resource sweep), so no wiring change.
- **D2 — no organisation id in the URL.** The module has no `org_…` id (it would need an extra
  `GET /v2/organizations/me` API call and a place to cache it). The bare `/dashboard/payments/{id}`
  path already redirects the logged-in operator into their organisation context — this is the
  pre-existing behaviour of the live branch and stays. Not building the org lookup is deliberate
  (no overengineering).
- **D3 — keep `rawurlencode()`** on the payment id. Existing behaviour, existing test.

## Engineering requirements

| Principle | Application |
|---|---|
| TDD-first | Story 1 starts with a test asserting the test-mode URL has no `test-mode` segment; red on `b-7.4.x` before the change |
| SRP / YAGNI | `MollieUrlBuilder` does one thing: `paymentUrl(id)`; the now-unused config dependency is removed rather than left dangling |
| DIP | Consumers keep depending on `MollieUrlBuilder` by type; `MolliePanelViewDataBuilder` is untouched |
| DevOps-first | `./bin/pre-commit-check.sh` green (phpcs, phpstan, phpmd, Unit) before commit; CI (7.4 + 7.5, both isolated + shop-bound Unit) green after push |
| No own migrations / no template change | Only the URL string changes; the Twig template and `dashboardUrl` view key are unaffected |

## Story 1 — `MollieUrlBuilder::paymentUrl()` emits the same dashboard path in test and live mode

**Tests first** — `tests/Unit/Service/MollieUrlBuilderTest.php`:
- `testPaymentUrl_InTestMode_OmitsTheTestModeSegment` (RED: currently gets `…/test-mode/payments/…`)
- `testPaymentUrl_InLiveMode_UsesLiveDashboard` (stays green — pins the correct shape)
- `testPaymentUrl_EncodesThePaymentId` (stays green)
- Then, on the refactor step: drop the `ModuleConfigurationServiceInterface` mock from this test and
  from `tests/Unit/Admin/MolliePanelViewDataBuilderTest.php` (the two construction sites).

**Implementation:**
1. Collapse `DASHBOARD_TEST` / `DASHBOARD_LIVE` into one `DASHBOARD_PAYMENTS` constant.
2. Remove the constructor and the config dependency.
3. Update the class PHPDoc: the link is mode-agnostic, and say why.

**Definition of Done:** an order paid in test mode shows
`https://my.mollie.com/dashboard/payments/tr_…` on the Payment tab; unit suite green; gates green;
CI green on `b-7.4.x`; CHANGELOG `Unreleased → Fixed` entry.

## Out of scope
- Resolving the merchant's `org_…` id for a fully-qualified deep-link (D2).
- The `test-mode` badge in the checkout (`ViewConfig::isTestMode()`) — that is the *checkout* page,
  which Mollie still serves under `mollie.com/checkout/test-mode/…`; unrelated to the dashboard.
