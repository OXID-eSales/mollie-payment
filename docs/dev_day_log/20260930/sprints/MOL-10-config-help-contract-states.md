# Sprint MOL-10: "Help" section in the module settings (contract state ↔ Mollie status) + panel label

**Date:** 2026-09-30 · **Ticket:** MOL-10 · **Branch:** `b-7.4.x-MOL-10-config-help-contract-states` (mollie-payment only)
**Status:** IN PROGRESS — TDD; merge on the product owner's word.
**Definition of Done:** the Mollie module Settings tab shows, after "Logging", a collapsible "Help" group (EN/DE by
admin language) with a table — OXID Contract Status · Meaning · Mollie payment status — for every contract state a
Mollie checkout can reach (the first three columns of the MOL-10 report table); the admin order Payment tab labels
the contract state "OXID Contract Status" instead of "State". Proven by unit tests (rows cover payment-base's
`ContractState`, translations exist in both languages, template hooks in the right place) and an admin e2e spec.

## Design

| Piece | One job |
|---|---|
| `Admin\ContractStateHelpRow` | value: contract states shown in the cell (e.g. `committed`, `fulfilled`), Mollie status code (`''` = none), meaning translation ident |
| `Admin\ContractStateHelp::rows()` | the eight rows in ladder order, built from payment-base's `ContractState` factories so a renamed state fails a test, not a reader |
| `Core\ViewConfig::getMollieContractStateHelp()` | hands the rows to the admin template (admin templates have `oViewConf`, no Mollie view) |
| `module_config.html.twig` override | `admin_module_config_group`: `{{ parent() }}`, then — Mollie module and `loop.last` only — the "Help" group in the stock collapsible markup with the table |
| admin translations EN/DE | `MOLLIE_HELP*` idents (title, intro, three column headers, eight meanings, "none"); `MOLLIE_CONTRACT_STATE` → "OXID Contract Status" / "OXID-Vertragsstatus" |

Stripe / PayPal columns of the report table are deliberately left out (the user's "first 3 columns"); state and Mollie
status codes stay untranslated (they are what the merchant sees in the panel and at Mollie).

## Stories

1. Red: `ContractStateHelpTest` (every non-draft `ContractState` once, ladder order, Mollie status per row,
   idents in EN+DE), `ModuleConfigHelpSectionTest` (template hooks the last group, gated on Mollie, uses the
   ViewConfig rows and the idents, delegates to parent), `MollieViewConfigHelpTest`, label test; e2e
   `MollieAdmin/ConfigHelpSection` (Help group after Logging, table content; panel label on a Mollie order).
2. Green: the classes, ViewConfig method, template, translations.
3. Gates, CHANGELOG, done/report/status, push, CI.
