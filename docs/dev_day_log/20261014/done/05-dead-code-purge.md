# Done 05 — Dead-code purge (review B1–B9 + C-H2/C-H3) — 2026-10-14

Branch: `b-7.4.x-REVIEW-01-fixes`. Every deletion was re-verified by grep before it happened
(notably: `views/.../mollie-footer.html.twig`'s `mollie-checkout-footer` is a different Stimulus
identifier than the deleted `mollie-checkout`, and the remaining `OxidShopOrderService` mentions
are docblocks about *payment-base's* class of the same name).

## Deleted

- **B1** `src/Mollie/Adapter/OxidShopOrderService.php` (256 L, registration kept it compiled but
  payment-base owns finalization since 2026-09-01) + yaml registration +
  `tests/Integration/Adapter/OxidShopOrderServiceTest.php`.
- **B2** `ConfigurationValidator` + interface + `ConfigurationValidationResult` (~124 L, aliased
  but never injected) + `ConfigurationValidatorTest` + the validator leak-test in
  `CredentialConfidentialityTest` (a class with no production caller cannot leak); f-matrix F19
  row updated accordingly.
- **B3** `/debug-product-checkout.mjs` (git-tracked debug script importing a playwright that is
  not even installed).
- **B4** `resources/js/controllers/mollie_checkout_controller.js` (73 L dead Stimulus controller
  shipping in the production bundle; its documented host templates were deleted long ago) —
  dropped from `app.js`, **both bundles rebuilt** (min 51.7 KB; grep confirms no
  `register("mollie-checkout"` remains; the live footer controller is inline in its twig, not in
  the bundle).
- **B5** empty `views/twig/frontend/` dir.
- **B6** `MollieDefinitions::EVENT_*` — five unreferenced event-name constants.
- **B7** `MollieDefinitions::supportsCountry()/getSupportedCountries()` + the feeding
  `'countries' => []` map entry + the test-only caller (the helper also still carried the
  "empty list = allow all" failure mode F16 removed from `supportsCurrency` — dead AND
  inconsistent).
- **B8** `OxidShopAdapter::getActiveLanguageId()` (not in the interface, zero callers).
- **B9** dead translations: `MOLLIE_VALIDATION_UNDERSTAND` (en+de) and the whole
  `views/twig/{de,en}/mollie_lang.php` theme files (one key dead, the refund-description pair
  duplicated admin keys never loaded in admin context).
- **C-H2/C-H3** stale, assertion-free root Playwright specs no project runs:
  `debug-admin-order.spec.ts`, `mollie-checkout.spec.ts`, `admin-access.spec.ts`, plus the
  untracked `diag-card3-tmp.mjs` (accidentally committed in S04, deleted here).

Left alone deliberately: `MOLLIE_VALIDATION_INVALID_USER_DATA` (kept alive by the lang-parity
test — flagged "likely dead", needs a decision, not a sweep) and the six payment-base
interface-mandated adapter methods (upstream ISP issue, can't be deleted module-side).

## Proof

- ~800 lines gone (PHP + JS + specs). Unit **765** green in BOTH configs (772 − 7 dead tests);
  Integration **39** green (41 − 2 with the deleted test file); container compiles;
  `pre-commit-check.sh` ALL CHECKS PASSED; bundles rebuilt and verified.
