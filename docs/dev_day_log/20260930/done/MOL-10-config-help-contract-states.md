# Done — MOL-10: "Help" group in the module settings + "OXID Contract Status" label

**Branch:** `b-7.4.x-MOL-10-config-help-contract-states` (mollie-payment). Plan: `../sprints/MOL-10-config-help-contract-states.md`.

## Story 1 — red proofs (TDD)
- `tests/Unit/Admin/ContractStateHelpTest` (6): rows are the report table in ladder order; every `ContractState`
  except `draft` appears exactly once (built from payment-base's factories); every ident the table uses exists
  in EN and DE; the panel label reads "OXID Contract Status" / "OXID-Vertragsstatus" and equals the table's
  first column header. Red (class missing, label "State") → green.
- `tests/Unit/Admin/ModuleConfigHelpSectionTest` (2, template source): the override hooks
  `admin_module_config_group` with `{{ parent() }}`, `loop.last` and the Mollie gate, uses the stock collapsible
  markup, takes rows from `oViewConf.getMollieContractStateHelp()` and translates every ident. Red → green.
- `tests/Unit/Core/MollieViewConfigContractStateHelpTest` (1). Red → green.
- e2e `MollieAdmin/ConfigHelpSection` (2, new Playwright project `mollie-admin`): the "Help" group follows
  "Logging" and is last; expanded, its table names every state and the Mollie statuses, no raw key; the order
  Payment tab (direct `PaymentAdmin` URL with `force_admin_sid` + `stoken`) shows exactly one "OXID Contract
  Status" row and no "State" row. Green after implementation (not run red — the unit tests were).

## Story 2 — implementation
- `src/Mollie/Admin/ContractStateHelpRow.php`, `src/Mollie/Admin/ContractStateHelp.php` (rows + `SHARED_IDENTS`).
- `Core\ViewConfig::getMollieContractStateHelp()` (admin templates see `oViewConf`, no Mollie view).
- `views/twig/extensions/themes/admin_twig/module_config.html.twig`: `admin_module_config_group` override — after the
  last group, Mollie module only, the same `div.groupExp` / `_groupExp` markup with intro + 3-column table; CSS in
  the existing style block.
- `views/admin_twig/{en,de}/mollie_lang.php`: `MOLLIE_HELP*` (title, intro, three headers, eight meanings, "none");
  `MOLLIE_CONTRACT_STATE` → "OXID Contract Status" / "OXID-Vertragsstatus".

## Story 3 — gates, docs
- phpcs (CI form, warnings counted) clean after wrapping one 126-char line; PHPMD clean; Unit 711 green; PHPStan:
  the 3 environmental findings in untouched files. CHANGELOG under Added / Changed.
- Learned: the OXID admin session rides in the URL (`force_admin_sid`, `stoken`); a direct `page.goto` to an admin
  controller without them lands on the login form (`AdminRefundFlow`'s direct URL has the same gap).
