# 2026-09-16

- Sprint 01 (AGB validation in Mollie standard checkout) — DONE. See `done/01-agb-validation-mollie-checkout.md` and `reports/01-agb-validation-fix.md`.
  - Fix: `MollieOrderController::execute()` now runs core `validateTermsAndConditions()` before creating the Mollie checkout session.
  - TDD both levels: e2e repro first (landed on mollie.com with AGB unchecked), then unit red/green.
  - Gates: pre-commit-check.sh ALL PASSED; Unit 599/599; e2e mollie-standard 6 passed (2 pre-existing failures verified on clean main).
  - Follow-ups to ticket: Mollie execute() also skips `checkSessionChallenge()` and `basketSummaryHash` validation (separate security findings).
