# Mollie Payment for OXID eShop 7

A Mollie payment module for OXID eShop 7.4+, built on the shared **`payment-base`**
Smart-Contract payment core. Mollie follows the **PayPal reference architecture**: segregated
adapter interfaces behind one lazy SDK adapter, redirect-first checkout, webhook verification by
re-fetching the payment from the Mollie API (Mollie sends no signature), and a shared admin
"Payment" tab owned by `payment-base`. The module owns **no database migrations** — all tables
come from `payment-base`.

> Status: under active construction (see `docs/dev_day_log/`). The scaffold, DevOps spine, and
> adapter/checkout/webhook layers are built sprint-by-sprint.

## Requirements

- OXID eShop CE 7.4+ (`dev-b-7.4.x`), PHP 8.2+
- `oxid-esales/payment-base`
- `mollie/mollie-api-php ^2`

## Install & activate

Registered as a Composer path repository in the shop and required like the sibling modules:

```bash
# from the shop root (inside the php container)
composer update oxid-esales/mollie-payment --with-all-dependencies
bin/oe-console oe:module:activate oe_payments_mollie
bin/oe-console oe:module:deactivate oe_payments_mollie
```

Configure the module in the admin under *Extensions → Modules → Mollie Payment*: choose the mode
(test/live), paste the matching API key (`test_…` / `live_…`), set the webhook URL, and pick the
log level.

## Quality gates

From the module root (run inside the `php` container, tools resolved from the shop vendor):

```bash
composer phpcs      # PSR-12
composer phpstan    # PHPStan level max — no baseline entries
composer phpmd      # PHP Mess Detector
composer phpunit    # PHPUnit (tests/phpunit.xml)
composer style      # phpcs + phpstan + phpmd

# aggregate gate (phpcs → phpunit → phpstan → phpmd)
./bin/pre-commit-check.sh              # Unit tests only
./bin/pre-commit-check.sh --full       # Unit + Integration (needs MySQL)
./bin/pre-commit-check.sh --no-phpunit # style only
```

Run a single test:

```bash
docker compose exec -w /var/www/extensions/mollie-payment php \
  /var/www/vendor/bin/phpunit -c tests/phpunit.xml --filter testName tests/Unit/Path/To/Test.php
```

## Architecture & docs

- Build plan & daily logs: `docs/dev_day_log/`
- Architecture analysis: `docs/dev_day_log/20260629/reports/01-mollie-architecture-analysis.md`
- Contributor guidance for AI agents: `CLAUDE.md`

All Mollie SDK imports are confined to `src/Mollie/Adapter/`; the contract is never mutated via a
generic `setState()` — only through the named transitions on the `payment-base` contract. Both
rules are enforced by regression tests.
