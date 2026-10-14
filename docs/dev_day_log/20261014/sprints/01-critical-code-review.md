# Sprint 01 — Critical code review of the Mollie module (2026-10-14)

**Goal:** a critical, evidence-based review of the Mollie module's own code (`src/`, `services.yaml`,
`metadata.php`, `views/`, `assets/`, `resources/`, `tests/`) — no code changes, report only.

**Deliverable:** `reports/01-critical-code-review.md`.

## Review dimensions

1. **SOLID / Clean Code / DRY** — SRP god classes, OCP-hostile status switches, DIP breaks
   (service-locator use inside services), long methods, magic values, duplicated logic across
   webhook/event handlers.
2. **Dead code** — classes/methods/interfaces with zero references, orphan `services.yaml` entries,
   unused translation keys, stray root-level files, commented-out blocks.
3. **Test quality / TDD** — fake or tautological tests (asserting mock configuration back),
   assertion-free tests, over-mocked interaction tests, untested security-relevant classes,
   skipped/incomplete tests.
4. **Digital security** — webhook guard chain (IP allowlist, HTTPS, rate limit, payload size),
   trust-on-POST vs re-fetch from Mollie, API-key handling and log leakage, SQL/template injection,
   CSRF on admin + frontend state-changing actions, return-flow forgery (contract token entropy,
   `hash_equals`), refund/capture amount integrity, open redirects, CI workflow injection.

## Method

- Four parallel review passes (one per dimension), each reading the actual code — findings
  accepted only with file:line evidence; pattern-match-only claims rejected.
- Scope excludes `vendor/`, `node_modules/`, embedded `source/` shop checkout.
- Findings ranked by severity; report also names what the codebase does well (credibility).

## Definition of Done

- Report written with severity-ranked, file:line-referenced findings per dimension.
- `status.md` updated with the sprint bullet.
- Nothing implemented — fixes are follow-up sprints picked from the report.
