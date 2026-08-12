# Done — Sprint 10: Module config, mask secret settings behind a reveal toggle

**Date:** 2026-08-12
**Sprint:** [../sprints/10-module-config-secret-masking.md](../sprints/10-module-config-secret-masking.md)

## What shipped

`sMollieTestKey` and `sMollieLiveKey` now render as `type="password"` with an adjacent eye
button on Extensions → Modules → Mollie Payment → Settings. Everything else on that page is
untouched.

| File | Change |
|---|---|
| `src/Mollie/Core/MollieDefinitions.php` | `+ SECRET_MODULE_SETTINGS` (the only new PHP) |
| `views/twig/extensions/themes/admin_twig/module_config.html.twig` | new — the override |
| `views/admin_twig/{en,de}/mollie_lang.php` | `+ MOLLIE_REVEAL_API_KEY`, `+ MOLLIE_HIDE_API_KEY` |
| `tests/Unit/Admin/ModuleConfigSecretMaskingTest.php` | new — drift guard (8 tests) |
| `tests/Integration/Module/ModuleConfigTemplateChainTest.php` | new — chain coexistence (5 tests) |
| `tests/e2e/playwright/tests/admin/mollie-api-key-mask.spec.ts` | new — e2e (5 tests) |
| `tests/e2e/playwright/playwright.config.ts` | `+ mollie-admin-key-mask` project |

Zero new services, zero interfaces, zero controller extensions, zero migrations.

## Threat model — read this before quoting the sprint anywhere

The key value is **still rendered into the `value=""` attribute and still travels to the
browser**. This mitigates **shoulder-surfing, screen sharing and screenshots**. It does **not**
hide the key from anyone who can open devtools or view-source on that page — and those people
already have admin rights to the module-config form, so they can read the key by saving it
anyway. Never-send-the-secret (masked placeholder + save-only-if-changed) needs save-side PHP on
the module-config POST path and is **deferred to its own sprint**; Sprint 10 does not deliver it.

## Story 4 — PSP coexistence verification

### The template chain is four deep, not three

The sprint assumed three overrides (Mollie, Stripe, PayPal). The resolved chain in this shop,
read from the shop's own `TemplateChainResolver` with all modules active, is:

```
last child: @oe_onepage_checkout/extensions/themes/admin_twig/module_config.html.twig
  0: @oe_onepage_checkout/…/module_config.html.twig
  1: @oe_payments_mollie/…/module_config.html.twig
  2: @oe_payments_paypal/…/module_config.html.twig
  3: @oe_payments_stripe_wallet/…/module_config.html.twig
  (then the stock source/Application/views/admin_twig/tpl/module_config.html.twig)
```

`oe_onepage_checkout` also overrides this template. Mollie sits **second**, so its
`{{ parent() }}` has to traverse PayPal *and* Stripe *and* the stock template — which the
integration test now exercises for real rather than assuming.

Two other corrections to the sprint's assumptions, for the record:

- Stripe's module id in this shop is **`oe_payments_stripe_wallet`**, not `osc_stripe_wallet`
  as the sprint text says.
- Stripe's `admin_module_config_var` override is gated on **variable name only**, not on
  `getEditObjectId()`. That is why Stripe's fields keep masking on every tab and why Mollie's
  `getEditObjectId()` gate is the thing doing the isolating here.

### Manual run — all three PSP settings tabs

Admin opened on each module's Settings tab with every group expanded, counts read out of the
live `edit` frame. Screenshots in [`screenshots/`](screenshots/).

| Tab | setting rows | `data-mollie-secret` | `data-stripe-secret` | clear-text inputs | PayPal banner |
|---|---|---|---|---|---|
| Mollie Payment | 7 | **2** | 0 | 2 | 0 |
| Stripe Wallet | 17 | 0 | **7** | 1 | 0 |
| PayPal Payment | 14 | 0 | 0 | 8 | **1** |

Against the sprint's acceptance criteria:

- **(a) every setting row still renders** — yes. Mollie's 7 rows match its 7 `metadata.php`
  settings; Stripe 17; PayPal 14. No blanked form anywhere.
- **(b) Mollie's keys masked only on Mollie's tab** — yes. `data-mollie-secret` count is 2 on
  Mollie's tab and 0 on both others.
- **(c) Stripe's keys still masked on Stripe's tab** — yes, all 7 of Stripe's sensitive fields.
- **(d) PayPal's onboarding banner still appears** — yes, `.oe-paypal-connect-banner` present,
  and the "PayPal Connect (onboarding)" group renders.

No block-swallowing observed, so no delegation fix was needed. Nothing was reordered.

The two clear-text inputs on Mollie's tab are `sMollieProfileId` and `sMollieWebhookUrl` — both
deliberately unmasked per decision D1 (the `pfl_…` profile id is shipped to the browser by the
OPC footer widget and is public by design; Mollie signs no webhook, so there is no webhook
secret). Visual check: the masked rows line up with the unmasked ones, no misalignment.

## Test results

| Gate | Result |
|---|---|
| `phpcs` (PSR-12) | clean |
| `phpstan` level max | no errors, no new baseline entries |
| `phpmd` | clean |
| PHPUnit Unit | 439 tests, 1130 assertions — green |
| PHPUnit Integration | 28 tests, 122 assertions — green (3 pre-existing skips) |
| Playwright `mollie-admin-key-mask` | 5/5 passed against the local shop |

The drift-guard test was written first and observed **red** (3 errors for the missing
`SECRET_MODULE_SETTINGS`, 5 failures for the missing template) before either existed.

One useful accident: the guard's "no clear-text input in this template" assertion fired on a
`<input type=text>` string sitting in the template's own doc **comment**. The comment was
reworded rather than the assertion loosened — the strict form is what makes the guard worth
having.

## Follow-ups found, deliberately not fixed here

- **`SHOP_MODULE_sMollieLogLevel_{off,errors,normal,debug}` translations are missing**, so the
  Log level select renders "ERROR: Translation not found" for each option (visible in
  `screenshots/mollie-settings.png`). Pre-existing, unrelated to masking — folding it into a
  security commit would make the diff harder to review. Worth a one-line follow-up.
- Everything in the sprint's "Out of scope" table stands: never-send-the-secret, masking
  `sMollieProfileId`, Stripe's Sprint 114 page restyle, `test_`/`live_` prefix validation, the
  profile dropdown from `GET /v2/profiles`.
