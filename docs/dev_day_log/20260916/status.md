# 2026-09-16

- Sprint 01 (AGB validation in Mollie standard checkout) — DONE. See `done/01-agb-validation-mollie-checkout.md` and `reports/01-agb-validation-fix.md`.
  - Fix: `MollieOrderController::execute()` now runs core `validateTermsAndConditions()` before creating the Mollie checkout session.
  - TDD both levels: e2e repro first (landed on mollie.com with AGB unchecked), then unit red/green.
  - Gates: pre-commit-check.sh ALL PASSED; Unit 599/599; e2e mollie-standard 6 passed (2 pre-existing failures verified on clean main).
  - Follow-ups to ticket: Mollie execute() also skips `checkSessionChallenge()` and `basketSummaryHash` validation (separate security findings).
- Sprint 02 (session-challenge / CSRF guard) — DONE. See `done/02-session-challenge-mollie-checkout.md` and `reports/02-session-challenge-and-basket-hash-fix.md`.
  - PendingReturnProbe extracted to a service (Story 1); silent-null CSRF guard mirrors core; e2e repro blanks `stoken` (NOT the `challenge` field — that's the sess_challenge order id).
- Sprint 03 (basketSummaryHash guard) — DONE. See `done/03-basket-summary-hash-mollie-checkout.md` and the same report.
  - Core's private helpers mirrored byte-for-byte; missing hash warns + proceeds (core parity); mismatch → BASKET_ITEMS_CHANGED_ERROR + order/basket.
  - PHPMD headroom came from deleting impossible-input instanceof re-checks after resolveService() went generic — pure-validator fallback not needed.
- CI fix (DoD: green GitHub Actions): workflows' payment-base checkout token falls back to `github.token` (repo has no PAT secrets; payment-base is public); COMPOSER_AUTH only sent when a PAT exists.
- Spin-offs to ticket: upstream CE (make basket-hash helpers protected); Stripe module lacks basketSummaryHash validation.
