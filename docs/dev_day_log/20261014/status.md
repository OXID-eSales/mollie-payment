# 2026-10-14

- Sprint 01 (critical code review of the Mollie module) — **REPORT written, nothing implemented**:
  `reports/01-critical-code-review.md` (plan: `sprints/01-critical-code-review.md`).
  - Four review passes over `src/` (156 files, ~12.5k LOC), `services.yaml`, views, tests, CI:
    SOLID/Clean-Code/DRY · dead code · test quality/TDD · security. Verdict: unusually disciplined
    codebase, **no critical/high security findings** — webhook re-fetch verification, admin CSRF,
    server-side amount ceilings and redirect validation all verified as real.
  - Must-act items: canonical Unit run is **RED** (7 errors, `GraphQL/MollieCheckoutTest` —
    graphql-base vendored-vs-installed drift); checkout entry flow duplicated controller vs OPC
    handler with MOL-15/MOL-18 guards on only one path; webhook endpoint has **no effective rate
    limiter** (forged `tr_…` ids each buy an authenticated Mollie API call) while f-matrix F17
    still claims ✅; ~640 lines certainly-dead PHP + dead 73-line Stimulus controller shipped in
    the prod bundle + 3 stale assertion-free root-level e2e specs; `OxidStockRestorationService`
    and `PaymentMethodInstaller` entirely untested.
  - Ranked follow-up sprint list at the end of the report (fix red suite → rate limiter + F17 →
    unify checkout entry → dead-code purge → test the untested → hygiene batch).
