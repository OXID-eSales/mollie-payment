# 2026-09-17

- CI repair on `b-7.4.x` — DONE (commit `6a53ddc`). The isolated unit job runs `tests/phpunit-unit.xml` against a hand-written `MollieOrderController_parent` stub; the stub predated the AGB guard merged from `main`, so four tests hit an undefined `isConfirmAGBError()` (plus one dynamic-property deprecation). Stub now declares `_blConfirmAGBError` and mirrors core's getter. 7.4 + 7.5 + secret-scan workflows green.
  - Note for the future: only `b-7.4.x` runs the standalone suite; any new core method the Mollie controller relies on must be added to that stub.
- Sprint 01 (dashboard deep-link drops `test-mode`) — DONE. See `sprints/01-dashboard-link-drops-test-mode-segment.md` and `done/01-dashboard-link-drops-test-mode-segment.md`.
  - `MollieUrlBuilder` is mode-agnostic: one base URL, no `ModuleConfigurationServiceInterface` dependency. Two test files touched; template and DI wiring unchanged.
  - Gates: pre-commit-check.sh ALL PASSED; Unit 613/613. CHANGELOG `Unreleased → Fixed`.
- New user-level skill `/dev-log-day-mollie` scaffolds `docs/dev_day_log/YYYYMMDD/{done,reports,sprints,status.md}` (sibling of the PayPal/Stripe variants).
