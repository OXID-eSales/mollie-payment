# Dev log — 2026-08-12 (Mollie module)

## Sprint 10 DONE: module config — mask secret settings behind a reveal toggle

Mollie's module settings currently render through OXID's stock `module_config.html.twig`, so
`sMollieTestKey` and `sMollieLiveKey` are shown as plain `<input type="text">` — the API keys are
readable on screen on the Extensions → Modules → Mollie Payment → Settings tab. Stripe solved
this in its Sprint 113 (`type="password"` + eye-toggle button); this sprint ports that behaviour
to Mollie.

- **Sprint:** [sprints/10-module-config-secret-masking.md](sprints/10-module-config-secret-masking.md)

### Shape of the work

- Secret set is exactly **two** settings (`sMollieTestKey`, `sMollieLiveKey`). `sMollieProfileId`
  is deliberately **not** masked — the `pfl_…` id is shipped to the browser by the OPC footer
  widget for Mollie Components, so masking it would imply a confidentiality it does not have.
  Mollie has no webhook signing secret (webhook verification is an API re-fetch, not an HMAC).
- **Zero new PHP in `src/`** beyond one `MollieDefinitions::SECRET_MODULE_SETTINGS` const: gate
  the template on the stock `oView.getEditObjectId() == 'oe_payments_mollie'` like PayPal does,
  rather than adding a second `ModuleConfiguration` class-chain extension next to Stripe's.
- TDD entry point is a **drift-guard unit test** that reconciles `SECRET_MODULE_SETTINGS` against
  both `metadata.php` and the template source — so the next credential setting cannot ship
  unmasked.
- Main risk is template-chain coexistence: Stripe and PayPal already override
  `admin_twig/module_config.html.twig`, Mollie makes three. Mandatory `{{ parent() }}`
  delegation plus a three-module verification story.

### Threat model — stated up front

The value still travels to the browser in the `value=""` attribute. This mitigates
**shoulder-surfing, screen sharing and screenshots**; it does **not** hide the key from anyone
who can open devtools on that page (who, having access to the module-config form, can read the
key by other means anyway). A true never-send-the-secret implementation needs save-side PHP and
is explicitly deferred to its own sprint.

### Outcome — shipped 2026-08-12

Both API keys now render `type="password"` with an eye toggle. **Write-up:**
[done/10-module-config-secret-masking.md](done/10-module-config-secret-masking.md).

The whole change is one `MollieDefinitions::SECRET_MODULE_SETTINGS` const, one Twig override, two
lang idents, and three test files — no service, no interface, no `ModuleConfiguration` extension,
no migration. Drift-guard test written first and observed red before the const and template
existed.

Two sprint assumptions turned out wrong and are corrected in the write-up:

- The chain is **four** overrides deep, not three — `oe_onepage_checkout` also overrides
  `admin_twig/module_config.html.twig`, and Mollie sits second, so its `{{ parent() }}` has to
  reach through PayPal, Stripe *and* the stock template. Verified live rather than assumed.
- Stripe's module id here is `oe_payments_stripe_wallet`, not `osc_stripe_wallet`.

Three-PSP verification (Story 4) passed on all four criteria — every settings tab still renders
in full (Mollie 7 rows / Stripe 17 / PayPal 14), Mollie masks only on its own tab, Stripe's 7
sensitive fields still mask on Stripe's tab, PayPal's onboarding banner survives. Screenshots in
`done/screenshots/`.

Gates: phpcs / phpstan level max (no new baseline) / phpmd clean; 439 unit + 28 integration tests
green; 5/5 Playwright.

**The threat model has not changed and should not be oversold:** the key still travels to the
browser inside `value=""`. This stops shoulder-surfing, screen sharing and screenshots — not
devtools. Never-send-the-secret remains deferred to its own sprint.

Found on the way, not fixed (would muddy a security diff): the `SHOP_MODULE_sMollieLogLevel_*`
option translations are missing, so the Log level select shows "ERROR: Translation not found".

---

## Audit: unnecessary and dangerous fallbacks

Full static read of `src/` (129 files, ~9.2k LOC) hunting every place the module substitutes a guess
for a fact — `?? default`, `catch` returning a benign value, guards that pass when they cannot run,
lenient `default =>` arms — and asking of each: if this fires, does anyone find out, and is the
substituted value safe?

- **Report:** [reports/01-unnecessary-and-dangerous-fallbacks.md](reports/01-unnecessary-and-dangerous-fallbacks.md)

### The four that matter

1. **Webhook event id is the payment id** (`MollieWebhookProcessor:81`) while payment-base's
   `oe_payments_webhooklogs` has `UNIQUE(OXEVENTID)` alone. The first delivery for `tr_xxx` claims it
   permanently, so `authorized → paid`, `pending → paid`, and every refund/chargeback delivery is
   answered `200 skipped`. The return leg still fulfils orders, so this kills the *backstop*, not
   every payment — but the backstop's whole job is the case where the shopper never returns.
2. **`WebhookResult::skipped()` is `success = true`** → HTTP 200 → Mollie never retries. "Contract
   not found" and "could not be committed" get the same answer as "already fulfilled". Smallest,
   highest-value fix in the report.
3. **The webhook guard chain fails open**: `getGuard()?->check()` plus a `!== null` test means an
   unbuildable guard service silently removes HTTPS/size/rate-limit/IP checks — while a missing
   *processor* three lines down is fail-closed with a 500.
4. **`getMode()` falls back to test mode** on any config-read failure, silently swapping to the test
   API key in a live shop: shoppers "pay", orders fulfil, no money is collected.

Plus: `amountChargedBack` is never mapped from the SDK and the DTO's `0.0` default hides it, so the
refundable ceiling is overstated by the charged-back amount (real double-spend); the rate-limit guard
keeps its token buckets in per-request memory and therefore cannot ever fire; and the OPC handler
forges `$_POST['sDeliveryAddressMD5']`, neutralising OXID's address-tamper check.

**Common thread:** nearly every dangerous fallback lives in a class with **no logger injected at
all** (7 of them). Adding loggers fixes no bug but makes all of them detectable.

Section D of the report lists the fallbacks that are correct as-is, so they don't get "fixed" later.

---

## Sprint 11 planned: fallback hardening (from the audit)

TDD sprint covering the findings that can cost money or hide a failure, in the report's priority
order. Five phases, 11 stories.

- **Sprint:** [sprints/11-fallback-hardening.md](sprints/11-fallback-hardening.md)

Organising principle: **a fallback must either be safe or be loud** — never both silently.

- **Phase A (webhook delivery contract)** — split `skipped` from `failure` so Mollie retries what we
  failed to process; per-delivery event id `tr_x:paid` / `tr_x:refunded:1250` (the cents
  discriminator matters: without it two partial refunds still collide); bounded retry for
  contract-not-found using the payment's own `createdAt`, so the fix isn't a retry storm for
  payments that will never have a contract here.
- **Phase B (money)** — map `amountChargedBack` and **remove the `= 0.0` default** so the compiler
  finds the next such omission; collapse the duplicated refundable formula.
- **Phase C (fail-closed)** — guard chain answers 503 instead of bypassing; `X-Forwarded-Proto`
  gated behind a default-off proxy-trust flag; rate-limit guard removed from the chain and replaced
  by a stateless `tr_`-prefix precheck *before* the API round-trip (the only real exposure it was
  aiming at); unreadable config throws instead of falling back to test mode — while a legitimately
  *unset* mode still defaults to test.
- **Phase D (observability)** — the eight loggerless classes get a logger and use it, plus a
  `NoSilentCatchRegressionTest` with a deliberate allowlist.
- **Phase E** — capture-bound ambiguity, the `contract_token`-bearing metadata write, the unbounded
  residual fold, and four `'EUR'` guesses collapsed into one accessor.

**Deferred with a paper trail:** F10 (`$_POST['sDeliveryAddressMD5']` forging) needs a decision from
whoever owns the OPC integration — the sprint still writes the test that documents today's behaviour
rather than leaving it undocumented.

**Two honest consequences flagged in the risk register:** a shop that has been silently running on
its test key will see Mollie disappear from checkout until configured (the bug surfacing, not a new
one), and F1's runtime confirmation — a real two-delivery `authorized → paid` sequence — is a DoD
item, since it's the one finding static reading could not settle.

