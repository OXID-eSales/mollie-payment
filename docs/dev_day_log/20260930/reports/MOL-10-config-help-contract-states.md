# Report — MOL-10: "Help" group in the module settings + "OXID Contract Status" label

**Date:** 2026-09-30 · **Branch:** `b-7.4.x-MOL-10-config-help-contract-states` (mollie-payment) · Plan:
`../sprints/MOL-10-config-help-contract-states.md` · Done: `../done/MOL-10-config-help-contract-states.md`

## What the merchant gets

- Module Settings tab: General · Test credentials · Live credentials · Webhooks · Logging · **Help**. The Help group
  collapses like the others and holds one sentence plus a table:

| OXID Contract Status | Meaning (EN) | Mollie payment status |
|---|---|---|
| `not_finished` | The order row exists and the customer was redirected to Mollie; nothing has been committed yet. | `open` |
| `pending` | The customer has committed; the payment network has not confirmed yet. | `pending` |
| `authorized` | Funds are reserved; the merchant still has to capture them. | `authorized` |
| `ready_to_commit` | The money has been taken; the order is not committed in the shop yet. | `paid` |
| `committed`, `fulfilled` | Shop-internal only: the order is committed (paid) and finally fulfilled. | none (shop-internal) |
| `cancelled` | Someone stopped the payment (customer, merchant or Mollie). | `canceled` |
| `expired` | The payment window ran out before the customer completed the payment. | `expired` |
| `failed` | The payment attempt was rejected. | `failed` |

  German by admin language (`Hilfe`, `OXID-Vertragsstatus`, `Bedeutung`, `Mollie-Zahlungsstatus`, …). The Stripe /
  PayPal columns of the MOL-10 report table are left out on purpose (the first three columns were asked for).
- Order → Payment tab: the row formerly labelled "State" / "Status" reads **OXID Contract Status** /
  **OXID-Vertragsstatus** — the same words as the Help table's first column.

## How

- One PHP table (`Admin\ContractStateHelp`) built from payment-base's `ContractState` factories; the ViewConfig
  extension hands it to the admin template; the template override adds the group after the last stock group
  (`loop.last`) for the Mollie module only and delegates every stock group to `{{ parent() }}` — the same
  multi-module-safe pattern the key-masking override uses.

## Proof

| Proof | Result |
|---|---|
| Unit: `ContractStateHelpTest` (6), `ModuleConfigHelpSectionTest` (2), `MollieViewConfigContractStateHelpTest` (1) | red → **green**; Unit suite **711** |
| e2e `MollieAdmin/ConfigHelpSection` (2) | **green** — Help after Logging and last; table complete, translated; panel label renamed, no "State" row |
| Gates | phpcs (CI form, warnings) clean · PHPMD clean · PHPStan: 3 environmental findings, none new |

## Notes

- The admin e2e needed the session on the URL (`force_admin_sid`, `stoken`) for a direct controller navigation;
  `AdminRefundFlow` navigates the same way without them and would land on the login form today (not in scope).
- New Playwright project `mollie-admin` runs `tests/MollieAdmin/*`.
