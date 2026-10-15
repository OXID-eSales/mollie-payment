# Report 01 — Critical code review of the Mollie module (2026-10-14)

Sprint plan: `../sprints/01-critical-code-review.md`. Review only — nothing implemented.

**Scope:** the module's own code at `source/extensions/mollie-payment` — `src/` (156 PHP files,
~12.5k LOC), `services.yaml`, `metadata.php`, `views/`, `assets/`, `resources/`, `translations/`,
`bin/`, `tests/` (~137 PHP test files + 31 Playwright specs), `.github/workflows`, `.gitleaks.toml`.
Excluded: `vendor/`, `node_modules/`, embedded `source/` shop checkout.

**Method:** four independent review passes (SOLID/Clean-Code/DRY · dead code · test quality/TDD ·
security), each reading actual code; findings accepted only with file:line evidence. Dead-code pass
grepped every src class name across the module and verified all 135 services.yaml FQCNs resolve.
Test pass read 19 central test files fully, ran the Unit suite both ways. Security pass verified
claims in `docs/security/f-matrix.md` against code and spot-checked payment-base guards.

---

## Executive summary

The module is in unusually good shape for its genre: small final classes, constructor injection
throughout, ISP-segregated adapters behind one lazy SDK boundary, tagged-iterator webhook routing,
fail-closed error discipline with the reasoning documented in place, and strong behavior-pinning
tests on the money paths. **No critical or high security findings.** The criticism below is real but
calibrated — there are no god classes, no copy-paste disasters, no fake-test epidemic.

The items that actually demand action:

1. **The canonical Unit run is RED** — 7 errors in `tests/Unit/GraphQL/Controller/MollieCheckoutTest.php`
   (graphql-base `User` constructor drift between the module's vendored and the shop's installed
   version). The documented in-container command reports ERRORS today. (TDD/high)
2. **Checkout entry flow duplicated and already diverged** — the user-data gate (MOL-15) and the
   double-click replay guard (MOL-18) exist only on the `MollieOrderController` path, not in the OPC
   `MolliePaymentHandler` path. A missed guard on one path is a checkout-integrity bug. (DRY/high)
3. **No effective webhook rate limiting** — every forged `tr_…` id buys an authenticated Mollie API
   call; an attacker can exhaust the merchant's Mollie API quota. The rate-limit guard was honestly
   unwired as a no-op, but `docs/security/f-matrix.md` F17 still claims it ✅. (security/medium ×2)
4. **~640 lines of certainly dead PHP + a dead 73-line JS controller shipped in the production
   bundle** + a stray debug script at the repo root. (dead code)
5. **Three stale root-level Playwright specs that can never fail** (zero assertions / `console.log`
   instead of `expect`) — currently unmatched by any project, i.e. dead *and* fake. (tests/high)

---

## A. SOLID / Clean Code / DRY

### High

- **A-H1 — Duplicated checkout entry flow, already diverged.**
  `src/Mollie/Controller/MollieOrderController.php:62-113,258-278,330-343` vs
  `src/Mollie/PaymentHandler/MolliePaymentHandler.php:102-168,174-186,398-423`. Both build the same
  `EventContext`, run the same buyability validation and the same
  `NotOrderableCheckoutFailure::fromThrowable()` recovery; the handler docblock itself admits the
  mirror ("same shape … MollieOrderController::buildCheckoutContext()"). Divergence is live:
  `userDataProblems()` (MOL-15) and `InFlightCheckoutReplay` (MOL-18) guard only the controller
  path. → Extract a shared checkout-entry service both paths call.

- **A-H2 — Byte-for-byte mirror of core's private basket-hash logic with no pinning test.**
  `MollieOrderController.php:213-216` re-implements core `OrderController::getBasketSummaryHash()`
  (`md5(json_encode(getBasketSummary()))`). If core changes the recipe, every legitimate Mollie
  order dies with BASKET_ITEMS_CHANGED_ERROR and nothing flags the drift. → add a regression test
  pinning against core's actual behavior; pursue the known spin-off (make core helpers protected).

### Medium

- **A-M1 — `WebhookContractFulfillmentHandler` (429 lines): five status flows repeat the same
  ~20-line load/guard/transition/save/audit skeleton** (`doPaymentPaid:115`, `Failed:155`,
  `Expired:182`, `Canceled:214`) plus commit orchestration and order mirroring in one class.
  → template method `applyTerminalStatus(providerOrderId, transition, auditType)`.
- **A-M2 — `FulfillmentOutcome` discards the contract id**, so
  `AbstractMollieWebhookHandler.php:70-90` re-queries `findByProviderOrderId()` per webhook just to
  recover an id the fulfillment handler already had. → small result object `{outcome, contractId}`.
- **A-M3 — Verbatim duplication + magic epsilon.** `requireProviderOrderId()` identical in
  `CaptureService.php:88-99` and `RefundService.php:143-154`; float tolerance `0.00001` as an
  unnamed literal in `CaptureService.php:111`, `RefundService.php:173`,
  `Admin/AdminAmountValidator.php:52`; `$contract->getId() ?? 'unknown'` idiom ×9.
  → `MoneyComparison` helper / named const.
- **A-M4 — services.yaml re-binds ~10 payment-base internals "mirroring Stripe's bindings"**
  (`services.yaml:356-420,584-600,688-714`), correctness resting on byte-identical definitions in
  every PSP module and on merge order; the single-valued `TokenServiceInterface` forces injecting
  concrete `ContractTokenService` (documented DIP violation). Root fix belongs in payment-base
  (provider-keyed registries); meanwhile isolate the blocks in one documented compat section.
- **A-M5 — Three silent `catch (Throwable)` in ViewConfig** (`Core/ViewConfig.php:89-91,130-134,
  205-207`) return fallbacks without logging — inconsistent with the module's own "safe or loud"
  rule and with `NoSilentCatchRegressionTest`'s spirit.
- **A-M6 — Country-ISO lookup duplicated** (`ViewConfig.php:112-135` vs
  `MollieOrderDataProvider.php:156-166`), one copy instantiating `oxNew(Country)` inline in a
  view-config class. → `CountryIsoResolver` service.
- **A-M7 — `ModuleConfigurationService` does DAO I/O in the constructor** (:49-70), freezing failure
  state at container-instantiation time, and ships a test-only `initializeForTest()` seam (:76) in
  production surface. → lazy-load on first access.

### Low

- **A-L1** — Seven webhook handlers repeat the same 5-line missing-id guard; five are near-identical
  40-line classes (~150 removable lines); `extractAmountRefunded`/`extractAmountChargedBack`
  duplicated (`PaymentRefundedHandler.php:72-77` / `ChargebackCreatedHandler.php:67-72`).
  Mitigation: deliberate OCP via tagged services, each class trivially readable.
- **A-L2** — `WebhookController::render()` builds the guard request twice (`:122`, `:143`),
  re-reading `php://input` and double-emitting the proxy-header debug log.
- **A-L3** — Orphaned docblock: `advanceToAuthorized()`'s documentation stranded above
  `recordCapturedAmount()` (`WebhookContractFulfillmentHandler.php:334-345`) — actively misleading
  in a comment-load-bearing module.
- **A-L4** — Concrete collaborators as constructor defaults / inline `new` in logic:
  `MolliePaymentHandler.php:75-77`, `MollieCheckoutSessionHandler.php:47`,
  `MollieOrderController.php:340` (no seam at all), `MollieAdapter.php:170`.
- **A-L5** — `MolliePanelViewDataBuilder` maintains the 20-key view array twice
  (`fromContract:72-110` vs `empty():123-149`; `empty` is also a misleading name);
  `MolliePaymentHandler::getFrontendConfig()` mutates `$iframeFallbackLogged` on a DI singleton —
  the class's only impure getter.

**Done well (credibility):** `MollieAdapter` as the single SDK-aware class behind four segregated
interfaces with boundary exception conversion; `MollieStatusMapper` genuinely the only place status
strings are interpreted; `MollieLinesBuilder` a model pure domain service (cent-exact invariants,
bounded residual fold); exceptional decision documentation; service-locator use confined to OXID
class-chain extensions where DI doesn't exist, centralized and logged.

---

## B. Dead code

### Certainly dead (safe to delete, ~640 PHP lines + 73 JS lines + stray files)

| # | What | Evidence |
|---|---|---|
| B1 | `src/Mollie/Adapter/OxidShopOrderService.php` (256 L) + yaml entry + its integration test | services.yaml:167-171 says payment-base owns finalization now; zero src consumers; test-only |
| B2 | `ConfigurationValidator` + interface + result (~124 L) | aliased services.yaml:108-110 but nothing injects/resolves it; test-only |
| B3 | `/debug-product-checkout.mjs` (48 L, git-tracked, repo root) | one-off Playwright debug script; imports playwright which isn't even installed — cannot run |
| B4 | `resources/js/controllers/mollie_checkout_controller.js` (73 L, **ships in the prod bundle**) | registered as `mollie-checkout` in app.js:21; no template attaches it; its documented host templates were deleted |
| B5 | `views/twig/frontend/` empty directory | leftover of deleted `mollie_methods.html.twig` |
| B6 | `MollieDefinitions::EVENT_*` 5 constants (`:95-99`) | zero references incl. tests; routing uses concrete event classes |
| B7 | `MollieDefinitions::supportsCountry()`/`getSupportedCountries()` (`:256`,`:202`) + `'countries' => []` map entries | test-only caller; also retains the "empty = allow all" failure mode F16 removed from `supportsCurrency` — dead *and* inconsistent |
| B8 | `OxidShopAdapter::getActiveLanguageId()` (`:51`) | not in the interface, zero callers anywhere |
| B9 | Dead translation keys: `MOLLIE_VALIDATION_UNDERSTAND`, `MOLLIE_METHODS_UNAVAILABLE`; whole `views/twig/{de,en}/mollie_lang.php` files (refund-description keys are duplicates of the admin_twig copies, never loaded in admin context) | ~4% of 126 EN keys dead — healthy ratio |

### Dead by design (decide: delete or keep documented)

- **B10/B11 — `WebhookIpAllowlistGuard` (67 L) and `WebhookRateLimitGuard` (65 L)**: registered
  (services.yaml:459-461) but deliberately not in the guard chain; unit-tested behavior nothing can
  trigger at runtime. Ties into security finding D-M1: the rate limiter should be *replaced* with a
  shared-storage one, not merely deleted.

### Likely dead / upstream

- `MOLLIE_VALIDATION_INVALID_USER_DATA` key — kept alive only by the lang-parity test that pins it.
- Six adapter methods mandated by payment-base interfaces but called by nobody (module *and*
  payment-base): `OxidSessionAdapter::getSessionId()/setUser()`,
  `OxidShopAdapter::getShopName()/getShopCurrency()/getCurrentLanguageAbbr()/getAdapterName()` —
  flag upstream as fat-interface slices (ISP).
- Side observations: untracked `tests/e2e/playwright/diag-card3-tmp.mjs`; committed 262 KB
  `composer.lock` in a library-type module.

Explicitly verified NOT dead (tagged iterators, metadata controllers/extend, dynamic translation
prefixes, esbuild pipeline, console commands, GraphQL namespace mapper) — see the sweep notes; no
services.yaml entry points at a missing class; no commented-out code blocks found in src at all.

---

## C. Test quality / TDD

**Suite status:** shop-vendor config (`docker compose exec php … -c extensions/mollie-payment/tests/phpunit.xml --testsuite Unit`)
→ **756 tests, 7 ERRORS**; module-vendored config → green, 756 tests / 2060 assertions in 0.6 s.
Both configs set `beStrictAboutTestsThatDoNotTestAnything` — hence near-zero assertion-free PHP tests.

### High

- **C-H1 — Canonical Unit run is red.** `tests/Unit/GraphQL/Controller/MollieCheckoutTest.php:46`
  passes a string to graphql-base `User::__construct()` which now requires a shop `User` model —
  vendored-vs-installed graphql-base drift. A suite whose color depends on which vendor dir loads it
  is unreliable as a regression signal. → fix the fixture; pin/align graphql-base.
- **C-H2 — Fake e2e: `tests/e2e/playwright/tests/debug-admin-order.spec.ts`** — 97 lines, `expect`
  imported, never called; five 1–3 s sleeps; hardcoded order 215; all checks behind
  `isVisible().catch(() => false)` + `console.log`. Can never fail. (Also unmatched by any
  playwright project → dead.) → delete.
- **C-H3 — e2e tests green regardless of behavior:** root-level `mollie-checkout.spec.ts`
  ('Admin: View order and verify Mollie panel' ~:89, 'Admin: Execute partial refund' ~:130) log
  `✓/⚠` in if/else instead of asserting; shared mutable `createdOrder` across tests; plus trivial
  `admin-access.spec.ts`. All three root specs unmatched by any project → delete or rewrite under a
  real project.

### Medium

- **C-M1 — Untested src classes** (zero test references): `OxidStockRestorationService` (118 L of
  SQL stock restoration mirroring `OrderArticle::storno()` — the risky part; RefundServiceTest only
  verifies the *mock* is called), `PaymentMethodInstaller` (127 L activation-time DB writes),
  `MollieMethodsFetcher`, `OxidLanguageTranslator`, `Core/Events.php`.
- **C-M2 — `tests/Unit/Security/ValidationEndpointGuardParityTest.php`** tests payment-base vendor
  classes, not module code — inflates the module's security-test count; will rot as duplicate
  coverage.
- **C-M3 — Reflection into protected internals** (`MollieWebhookProcessorTest.php:199-204`) drives
  `parseAndValidateRequest`/`processEvent` via `ReflectionClass`; same behavior mostly covered via
  public `process()` — fragility over information. Trivial constant-echo test at :45.
- **C-M4 — Six near-identical wrapper-handler test files** (Payment*HandlerTest) — the
  outcome→result mapping *is* the handlers' logic, so not tautological, but ~60% scaffold
  duplication per file.

### Low

- Constant-duplication change detectors (`MollieDefinitionsTest.php:84-90`, `:29-32`);
  `assertTrue(true)` filler branch (`ContractTokenNotPersistedTest.php:96`); template-source regex
  tests in Unit (`MolliePanelHelpHintTest.php:20-33` + siblings — the Integration template tests do
  it right by rendering through the real twig bridge); arbitrary "≤4 methods per interface" policy
  test (`AdapterInterfaceContractTest.php:46-51`); ~25× `waitForTimeout()` sleeps across otherwise
  good e2e suites; setUp `markTestSkipped` in Integration silently vanishes tests on misconfigured
  CI.

**Done well:** dated root-cause narratives proving red-first discipline (WebhookFailClosedTest F3/F5
incl. a documented proxy-trust reversal after a real outage; STRP-168, MOL-17 with a real contract,
not a mock); outcome assertions over mock echo (RefundServiceTest refund ceilings,
MollieAdapterTest asserting the actual SDK request array); real crypto in security tests (flipped
token character fails + positive control); architecture tests with teeth (NoSilentCatchRegressionTest
with justification allowlist, ModuleIndependenceTest); integration layer runs the whole inbound
webhook pipeline against real Doctrine repositories with only the Mollie HTTP fetch stubbed; 75
exception-path usages across Unit.

---

## D. Digital security

**No critical/high findings.** The decisive controls were verified as genuinely implemented, not
just claimed (see "verified controls" below).

### Medium

- **D-M1 — No effective rate limiting on the webhook endpoint; DoS amplification against the
  merchant's Mollie API quota.** The guard chain holds only payload-size + HTTPS guards; the
  rate-limit guard was honestly unwired (per-request token buckets could never reject). The
  `tr_…`-shape precheck (`WebhookController.php:67`) only filters garbage — any shaped id triggers an
  authenticated `getPayment` round trip (`MollieWebhookProcessor.php:74`). Flooding
  `cl=MollieWebhookController&id=tr_<random>` starves legitimate webhook verification and checkout
  payment creation. → shared-backend limiter (shop cache, IP+minute) before the re-fetch, plus a
  negative cache for recently failed ids.
- **D-M2 — `docs/security/f-matrix.md` F17 is stale and now false** ("WebhookRateLimitGuard … ✅"
  while services.yaml removed it). → mark open/mitigated-only-by-id-shape until a real limiter ships.

### Low

- **D-L1** — HTTPS guard bypassable by default via client-supplied `X-Forwarded-Proto`
  (`WebhookController.php:60,238-250`, `TRUST_PROXY_HEADERS_DEFAULT = true`). Documented, accepted
  trade-off; actual webhook auth is the TLS API re-fetch, so spoofing gains nothing. If hardening:
  per-shop setting or trusted-proxy IP pinning instead of a subclass seam.
- **D-L2** — Return `contract_token` never expires and is deterministic
  (`ContractTokenService.php:35-51`: `base64url(id + HMAC(id))`, no timestamp/nonce). A leaked
  return URL replays indefinitely and renders that order's confirmation in any session
  (`HandlesMollieCheckoutReturn.php` writes `sess_challenge`). Integrity intact (HMAC-SHA256,
  `hash_equals`, status re-fetched live) — disclosure/replay hardening only. → embed expiry +
  per-attempt nonce; refuse replays on terminal contracts.
- **D-L3** — `.gitleaks.toml` Mollie rule matches only exactly-30-char keys
  (`(?:live|test)_[A-Za-z0-9]{30}`); a differently-sized real key commits clean. → `{25,}`.
  (Working tree + sampled history grep found only obvious fixtures; git log clean.)
- **D-L4** — API keys echoed into the admin DOM via `value="{{ confstrs[module_var] }}"`
  (`module_config.html.twig`); any admin-page XSS or admin user reads the live key. Template comment
  states the threat model and defers the fix. → write-only-on-change + redacted placeholder.
- **D-L5** — Opt-in `WebhookIpAllowlistGuard` is IPv4-only (`ip2long`) and `bits > 32` yields an
  uncaught `ArithmeticError` → 500 on every webhook (`:50-66`). Not wired by default. → validate
  bits 0–32, `inet_pton` for IPv6.
- **Info** — Webhook rejection bodies enumerate `guard_unavailable`/`processor_unavailable` to
  unauthenticated callers; negligible, could be collapsed to a generic body.

### Verified controls (done well)

Webhook verification IS the API re-fetch — POSTed status never trusted, only the id, unverifiable id
→ 400 before any state change; fail-closed guard chain (unbuildable chain → 503, fixing an earlier
fail-open `?->` bug); delivery-identity idempotency that dedupes replays without letting `pending`
claim `paid`; contract lookup keyed by the *re-fetched* payment id, not attacker input; admin
capture/refund/cancel CSRF-checked via payment-base `dispatchAction()` (`hash_equals` on stoken);
refund/capture ceilings and currency derived server-side from the live Mollie payment, never request
params; frontend `execute()` preserves core's full guard order (session challenge → AGB → basket
hash → user data); open-redirect defenses both directions (correct `str_ends_with('.' . host)` —
`evilmollie.com` fails); return-token crypto solid (HMAC-SHA256, constant-time, versioned key
derivation, hard-fail without API key); zero injection surface found (parameterized SQL only,
autoescaped twig, single `|raw` is core-standard `getHiddenSid()`, no JS sinks, card token never
logged); API key never interpolated into logs/exceptions, mode+key fail closed together; CI clean
(no `pull_request_target`, no untrusted `${{ }}` interpolation, `permissions: contents: read`,
gitleaks on tree + full history).

---

## Suggested follow-up sprints (ranked)

1. **Fix the red Unit run** (C-H1, graphql-base drift) — the regression signal must be trustworthy.
2. **Webhook rate limiter with shared storage + f-matrix F17 correction** (D-M1/D-M2, supersedes
   dead guards B10/B11).
3. **Unify the checkout entry flow** (A-H1) — one service, both paths, MOL-15/MOL-18 guards
   everywhere; add the basket-hash pinning test (A-H2).
4. **Dead-code purge** (B1–B9, C-H2/C-H3 stale e2e specs) — ~800 lines gone, prod JS bundle slimmer.
5. **Test the untested** (C-M1): `OxidStockRestorationService` first (money-adjacent SQL),
   `PaymentMethodInstaller` second.
6. Smaller hygiene batch: ViewConfig silent catches (A-M5), money-epsilon const (A-M3),
   `FulfillmentOutcome` contract id (A-M2), token expiry (D-L2), gitleaks regex (D-L3).
