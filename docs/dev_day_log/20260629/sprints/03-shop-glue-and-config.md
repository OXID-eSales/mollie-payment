# Sprint 3 — Shop glue & config

**Goal:** Implement the three OXID adapters payment-base requires plus the module configuration
service and its validator, so checkout (Sprint 4) has shop context and credentials.
**Definition of Done (sprint-level):** payment-base can read session/basket, shop config, and
create orders through Mollie's adapters; `ModuleConfigurationService` returns the active API key &
mode; `ConfigurationValidator` rejects malformed keys.

## Out of scope
- Any Mollie API call (Sprint 2 owns the SDK; this sprint is shop-side glue + config reads).
- Logging factories (Sprint 8 logging-control story) — config exposes the level enum but no gating yet.

## Risks & unknowns
- **Reuse vs copy of Oxid*Adapter.** Stripe & PayPal have near-identical copies. Risk: premature
  extraction into a shared lib. Decision: **copy PayPal's**, adjust nothing structural; revisit a
  shared package only if a 4th provider appears (rule of three).

---

## Story 1 — OxidSessionAdapter (SessionAdapterInterface)

**Why:** payment-base reads basket/user/session vars only through this seam.
**Estimate:** S

**Tests first (TDD):**
- `tests/Unit/Adapter/OxidSessionAdapterTest.php`
  - `testGetBasket_ReturnsSessionBasket`
  - `testSetAndGetVariable_RoundTrips`

**Implementation steps:**
1. Copy PayPal `OxidSessionAdapter`; adjust namespace. 2. Protected `getSession()` seam for tests.

**SOLID/Clean check:** SRP: session access only. LSP: satisfies `SessionAdapterInterface` exactly.
**DevOps gate:** `phpstan` ✓ · Unit ✓.
**Definition of Done:** Basket/user/var read-write works against a faked OXID session.

---

## Story 2 — OxidShopAdapter (ShopAdapterInterface)

**Why:** Translations, shop URL, currency, language, and test-mode flag flow through this seam.
**Estimate:** S

**Tests first (TDD):**
- `tests/Unit/Adapter/OxidShopAdapterTest.php`
  - `testGetShopUrl_ReturnsConfiguredUrl`
  - `testIsTestMode_ReflectsModuleMode`
  - `testTranslateString_DelegatesToLang`

**Implementation steps:**
1. Copy PayPal `OxidShopAdapter`; wire `isTestMode()` to `ModuleConfigurationService` (Story 4).
2. `getAdapterName()` returns `'oxid'`.

**SOLID/Clean check:** SRP: shop config reads. DIP: depends on config interface for mode.
**DevOps gate:** `phpstan` ✓ · Unit ✓.
**Definition of Done:** Shop URL/currency/lang/test-mode resolve; strings translate.

---

## Story 3 — OxidShopOrderService (ShopOrderServiceInterface)

**Why:** payment-base's early-order + commitment handlers create/finalize the OXID order via this.
**Estimate:** M

**Tests first (TDD):**
- `tests/Unit/Adapter/OxidShopOrderServiceTest.php`
  - `testCreateOrder_ReturnsOrderIdAndNumber`
  - `testFinalizeOrder_SetsOrderState`
  - `testLoadOrder_ByIdReturnsOrder`

**Implementation steps:**
1. Copy PayPal `OxidShopOrderService`; adjust namespace. 2. Keep order-create logic thin —
   payment-base orchestrates; this only does OXID CRUD. 3. Protected `oxNew` seam for tests.

**SOLID/Clean check:** SRP: order CRUD only — no payment logic. No-else early returns on load failure.
**DevOps gate:** `phpstan` ✓ · `phpmd` ✓ · Unit ✓.
**Definition of Done:** Order create/load/finalize works against faked OXID order objects.

---

## Story 4 — ModuleConfigurationService (test/live key + mode + capture mode)

**Why:** Single source of truth for credentials & mode; the adapter factory and shop adapter both
depend on it. No `Registry::getConfig()` reach-ins elsewhere (CLAUDE.md DI rule).
**Estimate:** M

**Tests first (TDD):**
- `tests/Unit/Service/ModuleConfigurationServiceTest.php`
  - `testGetApiKey_ReturnsTestKeyInTestMode`
  - `testGetApiKey_ReturnsLiveKeyInLiveMode`
  - `testGetCaptureMode_DefaultsToAutomatic`
  - `testReadSetting_IsProtectedSeam` (testable-subclass pattern)

**Implementation steps:**
1. `Service/ModuleConfigurationService.php` (+ interface) reading `sMollieMode`, `sMollieTestKey`,
   `sMollieLiveKey`, `sMollieCaptureMode`, `sMollieLogLevel` via `ModuleSettingServiceInterface`.
2. Protected `readSetting()` seam (PayPal pattern) — tests subclass, no DAO mocks.

**SOLID/Clean check:** SRP: config reads. DIP: depends on OXID `ModuleSettingServiceInterface`.
ISP: small interface (getters only). No-else: mode switch via match.
**DevOps gate:** `phpstan` ✓ · Unit ✓.
**Definition of Done:** Active key & mode resolve correctly; no Registry reach-ins; seam tested.

---

## Story 5 — ConfigurationValidator (reject malformed keys/config)

**Why:** Fail fast on `test_`/`live_` key shape mismatch and mode/key inconsistency before any API call.
**Estimate:** S

**Tests first (TDD):**
- `tests/Unit/Service/ConfigurationValidatorTest.php`
  - `testValidate_TestModeWithLiveKey_Fails`
  - `testValidate_KeyWrongPrefix_Fails`
  - `testValidate_WellFormed_Passes`

**Implementation steps:**
1. `Service/ConfigurationValidator.php` (+ interface): assert key prefix matches mode
   (`test_` in test, `live_` in live), non-empty, plausible length.
2. Return a typed result object listing failures (reuse payment-base validation message shape if present).

**SOLID/Clean check:** SRP: config validity only. No overengineering: regex + prefix check, not a rules engine.
**DevOps gate:** `phpstan` ✓ · `phpmd` ✓ · Unit ✓ · `./bin/pre-commit-check.sh` ✓.
**Definition of Done:** Mode/key mismatch and bad prefixes are rejected with a clear message; valid config passes.

---

## Suggested order
1. Story 4 (config service) — Story 2/3 and Sprint 2's factory depend on it.
2. Story 5 (validator) — small, pairs with config.
3. Story 1 (session) — independent, quick.
4. Story 2 (shop) — needs config for test-mode.
5. Story 3 (order service) — heaviest glue; do last.
