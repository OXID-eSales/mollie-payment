# 2026-09-30

- Sprint MOL-10 ("Help" group after "Logging" in the module settings — OXID contract state · meaning · Mollie payment
  status, EN/DE — and the order Payment tab label "OXID Contract Status") — **IMPLEMENTED**, branch
  `b-7.4.x-MOL-10-config-help-contract-states`, pushed; CI pending, merge on the product owner's word. Plan
  `sprints/MOL-10-config-help-contract-states.md`; `done/…`; `reports/…`.
  - `Admin\ContractStateHelp` rows (built from payment-base `ContractState`) → `ViewConfig::getMollieContractStateHelp()`
    → `module_config.html.twig` group override (`loop.last`, Mollie only, stock collapsible markup); admin translations
    EN/DE; `MOLLIE_CONTRACT_STATE` → "OXID Contract Status" / "OXID-Vertragsstatus".
  - Proof: unit red → green (Unit 711); e2e `MollieAdmin/ConfigHelpSection` green (new `mollie-admin` project).
