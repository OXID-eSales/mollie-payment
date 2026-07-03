# Dev log — 2026-06-29 (Mollie module)

## Mollie module: architecture analysis + build sprint plan

Starting point: `extensions/mollie` is a stub (one-line README + empty `docs/`). Reviewed how
`stripe`, `paypal`, and `opalreturns` build on `payment-base`, then planned the Mollie build to
Stripe/PayPal parity.

- **Report:** [reports/01-mollie-architecture-analysis.md](reports/01-mollie-architecture-analysis.md)
  — the payment-base integration contract (contract lifecycle, 6 owned tables, 3 required Oxid
  adapters, 4 DI tags, Template-Method bases, shared handlers); a 3-module comparison; and the
  key decision: **copy PayPal, not Stripe** (segregated ≤4-method adapter interfaces + lazy adapter,
  vs Stripe's baselined 26-method monolith). Documents Mollie-specific deltas: **no webhook
  signature → verify by re-fetching the payment by id**, redirect-first UX, method-dependent
  capture, and the Methods-API storefront selector. Mollie owns **no migrations**.

- **Sprint roadmap:** [sprints/00-roadmap.md](sprints/00-roadmap.md) — 8 sprints, each ≤6 stories,
  TDD-first with explicit DevOps gates:
  1. [Scaffold & DevOps](sprints/01-scaffold-and-devops.md) — installable module, quality spine, CI.
  2. [Provider adapter layer](sprints/02-provider-adapter-layer.md) — 4 segregated interfaces +
     LazyMollieAdapter + factory + DTOs + status mapper; SDK confined to `Adapter/`.
  3. [Shop glue & config](sprints/03-shop-glue-and-config.md) — Oxid Session/Shop/Order adapters +
     ModuleConfigurationService + ConfigurationValidator.
  4. [Checkout flow](sprints/04-checkout-flow.md) — contract → early order → create payment →
     redirect → idempotent return → **central user-data validation** (the payment-base
     `oepaymentvalidationapi` system Stripe uses and PayPal skipped; reused by admin in Sprint 7,
     parity-checked in Sprint 8).
  5. [Webhook pipeline](sprints/05-webhook-pipeline.md) — guard chain + fetch-by-id verify +
     idempotency + status handlers → FULFILLED.
  6. [Admin actions](sprints/06-admin-actions.md) — capture/refund/cancel services + events +
     translator + OXPAID reconciliation.
  7. [Admin panel & frontend](sprints/07-admin-panel-and-frontend.md) — shared-tab panel +
     API-sourced history + storefront method selector + JS build.
  8. [Hardening & parity](sprints/08-hardening-and-parity.md) — F-series security parity, logging
     control, E2E, docs; vaulting/Connect as stretch.

Sprints 1–5 deliver a working pay-by-redirect happy path; 6–7 add admin + storefront; 8 hardens.
