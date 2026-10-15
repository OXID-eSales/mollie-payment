# 2026-10-14

Branch for S02–S07: `b-7.4.x-REVIEW-01-fixes` (NOT merged — merge is the user's call).
**Open before merging:** rerun the e2e proof
(`npx playwright test --project=mollie-standard --project=mollie-opc`) — the S04 run was killed by
host memory pressure, not by a failing test.

- Sprint 01 (critical code review of the Mollie module) — **REPORT**:
  `reports/01-critical-code-review.md` (plan: `sprints/01-critical-code-review.md`). Four passes
  (SOLID/Clean-Code/DRY · dead code · test quality/TDD · security) over src/services.yaml/views/
  tests/CI; no critical/high security findings; ranked follow-up list → Sprints 02–07.
- Sprint 02 (fix the red Unit run, C-H1) — **DONE**: `done/02-fix-red-unit-run.md`. GraphQL test
  built graphql-base's User with a stub-only string ctor → 7 errors under the shop vendor; test now
  mocks the shop model, both bootstrap stubs mirror the real signature. Bonus: pre-commit-check.sh
  itself had drifted (bootstrap override → trait fatal; --level=max → 6 phantom phpstan errors).
  Unit 756 green in BOTH configs for the first time; gates ALL PASSED.
- Sprint 03 (webhook rate limiter, D-M1/D-M2) — **DONE**: `done/03-webhook-rate-limiter.md`.
  Guard reworked onto payment-base's shared Doctrine RateLimitStore (no new table), wired into the
  chain at 120/min per source IP, fail-open on storage failure; f-matrix F17 corrected. Live
  container proof: request 121 from one IP → 429. Unit 761.
- Sprint 04 (one checkout entry ladder, A-H1/A-H2) — **DONE**: `done/04-unify-checkout-entry.md`.
  `CheckoutSessionStarter` owns buyability → MOL-15 gate → MOL-18 replay → dispatch; **MOL-15 and
  MOL-18 now guard the OPC path for the first time**; basket-hash mirror pinned against core's real
  private method (Integration parity test). Unit 772, Integration 41.
- Sprint 05 (dead-code purge, B1–B9 + stale e2e) — **DONE**: `done/05-dead-code-purge.md`.
  ~800 lines deleted after grep re-verification incl. OxidShopOrderService, the
  ConfigurationValidator trio, the dead `mollie-checkout` Stimulus controller (bundles rebuilt),
  dead constants/methods/translations, 3 assertion-free root Playwright specs. Unit 765,
  Integration 39.
- Sprint 06 (test the untested, C-M1) — **DONE**: `done/06-test-the-untested.md`.
  OxidStockRestorationService: 5 unit tests (storno/stock branch logic via new loadOrder seam) +
  real-DB integration (stock bump, OXSTORNO, retry idempotency, money columns untouched; EE needs
  the oxarticles2shop mapping row). PaymentMethodInstaller: creation path on a throwaway id +
  idempotency on the live row. Unit 770, Integration 42.
- Sprint 07 (hygiene batch) — **DONE**: `done/07-hygiene-batch.md`. ViewConfig catches log (A-M5);
  `MoneyComparison` + `ProviderOrderId` dedupe (A-M3); `FulfillmentResult` carries the contract id —
  no per-delivery re-query, repository dropped from the ladder handlers' ctor (A-M2); guard request
  built once (A-L2); orphaned docblock re-attached (A-L3); gitleaks regex `{25,}` (D-L3). Deferred
  with rationale: D-L2 token expiry (own sprint — return-URL compat), A-M1/A-L1/A-M4/A-M6/A-M7.
  Final: Unit **773** green both configs, Integration **42** green, gates ALL PASSED.
