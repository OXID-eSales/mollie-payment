# 2026-10-06 — GRAPH-QL Mollie provider story (P-Mollie)

**Branch:** `b-7.4.x-GRAPH-QL` (mollie-payment), on payment-base `b-7.4.x-GRAPH-QL` (Sprint 15 done; Stripe PS1–PS7 done).
**Sprint:** [sprints/GRAPH-QL-mollie-provider-story.md](sprints/GRAPH-QL-mollie-provider-story.md)
**Ritual per story:** this file updated · report in `done/` · sound played.

| Story | State | Notes |
|---|---|---|
| MS1 Headless-ready handler | **DONE** 2026-10-06 | [done/GRAPH-QL-MS1-headless-ready-handler.md](done/GRAPH-QL-MS1-headless-ready-handler.md) — Unit 725, gates green (CI-form phpcs); payment-base `providerOptions` |
| MS2 Wiring + CI pin | **DONE** 2026-10-06 | [done/GRAPH-QL-MS2-wiring.md](done/GRAPH-QL-MS2-wiring.md) — resolver tag, open-attempt finder; CI pinned to payment-base `b-7.4.x-GRAPH-QL` (TEMPORARY, alias) |
| MS3 Webhooks end the order | **DONE** 2026-10-06 | [done/GRAPH-QL-MS3-webhooks-end-the-order.md](done/GRAPH-QL-MS3-webhooks-end-the-order.md) — `authorized` commits with `requiresCapture` via payment-base; Unit 730, gates green |
| MS4 GraphQL mutations | **DONE** 2026-10-06 | [done/GRAPH-QL-MS4-graphql-mutations.md](done/GRAPH-QL-MS4-graphql-mutations.md) — Unit 740, schema proof green, live start/return/cancel on the dev shop |
| MS5 ACP service | **DONE** 2026-10-06 | [done/GRAPH-QL-MS5-acp-checkout-service.md](done/GRAPH-QL-MS5-acp-checkout-service.md) — create_checkout default, complete_checkout refused (no server-side token charge at Mollie); Unit 743 |
| MS6 Proof | IN PROGRESS | |

## How to run (dev shop)

- Unit (standalone, what CI runs): `docker compose exec -T php bash -c 'cd extensions/mollie-payment && vendor/bin/phpunit -c tests/phpunit-unit.xml'`
  — needs the module's own `vendor/` (`composer install` in the module) with `vendor/oxid-esales/payment-base` symlinked to
  `../../../payment-base` (the GraphQL branch), as Stripe's is. Baseline 2026-10-06: **714** green.
- Integration (shop PHPUnit): `docker compose exec -T php php vendor/bin/phpunit -c extensions/mollie-payment/tests/phpunit.xml --testsuite Integration`
- Gates (CI form, warnings count for phpcs!): `cd extensions/mollie-payment && composer phpcs && composer phpstan && composer phpmd`
