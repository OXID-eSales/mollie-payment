# Dev log — 2026-08-12 (Mollie module)

> **Note on this file.** `status.md`, `sprints/` and `reports/` for this day were removed from disk
> at ~14:21 while Sprint 11 was being implemented (not by the implementing session, and not via
> git — the stash was empty and `20260811/` is untouched). This file has been rewritten from the
> work that was actually done. The two long documents it used to link — the fallback audit report
> and the Sprint 11 plan — are **not** reconstructed here; they can be regenerated on request. The
> Sprint 11 outcome write-up in `done/` survived and is the authoritative record of what shipped.
> Sprint 10's `done/` write-up and its screenshots were lost in the same event.

## Sprint 10 DONE: module config — mask secret settings behind a reveal toggle

`sMollieTestKey` / `sMollieLiveKey` rendered as plain `<input type="text">` on the module Settings
tab. Both now render `type="password"` with an eye toggle, ported from Stripe's Sprint 113.

Shape of the change: one `MollieDefinitions::SECRET_MODULE_SETTINGS` const, one Twig override, two
lang idents, three test files — no service, no interface, no `ModuleConfiguration` class-chain
extension, no migration. The template gates on the stock
`oView.getEditObjectId() == 'oe_payments_mollie'` like PayPal does, rather than adding a second
extension beside Stripe's.

`sMollieProfileId` is deliberately **not** masked: the `pfl_…` id is shipped to the browser by the
OPC footer widget for Mollie Components, so masking it would imply a confidentiality it does not
have. Mollie has no webhook signing secret (verification is an API re-fetch, not an HMAC).

**Threat model, not to be oversold:** the value still travels to the browser inside `value=""`. This
stops shoulder-surfing, screen sharing and screenshots — not devtools. Never-send-the-secret needs
save-side PHP and remains deferred.

## Audit: unnecessary and dangerous fallbacks

Full static read of `src/` (129 files, ~9.2k LOC) for every place the module substitutes a guess for
a fact — `?? default`, `catch` returning a benign value, guards that pass when they cannot run,
lenient `default =>` arms — asking of each: if this fires, does anyone find out, and is the
substituted value safe? 24 findings, F1–F24.

The four that mattered:

1. **Webhook event id was the payment id** while payment-base's `oe_payments_webhooklogs` has
   `UNIQUE(OXEVENTID)` alone. The first delivery for `tr_xxx` claimed it permanently, so
   `authorized → paid`, `pending → paid` and every refund/chargeback delivery was answered
   `200 skipped`. The return leg still fulfils orders, so this killed the *backstop* — whose whole
   job is the case where the shopper never returns.
2. **`WebhookResult::skipped()` is `success = true`** → HTTP 200 → Mollie never retries. "Contract
   not found" and "could not be committed" got the same answer as "already fulfilled".
3. **The webhook guard chain failed open**: `getGuard()?->check()` plus a `!== null` test meant an
   unbuildable guard service silently removed HTTPS/size/rate-limit/IP checks — while a missing
   *processor* three lines down was fail-closed with a 500.
4. **`getMode()` fell back to test mode** on any config-read failure, silently swapping to the test
   API key in a live shop: shoppers "pay", orders fulfil, no money is collected.

Plus: `amountChargedBack` was never mapped from the SDK and the DTO's `0.0` default hid it, so the
refundable ceiling was overstated by the charged-back amount; the rate-limit guard kept its token
buckets in per-request memory and could never fire; and the OPC handler forges
`$_POST['sDeliveryAddressMD5']`, neutralising OXID's address-tamper check.

**Common thread:** nearly every dangerous fallback lived in a class with **no logger injected at
all** (seven of them). Adding loggers fixes no bug but makes all of them detectable.

## Sprint 11 DONE: fallback hardening

All 11 stories implemented across five phases. **Write-up:**
[done/11-fallback-hardening.md](done/11-fallback-hardening.md) — full finding-by-finding table,
the runtime evidence, and the follow-ups.

Gates: phpcs / phpstan level max (no new baseline) / phpmd clean; **501 unit + 27 integration**
green (`./bin/pre-commit-check.sh --full` → COMMITABLE). +70 tests over the 431 baseline.

### Three sprint assumptions turned out wrong

- **F5's default-off proxy trust broke webhook delivery outright and was reversed.** This shop is
  Cloudflare-fronted: the origin sees `HTTPS` unset, port 80, `X-Forwarded-Proto: https` (verified
  directly). With trust off the HTTPS guard answered `400 tls_required` to *every* genuine Mollie
  delivery — reintroducing the silent "order never finalizes" failure F1/F2 exist to prevent, in
  exchange for a transport check worth little on an endpoint that is unauthenticated by design and
  verified by an API re-fetch. Now honoured by default, the flag kept for origin-TLS shops, and
  logged whenever the verdict rests only on the header.
- **F7's "make the parameter required" would not have caught the bug** — a required parameter forces
  you to type a value, not the right one, and would have churned ~26 fixtures into permanent noise.
  Replaced with a mapping regression test using distinct non-zero money values per field, which
  reproduced the over-refund as `95.0 !== 55.0` on its first run.
- **F20 could not inject `ShopAdapterInterface`** — that closes a DI cycle (`OxidShopAdapter` depends
  on the config service, so the container would refuse to compile). Derivation moved to a new
  `MollieWebhookUrlProvider`, asserted against the real container.

### F1 confirmed at runtime

The shop's own webhook log carries the finding's footprint: three payments with an `authorized` row
and **no `paid` row**, and not one of 56 rows ever had a sibling — because it was impossible. Live
through the real endpoint, a delivery for a payment whose bare id was claimed twelve days earlier
now claims `tr_…:authorized` and is processed, while a true replay still dedupes.

### E2E

Two `MollieStandard` specs fail — **verified pre-existing** by stashing `src/` back to HEAD and
reproducing the identical failures. They are written for the classic redirect flow, but this shop has
inline-card checkout on (iframe flag + profile id), so the order page renders the Components widget.
OPC 3/4 pass, including `BuyNowPaysAndFinalizes` — a real Mollie payment through to order
finalization.

### Deliberately not fixed

**F10** — the `$_POST['sDeliveryAddressMD5']` forging still neutralises OXID's address-tamper check
on the OPC path. Pinned by a `known-issue`-grouped test so it cannot be forgotten; the fix changes
one-page-checkout's contract and is not this sprint's call.
