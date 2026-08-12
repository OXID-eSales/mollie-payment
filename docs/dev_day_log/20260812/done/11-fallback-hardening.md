# Done: Sprint 11 — Fallback Hardening

**Date:** 2026-08-12
**Sprint:** [`../sprints/11-fallback-hardening.md`](../sprints/11-fallback-hardening.md)
**Report:** [`../reports/01-unnecessary-and-dangerous-fallbacks.md`](../reports/01-unnecessary-and-dangerous-fallbacks.md)

All 11 stories implemented. Gates green: **phpcs / phpstan (level max, no new baseline) / phpmd
clean, 501 unit + 27 integration tests** (`./bin/pre-commit-check.sh --full` → `COMMITABLE`).
Baseline before the sprint was 431 unit tests; +70 are new.

## What changed, by finding

| Finding | Fix | Where |
|---|---|---|
| **F1** webhook event id = payment id | Event id now identifies the **delivery**: `tr_x:paid`, `tr_x:refunded:1250` | `MollieWebhookProcessor::buildEventId()` |
| **F2** `skipped()` = success = 200 | New `FulfillmentOutcome` enum splits the four cases; failures return 5xx so Mollie retries | `FulfillmentOutcome`, `AbstractMollieWebhookHandler`, `WebhookContractFulfillmentHandler` |
| **F2/D3** unbounded retry risk | Contract-not-found retries only while the payment is younger than 10 min, then logs + 200 | `AbstractMollieWebhookHandler::CONTRACT_WAIT_WINDOW_SECONDS` |
| **F3** guard chain failed open | Explicit three-way; an unbuildable chain answers `503 guard_unavailable` and processes nothing | `WebhookController::render()` |
| **F4** unreadable config → test mode | `getMode()`/`isTestMode()`/`getApiKey()` throw `MollieConfigurationException`; an *unset* mode still defaults to test | `ModuleConfigurationService` |
| **F5** `X-Forwarded-Proto` trusted | **Reversed after runtime evidence — see below** | `WebhookController::TRUST_PROXY_HEADERS_DEFAULT` |
| **F6** rate limiter cannot fire | Removed from the chain, docblock corrected; replaced by a stateless `tr_`-shape precheck *before* the API round-trip | `services.yaml`, `WebhookRateLimitGuard`, `WebhookController::extractPaymentId()` |
| **F7** `amountChargedBack` unmapped | Mapped; `mapPayment()` converted to named arguments; duplicate refundable formula deleted | `MollieAdapter`, `RefundService` |
| **F8** `DomainException` swallowed | Logged with the attempted transition; the "no order id" case logged explicitly | `WebhookContractFulfillmentHandler::attemptTransition()` |
| **F9** activation swallowed failures | Catch kept (activation must not fail), now logs at `error` with recovery instructions | `Core\Events` |
| **F11** `amountRemaining === 0` ambiguous | `capturableAmount()` keys on payment status; `CaptureService`'s copy deleted | `MolliePaymentDto` |
| **F12** silent 0.00 bounds | Logger injected; the failure is logged | `AdminActionBounds` |
| **F13** validation fails open silently | Direction kept, now logged at `warning` | `PaymentController::userDataIsValid()` |
| **F14** unbounded residual fold | Ceiling `max(0.05, 1% of total)`; above it, throws instead of shipping an unitemised Klarna invoice | `MollieLinesBuilder` |
| **F15** shopper's method silently dropped | Logged with contract id and dropped method | `CheckoutPaymentService` |
| **F16** `supportsCurrency()` permissive | An empty/unknown currency list now means **not supported** | `MollieDefinitions` |
| **F17** four `'EUR'` guesses | One `OxidCurrencyReader` that reports failure; regression test forbids the literal outside `MollieDefinitions` | `OxidCurrencyReader` + 4 call sites |
| **F18** `contract_token` persisted | `?? $redirectUrl` dropped from `setProvider()`/metadata | `MollieCheckoutSessionHandler` |
| **F19** NullLogger defaults | Loggers required in all three services; `src/` is now free of `NullLogger` | `RefundService`, `ContractRefundRecorder`, `OxidStockRestorationService` |
| **F20** `Registry::getConfig()` in a service | Derivation moved to `MollieWebhookUrlProvider`; config exposes `getWebhookUrlOverride()` | new service + interface |
| **F10** `$_POST` hash forging | **Deferred by design** — documented by a `known-issue` test | `DeliveryAddressHashForgingKnownIssueTest` |

## Three places the sprint plan was wrong

Written down because each was decided against the sprint text, on evidence.

### 1. F5's default-off proxy trust broke webhook delivery entirely — reversed

The sprint said: gate `X-Forwarded-Proto` behind a default-off flag, because anyone can send that
header. Shipped that way, then tested against the real deployment:

```
$ curl -s https://daniil.oxiddev.de/index.php?cl=MollieWebhookController -d id=tr_…
{"action":"tls_required"}   HTTP 400
```

The shop sits behind Cloudflare. TLS terminates at the proxy, so the origin sees
`HTTPS='(unset)'`, `SERVER_PORT='80'`, `HTTP_X_FORWARDED_PROTO='https'` — verified directly. With
trust off, `WebhookHttpsGuard` rejects **every genuine Mollie delivery**. Mollie retries a few
times, gives up, and the shop silently stops finalizing orders — precisely the failure F1 and F2
were fixed to prevent, reintroduced by a hardening measure. That is the normal production topology
for a reverse-proxied shop, not an exotic setup.

The trade is lopsided in the other direction too: Mollie only ever calls an HTTPS URL, the body is
a bare payment id, and verification is the authenticated API re-fetch — so spoofing the header buys
an attacker nothing on an endpoint that is unauthenticated by design.

**Resolution:** honoured by default; the flag stays so a shop terminating TLS at the origin can
harden it; and a `debug` line records whenever the HTTPS verdict rests only on the header, so the
weak signal is visible rather than assumed away. The reasoning is in the constant's docblock, not
just here.

### 2. F7's "make the parameter required" would not have caught the bug

The sprint specified making `MolliePaymentDto::$amountChargedBack` a required constructor
parameter. That forces an author to *type* a value — it does not stop them typing `0.0`, which is
exactly the wrong value. It would also have churned ~26 fixtures across 15 test files into
permanent noise.

Implemented instead: `PaymentMoneyMappingRegressionTest` feeds the adapter an SDK payment whose
money fields all carry **distinct non-zero values** and asserts each arrives intact, plus a
reflection assertion that fails when a new money field appears on the DTO without being added to
the test. That catches a dropped mapping *and* a mapping crossed with the wrong accessor. The
first run failed with `95.0 !== 55.0` — the over-refund, reproduced.

### 3. F20 could not be fixed by injecting `ShopAdapterInterface`

The sprint said to inject `ShopAdapterInterface` (it already exposes `getShopUrl()`). That closes a
dependency cycle: `OxidShopAdapter` depends on `ModuleConfigurationServiceInterface`, so the
container would refuse to compile. Deriving a URL is also a different responsibility from reading
configuration, so it moved to `MollieWebhookUrlProvider` — which resolves cleanly and is asserted
against the real container in `ServicesContainerTest`.

## F1 runtime confirmation (DoD)

**Production evidence, from the shop's own webhook log** — 56 rows, of which 51 are real
deliveries:

- Three payments carry an `authorized` row and **no `paid` row**
  (`tr_xv7ngHN4BWAqjQn2sEjUJ`, `tr_3dBbzSaeEEyTPju2FiiUJ`, `tr_CDpAkLhhvoHxfDnyDhiUJ`). That is the
  `authorized → paid` sequence with the decisive delivery dropped.
- Not one payment id in 56 rows has more than one row — because it was impossible.

**Live confirmation through the real HTTP endpoint**, using a payment whose bare id was already
claimed on 2026-07-31:

```
rows BEFORE:  tr_xv7ngHN4BWAqjQn2sEjUJ            authorized  processed   (2026-07-31)
POST #1    →  {"action":"skipped"}  HTTP 200
POST #2    →  {"action":"skipped"}  HTTP 200
rows AFTER:   tr_xv7ngHN4BWAqjQn2sEjUJ            authorized  processed   (2026-07-31)
              tr_xv7ngHN4BWAqjQn2sEjUJ:authorized authorized  processed   (2026-08-12)
```

POST #1 claimed its own delivery-scoped row and was **processed**, despite the bare id having been
claimed twelve days earlier — under the old code `claimEvent()` would have returned false and the
delivery would have been dropped with a 200. POST #2 created no row: a true replay still dedupes.
Both answered 200 because the payment's real status is `authorized` and the contract is already past
authorization, i.e. a genuine `NoOp` — which is the correct 200.

**What was not observed:** a fresh `authorized → paid` pair generated by Mollie itself in this
session. That needs a new manual-capture payment plus an admin capture. What is confirmed is the
mechanism that was broken (a second delivery for an already-claimed payment is now processed rather
than dropped), against the real MySQL `UNIQUE(OXEVENTID)` index, through the real endpoint.

Also proved against the real index in `DoctrineIdempotencyClaimTest`:
`testBarePaymentIdWouldStillCollideAcrossStatuses()` asserts the old scheme really does collide, so
the regression cannot quietly return.

## E2E status — two pre-existing failures, verified not ours

`MollieStandard/CheckoutPaysAndFinalizes` and `MollieStandard/InlineMethodRedirect` fail. Rather
than assume, the baseline was measured: `git stash push -- src services.yaml`, container cache
cleared, both specs re-run → **identical failures at `HEAD`**, then the work restored.

Cause: this shop has inline-card checkout **on** (`IframeCheckoutSettings::isEnabled()` true +
`sMollieProfileId` set), so the order page renders the Mollie Components widget and the page alerts
`Components are not yet loaded`. Those two specs are written for the classic redirect flow. The
method list is healthy (`creditcard klarna paypal` for EUR/DE).

Passing, post-change: **OPC 3/4**, including `BuyNowPaysAndFinalizes` — a real Mollie payment
through to order finalization, which exercises create-payment, the new webhook-URL provider, the
redirect, the return leg and fulfilment. The fourth (`CheckoutViaOpcPaysAndFinalizes`, "all PSPs
active") is the known multi-PSP `PaymentController` chain issue.

## Tests worth knowing about

- `WebhookEventIdentityTest` (F1) — 9 cases incl. the cents discriminator for successive partial
  refunds, and that a true replay keeps its id
- `WebhookRetrySemanticsTest` (F2/D3) — the four outcomes and the age boundary
- `WebhookFailClosedTest` (F3/F5/F6) — 503 on missing guard, proxy-trust matrix, 7 implausible ids
  that must never reach the API
- `PaymentMoneyMappingRegressionTest` (F7) — distinct-value money mapping + field inventory
- `NoSilentCatchRegressionTest` (F8) — no empty/comment-only catch in `src/`, with a one-entry
  allowlist (the best-effort file logger) that must be justified in writing to grow
- `CurrencyGateTest` (F16/F17) — closed gate + no `'EUR'` literal outside `MollieDefinitions`
- `DeliveryAddressHashForgingKnownIssueTest` (F10) — pins the deferred issue, `known-issue` group

## Follow-ups

1. **F10** — the `$_POST['sDeliveryAddressMD5']` forging still neutralises OXID's address-tamper
   check on the OPC path. Needs a decision from whoever owns that integration.
2. **Real webhook rate limiting** — needs a decided shared-storage backend (no migrations allowed).
3. **The two redirect-flow e2e specs** — rewrite for the inline-card config, or add a fixture that
   turns the iframe flag off for those runs.
4. **payment-base `ContractService::calculateTotals()`** still defaults the charge currency to
   `'EUR'` upstream (out of Mollie's scope, worth raising).
5. `sMollieProfileId` + iframe flag being on by default in this shop means the classic redirect flow
   is effectively untested here.
