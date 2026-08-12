# Sprint 10: Module Config — Mask Secret Settings Behind a Reveal Toggle

**Reference:** `../../20260710/sprints/_engeneering_requirements.md` (engineering requirements)
**Parent:** `../../20260629/sprints/00-roadmap.md`
**Related:** `../../20260811/reports/01-mollie-identification-vs-stripe-connect.md`
(recommendation #2 — "guided onboarding panel on the module config tab"; this sprint is the
first, smallest slice of that work)
**Reference implementation:** Stripe Sprint 113 —
`../../../../../stripe/views/twig/extensions/themes/admin_twig/module_config.html.twig`
**Date:** 2026-08-12

> **Regenerated 2026-08-12.** Both this plan and the implementation it describes were removed from
> the working tree at ~14:21 (the same event that took the day's other day-log files). The plan is
> reproduced here from the original, with the two facts the lost implementation had *discovered*
> folded into D2 and D4 — rediscovering them would be waste. Re-implementation notes live in
> [`../done/10-module-config-secret-masking.md`](../done/10-module-config-secret-masking.md).

## Overview

Mollie's module settings render through OXID's stock `module_config.html.twig`. Every string
setting is a plain `<input type="text">`, so **the live and test API keys are displayed in
clear text** on the Extensions → Modules → Mollie Payment → Settings tab. An admin opening
that tab in a meeting, on a shared screen, or during a screen recording leaks a credential
that can move money.

Stripe already solved this (Sprint 113): sensitive fields render as `type="password"` with an
adjacent eye button that toggles to `type="text"` and back, with `aria-pressed` / `aria-label`
kept in sync. **Copy that behaviour into Mollie**, namespaced to Mollie, with no PHP controller
extension and no restyle of the rest of the page.

### Scope boundary — be honest about the threat model

The value is still rendered into the `value=""` attribute and travels to the browser. This
mitigates **shoulder-surfing, screen sharing, and screenshots**. It does **not** hide the key
from anyone who can open devtools or view-source on that page — and those people already have
admin rights to the module-config form, i.e. they can read the key by saving it anyway.

Do **not** oversell it in the commit message or the changelog. A true never-send-the-secret
implementation (render a masked placeholder, persist only when the field actually changes)
requires save-side PHP and is **explicitly deferred** — see "Out of scope" below.

## Engineering Requirements (from `_engeneering_requirements.md`)

| Principle | Application to Sprint 10 |
|-----------|--------------------------|
| **TDD-first** | The drift-guard unit test (Story 1) is written and **red** before the template exists |
| **DevOps-first** | `./bin/pre-commit-check.sh` green before staging (PHPCS, PHPStan max, PHPMD, PHPUnit Unit) |
| **SOLID / SRP** | One template block override, one concern: presentation of secret-bearing inputs |
| **DIP** | No new service, no new interface — nothing to invert here; resist inventing a `SecretFieldRendererInterface` |
| **DRY** | The secret list has exactly **one** source of truth (`MollieDefinitions`), consumed by the test; the template's masked set is asserted against it |
| **No overengineering** | No `ModuleConfiguration` controller extension, no JS build step, no page restyle |
| **No new DB / migrations** | Presentation-only change |

## Key decisions

### D1 — Which settings are secret

| Setting | Secret? | Rationale |
|---|---|---|
| `sMollieTestKey` | **yes** | `test_…` API key — full API authority in test mode |
| `sMollieLiveKey` | **yes** | `live_…` API key — moves real money |
| `sMollieProfileId` | **no** | `pfl_…` is shipped to the browser by the OPC footer widget for `new Mollie(profileId, …)`. It is public by design; masking it would imply a confidentiality it does not have |
| `sMollieWebhookUrl` | **no** | A public URL. Mollie sends no signature (verification is an API re-fetch), so there is no webhook secret to protect |
| `sMollieMode`, `sMollieCaptureMode`, `sMollieLogLevel` | **no** | `select` widgets, non-sensitive |

Mollie has **no** webhook signing secret and **no** OAuth client secret today, so the secret set
is exactly two entries. If Mollie Connect ever lands (deferred per the 2026-08-11 report), its
client secret and refresh token join this list — and Story 1's drift guard is what will force
that to happen.

### D2 — No `ModuleConfiguration` controller extension

Stripe gates its template block on `oView.stripeIsStripe()`, which requires
`ModuleConfiguration::class => StripeModuleConfiguration::class` in `metadata.php`. Mollie must
**not** add a second extension to that chain:

- PayPal's own override already documents why:
  `attribute(oView, 'xxx') is defined` is unreliable against OXID's `__call`-based controllers
  (`is_callable()` always returns true), so calling a *sibling module's* predicate blows up.
- We have already been burned by multi-PSP class-chain ordering on
  `PaymentController` (see `MEMORY.md` — "Stripe+Mollie PaymentController chain crash").

**Gate on the stock base-Admin accessor instead**, exactly like PayPal:

```twig
{% set is_mollie_module = oView.getEditObjectId() == 'oe_payments_mollie' %}
```

Net effect: this sprint adds **zero** PHP to `src/` beyond one const. Only a template, two lang
idents, and tests.

### D3 — Own CSS/JS namespace, no shared classes

Use `mollie-key-field`, `button.mollie-key-toggle`, `data-mollie-secret`. Do not reuse Stripe's
`stripe-key-*` names: several modules ship a `module_config` override in this shop, and clashing
selectors would let one module's inline JS bind to another's buttons.

### D4 — Block delegation is mandatory, and the chain is deeper than it looks

The override implements `admin_module_config_var` and **must** call `{{ parent() }}` for every
`module_var` that is not a Mollie secret. Stripe's override does the same. Without it, whichever
module's template ends up later in the chain would swallow the other's fields and blank out the
form.

**The original plan said "three overrides"; the lost implementation found four.** `oe_onepage_checkout`
also overrides `admin_twig/module_config.html.twig`, and Mollie sits second in the resolved chain, so
its `{{ parent() }}` has to reach through PayPal, Stripe *and* the stock template. Verify the depth
live rather than assuming it (Story 4) — the count is a property of which modules are active, not of
this module.

Also recorded by the lost run, and worth not rediscovering: **Stripe's module id in this shop is
`oe_payments_stripe_wallet`**, not `osc_stripe_wallet` as the root `CLAUDE.md` example suggests.

## Stories

### Story 1: Drift-guard unit test — secret list vs. template markup

**As a** maintainer
**I want** a test that fails when a secret setting is added without masking
**So that** the next credential setting cannot silently ship in clear text

This is the TDD entry point: written first, **red** (no template file yet), and it stays as the
regression guard afterwards.

**Acceptance Criteria:**
- [ ] `MollieDefinitions::SECRET_MODULE_SETTINGS = ['sMollieTestKey', 'sMollieLiveKey']` — the
      single source of truth (a `public const array`, no getter, no service)
- [ ] Unit test reads the override template **as text** (precedent:
      `tests/Unit/Security/FrontendConfidentialityParityTest.php` pins presentation shape)
- [ ] Test: every name in `SECRET_MODULE_SETTINGS` appears in the template with
      `type="password"` and `data-mollie-secret`
- [ ] Test: no name in `SECRET_MODULE_SETTINGS` appears anywhere in the template with
      `type="text"`
- [ ] Test: each secret field is followed by a `button` with class `mollie-key-toggle`,
      `type="button"`, `aria-pressed="false"`, and a non-empty `aria-label`
- [ ] Test: the template contains `{{ parent() }}` (D4 delegation guard)
- [ ] Test: **metadata reconciliation** — every `settings` entry in `metadata.php` whose name is
      in `SECRET_MODULE_SETTINGS` is masked, and every masked name is a real setting in
      `metadata.php` (catches both "added a secret, forgot to mask" and "masked a typo")

**Files:**
- `tests/Unit/Admin/ModuleConfigSecretMaskingTest.php` — new
- `src/Mollie/Core/MollieDefinitions.php` — add `SECRET_MODULE_SETTINGS`

**Notes:**
- Assert on the template **source**, not on rendered HTML: the Unit suite has no Twig
  environment and standing one up (with OXID's `module_config.html.twig` parent + `translate()`
  + `help_id()` extensions available) is disproportionate for a two-field change. Rendering is
  covered for real by the e2e spec in Story 5.
- Keep the parsing dumb and readable — `str_contains` / one `preg_match_all` per assertion. No
  HTML parser dependency.

---

### Story 2: The template override

**As an** admin
**I want** the Mollie API keys masked by default
**So that** they are not readable over my shoulder or on a shared screen

**Acceptance Criteria:**
- [ ] Story 1's test goes green
- [ ] New file at the OXID 7 convention path (auto-discovered — **no** `metadata.php`
      `templates` entry needed; mirrors Stripe/PayPal):
      `views/twig/extensions/themes/admin_twig/module_config.html.twig`
- [ ] `{% extends "module_config.html.twig" %}`, overriding `admin_module_config_var`
- [ ] Secret vars render:
      `<input type="password" class="txt" name="confstrs[{{ module_var }}]"
      value="{{ confstrs[module_var] }}" {{ readonly }} data-mollie-secret autocomplete="off">`
- [ ] Adjacent `<button type="button" class="mollie-key-toggle" aria-pressed="false"
      aria-label=… data-label-reveal=… data-label-hide=…>` with the eye glyph `&#128065;`
- [ ] `{% include "inputhelp.html.twig" %}` preserved so the setting's help bubble survives
- [ ] The `<dd>` label (`SHOP_MODULE_ ~ module_var`) and the `<div class="spacer">` match the
      surrounding rows — the masked row must not look misaligned next to unmasked ones
- [ ] Every non-secret `module_var` falls through to `{{ parent() }}`
- [ ] `autocomplete="off"` set — no browser password-manager prompt on the module form
- [ ] The whole block is inside the `is_mollie_module` gate (D2)

**Files:**
- `views/twig/extensions/themes/admin_twig/module_config.html.twig` — new

**Explicitly not in this template:**
- No copy of Stripe's ~200-line "Sprint 114" page restyle (`body.module_config` inputs,
  `.groupExp`, submit buttons). That is a separate look-and-feel decision; smuggling it in here
  would make a security fix indistinguishable from a redesign in review. Only the CSS the
  toggle itself needs.

---

### Story 3: Toggle behaviour + lang idents

**As an** admin
**I want** one click to reveal a key and another to hide it, keyboard included
**So that** I can check or paste a key without leaving it exposed

**Acceptance Criteria:**
- [ ] Inline `<script>` in the `admin_module_config_form` block, gated by `is_mollie_module`,
      binding every `button.mollie-key-toggle` to its sibling `input[data-mollie-secret]`
- [ ] Click flips `input.type` between `password` and `text`
- [ ] `aria-pressed` tracks the revealed state; `aria-label` swaps between the
      `data-label-reveal` / `data-label-hide` values
- [ ] Input **value is never rewritten** by the toggle (no data loss on save)
- [ ] Keyboard: a native `<button>` already answers Enter and Space — assert it, don't
      hand-roll `keydown` handling
- [ ] `DOMContentLoaded` guard for the case where the script runs before the inputs exist
      (the admin edit frame ordering is not guaranteed)
- [ ] Scoped CSS only: `.mollie-key-field` (inline-block, `nowrap`) and `.mollie-key-toggle`
      (26px tall to match the stock `.txt` inputs, hover/focus ring, `aria-pressed="true"`
      state). No `!important`, no bare-element selectors
- [ ] Lang idents added to **both** `en` and `de`:
      `MOLLIE_REVEAL_API_KEY` / `MOLLIE_HIDE_API_KEY`
      (en: "Reveal API key" / "Hide API key"; de: "API-Schlüssel anzeigen" /
      "API-Schlüssel verbergen")

**Files:**
- `views/twig/extensions/themes/admin_twig/module_config.html.twig` — script + style blocks
- `views/admin_twig/en/mollie_lang.php` — 2 idents
- `views/admin_twig/de/mollie_lang.php` — 2 idents

**Notes:**
- No asset build. The script is ~15 lines; a `js/` file plus a `getMollieAdminScript()`
  view-config accessor would be more machinery than the feature.
- Copy Stripe's `attach(button)` / `init()` shape — it is already the minimal correct version.

---

### Story 4: Multi-module coexistence verification

**As a** shop operator running Mollie next to Stripe, PayPal and one-page-checkout
**I want** every module's settings tab to keep working
**So that** the new override does not blank out another module's form

Several modules already ship `admin_twig/module_config.html.twig`. This is the real risk in the
sprint, and it is integration-level, not unit-level.

**Acceptance Criteria:**
- [ ] Establish the **actual** chain depth and Mollie's position in it (D4 — the original plan
      guessed three, the truth was four). Record it.
- [ ] Integration test (or documented manual run, if the module-template chain cannot be built
      in the Integration suite): with all PSP modules active, the resolved `module_config`
      template chain includes every override and rendering does not throw
- [ ] Manual verification, recorded in `done/`: open **each** module's Settings tab and confirm
      (a) every setting row still renders,
      (b) Mollie's keys are masked **only** on Mollie's tab,
      (c) Stripe's keys are still masked on Stripe's tab,
      (d) PayPal's onboarding banner still appears on PayPal's tab
- [ ] If block-swallowing is observed, the fix is in the delegation (`{{ parent() }}`) and the
      `getEditObjectId()` gate — **not** by reordering modules, which is not something we control
      at a customer install

**Files:**
- `docs/dev_day_log/20260812/done/` — verification note with screenshots

---

### Story 5: Playwright e2e — masked by default, reveals on click

**As a** maintainer
**I want** the behaviour verified in the real admin
**So that** a Twig chain or asset-path regression is caught by CI, not by a customer

Port `../../../../../stripe/tests/e2e/playwright/playwright/tests/admin/stripe-api-key-mask.spec.ts`
and repath to Mollie (Mollie's e2e lives at `tests/e2e/playwright/tests/`, one level shallower
than Stripe's).

**Acceptance Criteria:**
- [ ] `SENSITIVE_FIELDS = ['sMollieTestKey', 'sMollieLiveKey']`
- [ ] Navigation helper: admin login → Extensions → Modules → row matching
      `/Mollie Payment/` → Settings tab → `edit` frame; expand the
      `Test credentials` / `Live credentials` group headers
      (`SHOP_MODULE_GROUP_MOLLIE_TEST_CONFIG` / `…_LIVE_CONFIG`) before asserting
- [ ] Test: both fields are `type="password"` on load
- [ ] Test: each has an adjacent `button.mollie-key-toggle` with `aria-pressed="false"` and a
      non-empty `aria-label`
- [ ] Test: click reveals (`type="text"`, `aria-pressed="true"`, `aria-label` changed), second
      click re-masks and restores the original `aria-label`
- [ ] Test: `Enter` and `Space` on the focused toggle operate it
- [ ] Test: `inputValue()` is unchanged across a reveal/re-mask cycle
- [ ] Reuse Mollie's existing admin login fixture rather than importing Stripe's page objects

**Files:**
- `tests/e2e/playwright/tests/admin/mollie-api-key-mask.spec.ts` — new

---

## Out of scope (deferred, with reasons)

| Deferred | Why |
|---|---|
| **Never send the secret to the browser** (masked placeholder + save-only-if-changed) | Needs save-side PHP on the module-config POST path — a behaviour change to persistence, not presentation. Worth its own sprint; state plainly that Sprint 10 does not deliver it |
| Masking `sMollieProfileId` | Public by design (D1) — masking would misrepresent it |
| Stripe's Sprint 114 page restyle | Separate look-and-feel decision (Story 2 note) |
| `test_`/`live_` prefix validation against `sMollieMode` | 2026-08-11 report recommendation #2; belongs with the guided-onboarding panel |
| Profile dropdown from `GET /v2/profiles` | Same — needs an API round-trip from the admin controller |
| Write-only settings via `oxconfig` outside the form (Stripe's webhook-secret trick) | Mollie has no webhook secret; nothing to hide this way |
| **Missing `SHOP_MODULE_sMollieLogLevel_*` option translations** | Found by the lost run: the Log level select renders "ERROR: Translation not found". Real, but fixing it inside a security diff would muddy the review. Its own tiny change |

## Definition of Done

- [ ] Story 1 test written first and observed **red**, then green
- [ ] `composer phpcs` clean (the new PHP is one const + one test class)
- [ ] `composer phpstan` at level max, **no new baseline entries**
- [ ] `composer phpmd` clean
- [ ] `vendor/bin/phpunit -c tests/phpunit.xml --testsuite Unit` green
- [ ] `./bin/pre-commit-check.sh` green before staging
- [ ] Story 4 multi-module verification recorded in `done/`
- [ ] Story 5 Playwright spec passing against the local shop
- [ ] `docs/dev_day_log/20260812/status.md` updated with the outcome
- [ ] Commit message ends with the `Co-Authored-By` trailer

## Risk register

| Risk | Likelihood | Mitigation |
|---|---|---|
| Mollie's block swallows another module's fields via the shared template chain | medium | D4 `{{ parent() }}` delegation + `getEditObjectId()` gate + Story 4, with the chain depth measured rather than assumed |
| Calling a sibling module's view predicate (`stripeIsStripe`-style) fatals | low | D2 — we never call one; gate on stock `getEditObjectId()` |
| Inline JS binds to Stripe's toggles or vice versa | low | D3 — separate class names and data attribute |
| `readonly` var interpolation (`{{ readonly }}`) differs from Stripe's context | low | Keep it; verify visually in Story 4 — Mollie keys are always editable (no Connect flow) |
| Someone reads the sprint as "the key is now confidential" | medium | Threat model stated in the Overview; repeat it in the commit body and `status.md` |
