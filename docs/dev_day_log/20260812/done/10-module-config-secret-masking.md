# Done: Sprint 10 — Module Config Secret Masking (re-implementation)

**Date:** 2026-08-12
**Sprint:** [`../sprints/10-module-config-secret-masking.md`](../sprints/10-module-config-secret-masking.md)
**Visual walkthrough:** [`10-masking-walkthrough.html`](10-masking-walkthrough.html) — the browser evidence,
with screenshots
**Screenshots:** [`screenshots/`](screenshots/)

Both API keys now render `type="password"` on the module Settings tab, with a reveal toggle.
Gates green: **phpcs / phpstan level max (no new baseline) / phpmd clean, 538 unit + integration
tests**, `./bin/pre-commit-check.sh --full` → `COMMITABLE`. The masking drift guard is 10 tests; the
admin e2e is 6, all passing against the live shop.

## Why this is a re-implementation

The first implementation of this sprint, and its `done/` write-up and screenshots, were removed from
the working tree at ~14:21 on 2026-08-12 during Sprint 11's implementation — not by that session, and
not through git (the stash was empty, `20260811/` untouched, and `main` was at `16dfde0` throughout).
`status.md` was left claiming the sprint had shipped while nothing of it was in the tree: no template,
no `SECRET_MODULE_SETTINGS`, no lang idents, no tests.

Rather than resurrect it from memory, it was rebuilt from the (also regenerated) plan, with the two
facts the lost run had *discovered* folded in so they were not rediscovered the hard way:

1. The template chain is **four** overrides deep, not three.
2. Stripe's module id in this shop is `oe_payments_stripe_wallet`, not `osc_stripe_wallet`.

## What shipped

| Piece | File |
|---|---|
| The secret list — single source of truth | `src/Mollie/Core/MollieDefinitions.php` → `SECRET_MODULE_SETTINGS` |
| The mask + toggle | `views/twig/extensions/themes/admin_twig/module_config.html.twig` |
| Toggle labels (en/de) | `views/admin_twig/{en,de}/mollie_lang.php` → `MOLLIE_REVEAL_API_KEY` / `MOLLIE_HIDE_API_KEY` |
| Drift guard, 10 tests | `tests/Unit/Admin/ModuleConfigSecretMaskingTest.php` |
| Admin e2e, 6 tests | `tests/e2e/playwright/tests/admin/mollie-api-key-mask.spec.ts` |

No service, no interface, no `ModuleConfiguration` class-chain extension, no migration. One const, one
template, four lang lines, two test files.

## One improvement over the plan

The plan had the template naming the two keys. It doesn't: it reads the list through Twig's
`constant()`.

```twig
{% set mollie_secret_settings = constant('OxidEsales\\Payments\\Mollie\\Core\\MollieDefinitions::SECRET_MODULE_SETTINGS') %}
{% if mollie_is_mollie_module and module_var in mollie_secret_settings %}
```

Adding a future credential setting to the const masks it with **no template edit**. There is no second
list to drift out of step with the first — which is the whole point of the drift guard.

That change invalidated one of the drift guard's own assertions (it required each key's literal name to
appear in the template, which is now deliberately false), so the assertion was rewritten to pin the
*mechanism* — password input, marker attribute, and the constant reference — with a comment explaining
why literal names are the weaker test. The metadata reconciliation in both directions is unchanged and
is what actually catches drift.

## The risk that needed the browser, and how it resolved

`constant()` is core Twig, but nothing in the unit suite can tell you whether OXID's Twig environment
permits it. If it were restricted, the `{% if %}` yields false, every key falls through to
`{{ parent() }}`, and the tab renders **exactly as it does today — keys in clear text, no error
anywhere**. A silent failure in the revealing direction.

This is why the sprint was not committed until the e2e ran. It resolved green: `constant()` works, the
override resolves, and both keys are masked in the real admin. The walkthrough report carries the
screenshots.

## Story 4 — the four-deep chain, measured not assumed

```
extensions/stripe/views/twig/extensions/themes/admin_twig/module_config.html.twig
extensions/one-page-checkout/views/twig/extensions/themes/admin_twig/module_config.html.twig
extensions/mollie-payment/views/twig/extensions/themes/admin_twig/module_config.html.twig
extensions/paypal/views/twig/extensions/themes/admin_twig/module_config.html.twig
```

Four active modules override the same admin template, on top of the stock one. That is why
`{{ parent() }}` delegation is not a nicety: an override that swallows the block blanks out the settings
forms of every module earlier in the resolved chain. The e2e's sixth test pins the delegation from
Mollie's own side (non-secret rows still render, and `sMollieProfileId` stays `type="text"`), and the
walkthrough shows the other three modules' tabs still rendering in full.

`extensions/stripe_bkp/` also contains a copy; it is a backup directory, not an active module.

## Threat model — unchanged, and not to be oversold

The key still travels to the browser inside `value=""`. This stops **shoulder-surfing, screen sharing
and screenshots**. It does **not** hide the key from anyone who can open devtools on that page — and
anyone with access to this form can read the key by saving it anyway.

A true never-send-the-secret implementation (masked placeholder, persist only when the field actually
changes) needs save-side PHP on the module-config POST path and remains deferred to its own sprint.

## Evidence-capture note

The reveal screenshot shows a **dummy value** (`test_DUMMYVALUEFORSCREENSHOT0000`) typed into the field
before revealing, never saved. Proving that the toggle reveals a key does not require publishing a real
credential in an image, and a screenshot of the live test key would have been a worse leak than the one
this sprint fixes. The capture script that produced the screenshots was a one-off and has been deleted;
`mollie-api-key-mask.spec.ts` is the regression test.

## Found while proving it: OXID already has a password setting type, and it is better than this

The stock template supports `'type' => 'password'` for module settings
(`source/Application/views/admin_twig/tpl/module_config.html.twig:81-84`):

```twig
{% elseif var_type == 'password' %}
    <input class="password_input" type="password" name="confpassword[{{ module_var }}]"
           data-empty="{% if confpassword[module_var] %}false{% else %}true{% endif %}" …>
```

Note what is absent: **there is no `value` attribute at all.** `data-empty` says only *whether* a value
exists. The secret is never sent to the browser — which is exactly the "never-send-the-secret"
implementation this sprint's plan deferred as needing save-side PHP and its own sprint. It already
exists, in core, and `Application/Controller/Admin/ModuleConfiguration.php:26` registers the
`confpassword` request bucket for it.

**So the approach shipped here is second-best**, and the honest recommendation is to switch
`sMollieTestKey` / `sMollieLiveKey` to `'type' => 'password'` in `metadata.php` and delete the override,
the constant, the toggle JS and the drift guard's template assertions entirely.

It was **not** done in this sprint because it is not a free swap, and both risks end in a merchant
losing their API key:

1. **The storage bucket changes.** The value moves from `confstrs[…]` to `confpassword[…]`.
   `saveModuleConfigVariables()` calls the same `$moduleSetting->setValue($value)` either way, so the
   store is the same — but whether an existing value survives the declared-type change on
   `oe:module:install`/activate is unverified.
2. **An untouched field appears to submit an empty string.** Because the input renders with no value,
   saving the form without retyping the key posts `confpassword[sMollieTestKey] = ''`, and the save path
   applies it with no "skip if empty" guard visible in the controller. There is a `password_input` class
   and a `MODULE_REPEAT_PASSWORD` hint suggesting JS handles this somewhere, but that was not traced.

Both need verifying on a throwaway shop before any change. Getting it wrong wipes a live credential and
breaks checkout — a strictly worse outcome than the clear-text display this sprint fixed. Filed as the
top follow-up rather than attempted at the end of a session.

## Revision: the website profile id is masked too

Asked for after the first pass, and done: `sMollieProfileId` joins `SECRET_MODULE_SETTINGS`, so all
three string settings on the tab arrive as dots with a toggle.

The reason is **not** the same as for the API keys, and the code says so in three places (the constant's
docblock, the drift guard's `testProfileIdIsMaskedForScreenHygieneNotConfidentiality`, and the sprint's
D1 table) because blurring it would be the start of a false claim:

- The keys are masked because they are **secret** — they carry API authority.
- The profile id is masked for **screen hygiene**. The `pfl_…` id is shipped to the browser by the OPC
  footer widget for `new Mollie(profileId, …)`, so masking it in admin protects nothing against anyone
  who can read the storefront. What it buys is an account identifier that no longer sits in plain view
  during a screen share, and one fewer field an operator has to think about.

The original decision was to leave it visible precisely because masking implies protection it does not
have. That remains a fair point; it is recorded rather than deleted, and the test pins the *reason* so
nobody later concludes from the list that the profile id is a secret, or strips the masking on the
grounds that it isn't one.

Two consequential changes fell out of it:

- **The toggle labels are now generic.** `MOLLIE_REVEAL_API_KEY` → `MOLLIE_REVEAL_VALUE` (and the hide
  pair), because a button beside "Website profile ID" whose screen-reader label says "Reveal API key" is
  simply wrong.
- **The drift guard's name heuristic became a subset check.** It asserted that the settings whose names
  end in `key`/`secret`/`token`/`password` are *exactly* the masked list. With a deliberately-masked
  non-credential in the list that equality is wrong, so it now asserts every credential-looking setting
  is *in* the list. The drift-catching direction is unchanged; the list is free to be a superset.

The e2e's delegation test also had to move: it proved `{{ parent() }}` still works by asserting the
profile id stayed a plain text input. It now stands on `sMollieWebhookUrl`, which is genuinely
non-masked.

## Deliberately not done
- **Never-send-the-secret** — see the threat model above.
- **`SHOP_MODULE_sMollieLogLevel_*` option translations are still missing**, so the Log level select
  renders "ERROR: Translation not found". Real, carried over from the lost run's findings, and still not
  fixed here: it is a translation bug, and folding it into a security diff would muddy the review. Its
  own one-line change.
