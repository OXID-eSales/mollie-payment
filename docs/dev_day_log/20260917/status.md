# 2026-09-17

- CI repair on `b-7.4.x` — DONE (commit `6a53ddc`). The isolated unit job runs `tests/phpunit-unit.xml` against a hand-written `MollieOrderController_parent` stub; the stub predated the AGB guard merged from `main`, so four tests hit an undefined `isConfirmAGBError()` (plus one dynamic-property deprecation). Stub now declares `_blConfirmAGBError` and mirrors core's getter. 7.4 + 7.5 + secret-scan workflows green.
  - Note for the future: only `b-7.4.x` runs the standalone suite; any new core method the Mollie controller relies on must be added to that stub.
- Sprint 01 (dashboard deep-link drops `test-mode`) — DONE. See `sprints/01-dashboard-link-drops-test-mode-segment.md` and `done/01-dashboard-link-drops-test-mode-segment.md`.
  - `MollieUrlBuilder` is mode-agnostic: one base URL, no `ModuleConfigurationServiceInterface` dependency. Two test files touched; template and DI wiring unchanged.
  - Gates: pre-commit-check.sh ALL PASSED; Unit 613/613. CHANGELOG `Unreleased → Fixed`.
- Sprint 02 (refundable amount bounded by the captured amount) — DONE, **committed locally, NOT pushed** (on request). See `sprints/02-partial-capture-refundable-amount.md` and `reports/01-partial-capture-refundable-amount.md`.
  - Root cause: `refundableAmount()` started from the authorized `amount`; `amountCaptured` was never mapped from the SDK. Fix in the DTO only (nullable `amountCaptured`, `settledAmount()` base); panel bound and RefundService inherit it.
  - Gates: pre-commit-check.sh ALL PASSED; Unit 624/624 (11 new). Not verified against a live partially captured payment — see report.
  - Related finding for a ticket: `capturableAmount()` reads `amountRemaining`, which Mollie documents as the *refundable* remainder.
- Sprint 03 (Payment tab shows post-action state without reload) — DONE, **committed locally, NOT pushed**. See `sprints/03-panel-rebuild-after-action.md` and `reports/02-panel-rebuild-after-action.md`.
  - Not a JS problem: the action POST already re-renders the tab. Two server-side causes: the Sprint-136 payment memo survived the action (`resetViewCache()` was a no-op) and the bound ignored *pending* refunds (Mollie's `amountRefunded` lags; `amountRemaining` doesn't). `refundableAmount()` now prefers `amountRemaining`.
  - Proof: new Playwright spec asserts on the refund POST's own response — 999.00 → 998.69 with no reload. Gates: ALL PASSED; Unit 632/632.
  - Observations for tickets: local "Refunded" figure includes a refund Mollie shows as canceled; e2e page objects don't fit this admin theme.
- Refunded figure excludes canceled/failed refunds — DONE, **committed locally, not pushed**. See `reports/03-refunded-amount-excludes-canceled-refunds.md`.
  - `RefundedAmountResolver` sums the live refund rows the table already shows (pending counts, voided doesn't); local record only as unreachable-fallback. Order 559 now reads 201.31 refunded / 798.69 refundable (sum = 1000.00 captured).
  - Gates: ALL PASSED; Unit 637/637. Ticket candidate: the contract's `OXREFUNDEDAMOUNT` audit column still carries the canceled refund.
- New user-level skill `/dev-log-day-mollie` scaffolds `docs/dev_day_log/YYYYMMDD/{done,reports,sprints,status.md}` (sibling of the PayPal/Stripe variants).
