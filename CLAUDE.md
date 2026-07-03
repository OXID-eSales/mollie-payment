# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

The **Mollie payment module** (`oe_payments_mollie`) for OXID eShop 7.4+ — a full payment
provider built on top of the `payment-base` module. It lives at
`source/extensions/mollie-payment` inside the larger OXID SDK repo (see the root
`../../../CLAUDE.md` for Docker/shop-level commands).

**Current status: greenfield.** There is no `src/` yet — only `README.md` and planning docs
under `docs/dev_day_log/`. The whole module is being built from a detailed architecture analysis
and an 8-sprint plan. **Read these before writing any code:**

- `docs/dev_day_log/20260629/reports/01-mollie-architecture-analysis.md` — the `payment-base`
  integration contract, the 3-module comparison (Stripe/PayPal/OpalReturns), the key decisions,
  and the full file inventory Mollie must build (~45-55 files).
- `docs/dev_day_log/20260629/sprints/00-roadmap.md` — the 8-sprint sequence and epic Definition
  of Done. Individual sprint files (`01`…`08`) story out the work TDD-first.

## The single biggest architectural rule: copy PayPal, not Stripe

Mollie's redirect-based flow maps almost 1:1 onto PayPal's. **Use `../paypal/` as the reference
implementation**, not `../stripe/`. Concretely:

- **Segregated adapter interfaces**, not one fat interface. PayPal splits the SDK behind four
  ≤4-method interfaces; Stripe has one 26-method `StripeAdapterInterface` (PHPMD-baselined as
  `TooManyMethods` — do *not* replicate that). Mollie's four:
  `MolliePaymentsAdapterInterface`, `MollieCaptureAdapterInterface`,
  `MollieRefundAdapterInterface`, `MollieWebhookAdapterInterface` — all aliased to **one**
  `LazyMollieAdapter` instance (one SDK client per request), exactly like PayPal's
  `LazyPayPalAdapter`.
- The three `Oxid*Adapter` implementations (`OxidSessionAdapter`, `OxidShopAdapter`,
  `OxidShopOrderService`) are near-identical across providers — **start by copying PayPal's** and
  adjust only the PSP touch-points.

## How a provider plugs into payment-base (inherited for free)

`payment-base` is a provider-agnostic Smart-Contract payment core; the provider supplies ~5% of
the code. Do **not** rebuild anything below — it comes from `../payment-base/`:

- **Contract lifecycle** `DRAFT → NOT_FINISHED → PENDING → AUTHORIZED → READY_TO_COMMIT →
  COMMITTED → FULFILLED` (endings: `CANCELLED`/`EXPIRED`/`FAILED`). Transitions happen **only**
  through named methods (`transitionToPending()`, `authorize()`, `captureAuthorization()`,
  `fulfill()`, `cancel($reason)`, …). **There is no `setState()`** — guard this with a regression
  test like PayPal's `NoSetStateOnContractRegressionTest`.
- **The 6 DB tables** (`oe_payments_contract`, `_transaction`, `_customer`, `_idempotency`,
  `_sessions`, `_webhooklogs`). **Mollie owns NO migrations.** PSP-specific data goes into
  `oe_payments_contract.OXMETADATA` (JSON) via `setMetadata()/getMetadata()`; the PSP id links
  through `setProvider($name, $providerOrderId, $redirectUrl)`.
- **Shared orchestrator handlers** (`EarlyOrderCreationHandler`, `ContractCommitmentHandler`,
  `TransactionRecordingHandler`, `OrderPaymentCompletedHandler`, …) — Mollie writes only the
  PSP-touching handlers.
- **Template-Method bases** to extend: `AbstractPaymentCaptureService` /
  `AbstractPaymentRefundService`, `AbstractFileLoggerFactory`, `AbstractWebhookProcessor`,
  and the abstract `ContractCreationHandler`.
- **DI tags** that wire a provider in: `payment.event_handler` (event handlers),
  `oe.payment.event_translator` (abstract `*RequestedEvent` → concrete event),
  `oe.payment.admin_panel` (renders into the shared admin "Payment" tab). Interfaces to satisfy:
  `SessionAdapterInterface`, `ShopAdapterInterface`, `ShopOrderServiceInterface`, the
  `*HandlerInterface` family, and `ReturnResolverInterface`.

## Mollie-specific facts that change the flow

1. **No webhook signature.** Mollie POSTs only `id=tr_xxxx`. Verification = **re-fetch that
   payment from the Mollie API and trust the API's status** — so `parseAndValidateRequest()` is an
   API round-trip, not an HMAC check. The guard chain (HTTPS/size/rate-limit) still applies; the
   IP-allowlist guard is weak (Mollie publishes no fixed ranges) — gate behind config, default off.
2. **Redirect-first UX.** Create Payment → `_links.checkout` → 302 to Mollie → return to
   `redirectUrl`. The **webhook, not the redirect, is the source of truth.** JS card components
   (`@mollie/components`) are a later enhancement.
3. **Capture is method-dependent.** Most methods auto-capture; two-step authorize+capture exists
   only for cards/Klarna. Gate the admin Capture form on capture-mode + method capability.
4. **Storefront method selector** (iDEAL, Bancontact, cards, Klarna, SEPA…) is sourced from the
   Mollie Methods API — a Mollie concern with no Stripe equivalent (Stripe Checkout hosts its own).
5. **Auth = API key** (`test_`/`live_`); OAuth is only for Mollie Connect (deferred stretch).

## Central user-input validation — adopt it (like Stripe, unlike PayPal)

`payment-base` provides a shared anti-injection validation subsystem: the frontend endpoint
`index.php?cl=oepaymentvalidationapi&fnc=validate`, a 7-guard chain, and a character-class engine.
Stripe adopted it; PayPal skipped it; **Mollie must adopt it** (Sprint 4 Story 6). The provider
supplies only `src/Resources/validation-rules.php` + a `ValidationRulesProvider` + a tagged
`UserDataValidationMessageFormatter` + bindings in `services.yaml`. Do **not** roll a bespoke
validator or a second endpoint.

## PSP SDK confinement

All `mollie/mollie-api-php` imports are confined to `src/Mollie/Adapter/`. Guard this with a
regression test like PayPal's SDK-confinement check. The dependency is declared once, centrally,
in `composer.json`.

## Commands

The DevOps spine (composer scripts + `bin/pre-commit-check.sh`) is created in **Sprint 1** by
copying from `../stripe/` and `../paypal/` and repathing to Mollie. Once scaffolded, from this
directory:

```bash
composer phpcs                     # PSR-12 (php_codesniffer)
composer phpstan                   # PHPStan level max — no new baseline entries
composer phpmd                     # PHP Mess Detector
composer static                    # phpcs + phpstan + phpmd
composer phpunit                   # PHPUnit (tests/phpunit.xml)
./bin/pre-commit-check.sh          # all gates + unit tests; run before staging
./bin/pre-commit-check.sh --full   # full suite incl. integration
./bin/pre-commit-check.sh --no-phpunit
```

PHPUnit suites are `Unit` and `Integration` (`tests/phpunit.xml`). Run a single file/method:

```bash
vendor/bin/phpunit -c tests/phpunit.xml tests/Unit/Path/To/Test.php
vendor/bin/phpunit -c tests/phpunit.xml --filter testMethodName tests/Unit/Path/To/Test.php
```

Module lifecycle (inside the PHP container, from the shop root — see root `CLAUDE.md`):

```bash
bin/oe-console oe:module:install extensions/mollie-payment
bin/oe-console oe:module:activate oe_payments_mollie
bin/oe-console oe:module:deactivate oe_payments_mollie
```

## Non-negotiable rules (every story, every sprint)

- **TDD-first** — a failing test precedes the implementation.
- **DevOps gate green before commit** — `phpcs` / `phpstan` (level max) / `phpmd` / `phpunit` Unit
  / `./bin/pre-commit-check.sh`.
- **SOLID + ISP** — no interface >5 methods without justification (the whole reason to copy PayPal).
- **DRY** — reuse before new; but don't pre-DRY similar-but-not-same code.
- **Clean Code** — no-else / early returns, 15-25-line methods, explicit imports, DI-only (no
  `Registry::getConfig()` reach-ins in services).
- **No overengineering** — no abstraction without a present caller; one impl before an interface,
  two before a base class.
- **No own DB migrations** — all tables come from `payment-base`.
- **PSP SDK imports confined to `src/Mollie/Adapter/`.**
- Commit messages end with the `Co-Authored-By` trailer.

## Dev logs

Work is logged per day under `docs/dev_day_log/YYYYMMDD/` (`status.md`, `reports/`, `sprints/`,
`done/`). Scaffold a new day with the `/dev-log-day-stripe` skill (adapt for this module) or by
mirroring the existing `20260629/` layout.
