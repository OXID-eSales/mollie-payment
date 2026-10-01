# Sprint MOL-10 (follow-up): the contract-state Help becomes payment-base's; Mollie keeps its column

**Date:** 2026-10-01 · **Branch:** `b-7.4.x-MOL-10-contract-state-help` in payment-base, mollie-payment and stripe
**Status:** IMPLEMENTED, pushed; merge order: payment-base → mollie-payment / stripe (both CIs pin payment-base `b-7.4.x`).

## Ask

Move the Help section to payment-base (same approach, two columns: OXID Contract Status · Meaning). Mollie and Stripe
keep a Help section on their Settings tabs with a third, provider-specific column. Next to "OXID Contract Status" on
the order Payment tab a "?" opens a popup layer with the provider's description and the three-column table.

## What each repo does

| Repo | Owns |
|---|---|
| payment-base | `Admin\Help\ContractStateHelp` (+ row), Twig function `oe_payment_contract_state_help()`, table partial (optional provider column), "?" hint partial (popup, Escape / outside click / close button), its own Settings "Help" group (2 columns), translations `PAYMENT_ADMIN_HELP*`, label "OXID Contract Status" |
| mollie-payment | `Admin\MollieContractStateHelp` (state → Mollie status, column header, intro) via `ViewConfig::getMollieContractStateHelp()`; Settings "Help" group includes the shared table with Mollie's column; panel includes the shared hint; the local rows class, meanings and table CSS are gone |
| stripe | `Admin\StripeContractStateHelp` (state → PaymentIntent status) via `ViewConfig::getStripeContractStateHelp()`; Settings "Help" group; a new "OXID Contract Status" row on the panel (the builder now exposes `contractState`) with the shared hint |

## Proof

| Proof | Result |
|---|---|
| payment-base unit (10 new) + suite | red → **green**, 1392 |
| mollie-payment unit (`MollieContractStateHelpTest`, `ModuleConfigHelpSectionTest`, `MolliePanelHelpHintTest`, ViewConfig) + suite | **green**, 710 |
| stripe unit (`StripeContractStateHelpTest`, `StripeAdminHelpTemplatesGuardTest`, builder `contractState` ×2, ViewConfig) + standalone suite | red → **green**, 1587 |
| e2e `MollieAdmin/ConfigHelpSection` (Mollie Settings 3 columns; panel "?" layer, Escape) | **green** (2) |
| e2e `MollieAdmin/SharedContractStateHelp` (payment-base Settings 2 columns; Stripe Settings 3 columns; Stripe panel "?" layer) | **green** (3) |
| Gates | payment-base all green; Mollie phpcs/PHPMD green, PHPStan the 3 environmental findings; Stripe phpcs/PHPStan green, PHPMD one pre-existing `TooManyMethods` on an untouched controller |

## Notes

- Running Stripe's Unit suite through the shop bootstrap fails while *loading* the suite on Mollie's controller chain
  (cross-module class map); the standalone `tests/phpunit-unit.xml` run (CI's isolated job) is the reference.
- payment-base needed `twig/twig` in require-dev for its standalone unit run.
