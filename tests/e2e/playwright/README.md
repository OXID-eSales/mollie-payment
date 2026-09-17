# Playwright E2E Tests for the Mollie Payment Module

End-to-end tests for the OXID eShop Mollie Payment Module using Playwright.

## Status: CI/manual-run-only — NOT run as part of this sprint

**This environment has no Mollie sandbox credentials.** These tests are committed as a complete,
ready-to-run skeleton (spec + config + clear instructions), per Sprint 8 Story 3, but have
**never been executed** here. Do not treat a green PHP quality gate (`./bin/pre-commit-check.sh`)
as implying these have run — they are entirely independent and gated on real Mollie sandbox
access. A human (or CI runner) with a Mollie test-mode API key must run these before relying on
them.

## What's covered

| Spec | Proves |
|---|---|
| `tests/MollieStandard/CheckoutPaysAndFinalizes.spec.ts` | Full standard-checkout happy path: select Mollie → Mollie sandbox pay → return → (webhook finalizes) → thank-you page, order not left in an error state. |
| `tests/admin/mollie-admin-panel-rebuild-after-refund.spec.ts` | After a partial refund, the Payment tab's refundable bound, refund input `max`, refunded total and transaction history are all updated **in the response of the action itself** — no reload. Needs a refundable test-mode order (`MOLLIE_E2E_ORDER_NUMBER`, default 559); refunds a run-unique amount < 1.00 each run. |
| `tests/MollieStandard/FrontendLoggingGated.spec.ts` | Zero Mollie-module `console.log` output while `sMollieLogLevel` is not `debug` (Sprint 8 Story 2 regression, mirrors the Stripe module's identical pattern). |

## Prerequisites

- Node.js 18+, npm 9+.
- A running OXID eShop instance with the Mollie module installed and activated.
- **A Mollie test-mode API key** (`test_…`) configured in
  Admin → Extensions → Modules → Mollie Payment → Settings → `sMollieTestKey`, with
  `sMollieMode=test`. Get one from <https://my.mollie.com/dashboard/> → Developers → API keys.
- The shop reachable from wherever Playwright runs, over a URL Mollie's sandbox can call back to
  for the webhook (a public URL or tunnel — `localhost` will not receive Mollie's webhook POST).

## Installation

```bash
cd tests/e2e/playwright
npm install
npx playwright install --with-deps chromium
```

## Configuration

Copy `.env.dist` to `.env` and fill in real values — **never commit `.env`** (it's gitignored):

```bash
cp .env.dist .env
```

| Variable | Description | Default |
|---|---|---|
| `SHOP_URL` / `MOLLIE_E2E_SHOP_URL` | Base URL of the OXID shop under test | `http://localhost.local` |
| `TEST_USER_EMAIL` / `TEST_USER_PASSWORD` | Storefront customer used by the checkout spec | `playwright.user@oxid-esales.dev` / `useruser` |
| `ADMIN_USER_EMAIL` / `ADMIN_USER_PASSWORD` | Shop admin backend login | — |
| `MOLLIE_TEST_API_KEY` | Documentation only — the key must be configured in the shop's admin panel, not injected by Playwright; listed here so a runner operator knows it's a prerequisite | — |
| `HEADLESS` | Run browser headless | `true` |

## Running

```bash
npm test                 # all specs
npm run test:standard    # MollieStandard/ only
npm run test:headed      # watch it run
npm run report           # open the last HTML report
```

## Deploy-steps lesson (carried over from Stripe)

If you change PHP or Twig and re-run these against a long-lived dev shop, restart PHP-FPM, clear
the OXID cache, and reinstall/rebuild frontend assets before trusting a red/green result —
opcache and Twig's compiled-template cache have both produced false negatives in the sibling
Stripe module's E2E history:

```bash
bin/oe-console oe:cache:clear
rm -rf source/tmp/*
# rebuild resources/js/* per the module's build.js if you touched frontend code
```

## Why these two specs, not more

Sprint 8's scope for E2E was explicitly the happy-path spine (Story 3) plus the logging-gating
regression already proven necessary in the sibling Stripe module. Deeper admin-panel E2E coverage
(refund/capture/cancel through a real browser) was **not** built this sprint — it would need the
"Known limitation" noted in `docs/architecture/00-overview.md` (manual capture's `AUTHORIZED`
state is currently unreachable) resolved first for the capture/cancel half of that coverage to be
meaningful.
