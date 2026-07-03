# Mollie module — sprint roadmap

**Date:** 2026-06-29
**Goal:** Build the `mollie` payment module to Stripe/PayPal parity on top of `payment-base`,
following the **PayPal reference architecture** (segregated adapter interfaces, lazy adapter,
redirect-first UX, shared admin tab, no own migrations).
**Background report:** `../reports/01-mollie-architecture-analysis.md`

## Why this sequence

The dependency order is: you cannot test a checkout handler without an adapter; you cannot
verify a webhook without a checkout that created a contract; admin capture/refund needs a
fulfilled contract to act on. So foundations first (scaffold -> adapter -> shop glue), then the
happy-path money flow (checkout -> webhook), then admin actions, then frontend polish, then
hardening. Each sprint ships independently and leaves the module installable & green.

## Non-negotiable rules (apply to every story in every sprint)

TDD-first (failing test precedes impl) · DevOps gate green before commit (`phpcs` / `phpstan`
level max / `phpmd` / `phpunit` Unit / `./bin/pre-commit-check.sh`) · SOLID · DRY (reuse before
new; don't pre-DRY similar-but-not-same) · Liskov/ISP (no interface > 5 methods without
justification) · Clean Code (no-else, early returns, 15-25 line methods, explicit imports) ·
**no overengineering** (no abstraction without a present caller) · no own DB migrations ·
PSP SDK imports confined to `src/Mollie/Adapter/` (regression-guard it like PayPal) · **reuse the
payment-base central user-input validation system** (shared `oepaymentvalidationapi` endpoint +
`validation-rules.php`) that Stripe uses and PayPal skipped — no bespoke validator or second endpoint.

## Sprint list

| # | Sprint | Goal (one line) | Depends on | Est |
|---|---|---|---|---|
| 1 | Scaffold & DevOps | Installable empty module: composer, metadata, services.yaml, quality gates, CI | — | M-L |
| 2 | Provider adapter layer | 4 segregated Mollie interfaces + LazyMollieAdapter + factory + DTOs + status mapper, SDK confined | 1 | L |
| 3 | Shop glue & config | Oxid Session/Shop/ShopOrderService adapters + ModuleConfigurationService + ConfigurationValidator | 1 | M |
| 4 | Checkout flow | Contract creation -> early order -> Mollie payment create -> redirect -> return + **central user-data validation** | 2,3 | L |
| 5 | Webhook pipeline | WebhookController + guard chain + fetch-by-id verify + idempotency + status handlers -> fulfill | 2,4 | L |
| 6 | Admin actions | Capture/Refund/CancelAuthorization services + events + translator + OXPAID reconciliation | 4,5 | L |
| 7 | Admin panel & frontend | MolliePaymentPanelProvider (shared tab) + transaction history + storefront method selector + JS build | 5,6 | L |
| 8 | Hardening & parity | Security parity sweep, E2E happy-path, logging control, docs, vaulting/Connect (stretch) | 7 | L |

> 8 sprints, each <= 6 stories. Sprints 1-5 deliver a working pay-by-redirect happy path;
> 6-7 deliver admin + storefront; 8 is hardening/parity. Stretch items (vaulting, Mollie
> Connect/OAuth, Klarna two-step capture, 3DS-on-card via components) are explicitly deferred
> and listed under each sprint's "Out of scope".

## Detailed sprint files

- `01-scaffold-and-devops.md`
- `02-provider-adapter-layer.md`
- `03-shop-glue-and-config.md`
- `04-checkout-flow.md`
- `05-webhook-pipeline.md`
- `06-admin-actions.md`
- `07-admin-panel-and-frontend.md`
- `08-hardening-and-parity.md`

(Sprints 1-5 are fully storied below in their files. 6-8 are storied at the same depth; revisit
6-8 scope after Sprint 5 lands, since Mollie API behaviours observed live — capture
availability per method, webhook timing — may reshape them.)

## Definition of Done (epic-level)

A merchant can install & activate `oe_payments_mollie`, configure a test API key, select a
Mollie method in checkout, be redirected to Mollie, pay, return, and see the order finalized by
webhook; admin can view transaction history and issue full/partial refunds (and capture where
the method supports two-step); all gates green; ~60+ unit tests; security parity with PayPal's
F-series; zero own migrations.
