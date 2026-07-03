# Sprint 8 — Hardening & parity

**Goal:** Bring Mollie to PayPal/Stripe security & quality parity, prove the happy path end-to-end,
add logging control, finalize docs. Stretch: vaulting/mandates, Mollie Connect (OAuth), card components.
**Definition of Done (sprint-level):** Security parity sweep complete (PayPal F-series mapped),
E2E checkout green against sandbox, logging is merchant-controllable, full docs published; stretch
items either landed or explicitly deferred with a ticket.

## Out of scope (unless promoted)
- Marketplace/Connect split-payments beyond OAuth onboarding skeleton.
- Subscriptions/recurring beyond first-payment mandate creation.

## Risks & unknowns
- **Parity scope creep.** The F-series is large. De-risk: map each F-item to "applies / N/A for
  Mollie / already covered"; only build the gaps. Document the matrix.

---

## Story 1 — Security parity sweep (map PayPal F1-F25)

**Why:** PayPal claims F1-F25 parity with Stripe; Mollie must consciously match or justify N/A
(esp. the no-signature webhook model).
**Estimate:** L

**Tests first (TDD):**
- `tests/Unit/Security/WebhookSecurityParityTest.php` (one test per applicable F-item)
  - `testF1_WebhookHttpsEnforced` · `testF2_PayloadSizeCapped` · `testF17_RateLimited` ·
    `testF18_VerificationByApiFetch_NotForgeable` · `testF20_ReturnTokenTamperRejected` …
- `tests/Unit/Security/ValidationEndpointGuardParityTest.php`
  - `testValidationApi_RejectsNonPost` · `testValidationApi_RejectsCrossOrigin` ·
    `testValidationApi_RejectsMissingCsrf` · `testValidationApi_RejectsInactiveModuleId422` ·
    `testValidationApi_RateLimited` (the shared `oepaymentvalidationapi` guard chain Mollie relies on)

**Implementation steps:**
1. Build the F-matrix doc: applies / N/A (e.g. F-signature -> replaced by fetch-by-id) / covered.
2. Include the **central user-input validation** system (Sprint 4 Story 6) in the matrix: confirm
   Mollie's `validation-rules.php` + server-side `UserDataValidator` + the shared endpoint guard
   chain are exercised — this is the subsystem **PayPal skipped**, so it is an explicit parity item.
3. Close each gap with a test + minimal fix; no speculative hardening.

**SOLID/Clean check:** No overengineering — only build gaps the matrix proves real.
**DevOps gate:** `phpstan` ✓ · `phpmd` ✓ · Unit ✓.
**Definition of Done:** Every applicable F-item has a passing test; N/A items documented with rationale.

---

## Story 2 — Logging control (level off/errors/normal/debug + gated factories)

**Why:** Reuse Stripe's harmonized logging design (2026-06-24): one level select gating the file
logger factories via `?\Closure $isEnabled`; frontend console gated at `debug`.
**Estimate:** M

**Tests first (TDD):**
- `tests/Unit/Service/Factory/LoggerGatingTest.php`
  - `testLevelOff_FactoryReturnsNullLogger_NoFileWritten` (`assertFileDoesNotExist`)
  - `testLevelDebug_ReturnsRealLogger`

**Implementation steps:**
1. Mollie file-logger factories extend payment-base `AbstractFileLoggerFactory` with a gating closure
   reading `sMollieLogLevel`. 2. Frontend `debug()` wrapper gated by the level (Stripe Phase-5b lesson:
   drive from a runtime flag, not build/domain).

**SOLID/Clean check:** SRP per channel. DRY: one level resolver feeds all channels + frontend. LSP:
NullLogger is a drop-in. No overengineering: a closure, not a `LoggingTogglesInterface`.
**DevOps gate:** `phpstan` ✓ · Unit ✓.
**Definition of Done:** `off` writes nothing on any channel; `debug` enables all incl. frontend console.

---

## Story 3 — E2E happy-path (Playwright, sandbox)

**Why:** Unit/integration can't prove the real redirect+webhook round-trip; one E2E locks the spine.
**Estimate:** L

**Tests first (TDD):**
- `tests/e2e/.../MollieStandard/CheckoutPaysAndFinalizes.spec.ts`
  - full checkout -> Mollie sandbox pay -> return -> webhook finalizes -> thank-you + OXPAID set
- `tests/e2e/.../MollieStandard/FrontendLoggingGated.spec.ts`
  - 0 Mollie console logs while log level is off (Stripe regression-test pattern)

**Implementation steps:**
1. Playwright project + sandbox creds via env. 2. Drive the standard checkout; assert order paid.
3. Reuse the deploy steps (restart php + cache clear + asset reinstall) — opcache lesson from Stripe.

**SOLID/Clean check:** n/a (test). **DevOps gate:** Playwright green on sandbox.
**Definition of Done:** A real sandbox payment finalizes an order end-to-end; frontend silent when logging off.

---

## Story 4 — Developer & merchant docs + architecture diagrams

**Why:** Parity with Stripe/PayPal `docs/` (architecture, for_developer, for_merchant); makes the
module maintainable and the extension points discoverable.
**Estimate:** M

**Tests first (TDD):**
- N/A (docs). Verify links resolve; `MetadataTest`/`SettingsTranslationsTest` already guard config.

**Implementation steps:**
1. `docs/architecture/` (00-overview, 01-layers, 02-event-system, 03-provider-abstraction,
   04-webhook-processing — mirror PayPal, adjust for fetch-by-id verification).
2. `docs/for_developer/` (module principles, payment-base dependency, extension patterns).
3. `docs/for_merchant/` (setup: API key + webhook URL, admin guide, troubleshooting).

**SOLID/Clean check:** n/a. **DevOps gate:** `./bin/pre-commit-check.sh --full` ✓.
**Definition of Done:** Docs match the shipped module; webhook URL + key setup documented for merchants.

---

## Story 5 (stretch) — Vaulting/mandates + Mollie Connect OAuth skeleton

**Why:** Mollie supports first-payment mandates (recurring) and OAuth for multi-merchant; both are
real but not happy-path. Build only if prioritized.
**Estimate:** L (split if taken)

**Tests first (TDD):**
- `tests/Unit/Service/MollieCustomerServiceTest.php`
  - `testCreateCustomer_AndStoreMandateReference`
- `tests/Unit/Controller/Admin/MollieConnectTest.php`
  - `testOAuthCallback_StoresOrgAccessToken`

**Implementation steps:**
1. `Service/MollieCustomerService.php` (customer + mandate) — only if recurring is in scope.
2. `Controller/Admin/MollieConnect.php` OAuth onboarding -> store org token; webhook auto-registration.

**SOLID/Clean check:** No overengineering — do NOT build this unless a present requirement exists;
otherwise defer with a ticket and delete from the sprint.
**DevOps gate:** `phpstan` ✓ · `phpmd` ✓ · Unit ✓ · `./bin/pre-commit-check.sh --full` ✓.
**Definition of Done:** (If taken) mandates create & store; OAuth onboarding stores a usable token.
Otherwise: explicitly deferred, ticket linked.

---

## Suggested order
1. Story 1 (security parity) — highest risk; gate before any release.
2. Story 2 (logging control) — small, reuses Stripe design.
3. Story 3 (E2E) — proves the spine.
4. Story 4 (docs) — once behavior is final.
5. Story 5 (stretch) — only if prioritized; default deferred.
