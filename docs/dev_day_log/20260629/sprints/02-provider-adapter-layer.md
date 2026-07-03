# Sprint 2 — Provider adapter layer

**Goal:** Wrap the Mollie SDK behind segregated interfaces + a lazy adapter, returning immutable
DTOs, so no Mollie SDK type leaks past `src/Mollie/Adapter/`.
**Definition of Done (sprint-level):** A service can call `createPayment`/`createRefund`/
`fetchByWebhookId` against a faked Mollie client and receive typed DTOs; a regression test proves
no `Mollie\Api\*` import exists outside `Adapter/`.

## Out of scope
- Contract/order flow (Sprint 4) and webhook routing (Sprint 5) — this sprint is the SDK boundary only.
- Two-step capture wiring into admin (Sprint 6); the `MollieCaptureAdapterInterface` exists but is
  exercised only by adapter unit tests here.
- Mandates/vaulting adapter (Sprint 8 stretch).

## Risks & unknowns
- **Mollie SDK shape.** `mollie/mollie-api-php` exposes `MollieApiClient->payments/refunds/...`.
  De-risk spike (½ day): write one throwaway `createPayment` call against a sandbox key, capture
  the response shape, then design DTOs from the real payload — not from docs.
- **Capture availability per method.** Capture only applies to card/Klarna two-step. De-risk:
  `MollieCaptureAdapter` throws a typed `CaptureNotSupportedException` for unsupported methods;
  tested, not silently no-op.

---

## Story 1 — Define 4 segregated adapter interfaces (ISP)

**Why:** Avoid Stripe's 26-method monolith (PHPMD `TooManyMethods`); each consumer imports only
its slice. Honors ISP from CLAUDE.md.
**Estimate:** S

**Tests first (TDD):**
- `tests/Unit/Adapter/AdapterInterfaceContractTest.php`
  - `testEachAdapterInterface_HasAtMostFourMethods`
  - `testInterfaces_DeclareOnlyDtoReturnTypes_NoSdkTypes`

**Implementation steps:**
1. `Adapter/MolliePaymentsAdapterInterface.php` — `createPayment(CreatePaymentRequest): MolliePaymentDto`,
   `getPayment(string $id): MolliePaymentDto`, `cancelPayment(string $id): MolliePaymentDto`.
2. `Adapter/MollieCaptureAdapterInterface.php` — `createCapture(CaptureRequest): MollieCaptureDto`.
3. `Adapter/MollieRefundAdapterInterface.php` — `createRefund(RefundRequest): MollieRefundDto`,
   `getRefund(string $paymentId, string $refundId): MollieRefundDto`.
4. `Adapter/MollieWebhookAdapterInterface.php` — `fetchByWebhookId(string $id): MolliePaymentDto`.

**SOLID/Clean check:**
- ISP: 4 interfaces, each <= 3 methods. DIP: services will depend on these, never on `LazyMollieAdapter`.
- No overengineering: no `MollieMethodsAdapterInterface` until the storefront selector (Sprint 7) needs it.

**DevOps gate:** `phpstan` ✓ · `phpcs` ✓ · Unit ✓.

**Definition of Done:** Four interfaces compile; contract test proves method counts and DTO-only signatures.

---

## Story 2 — Immutable request/response DTOs

**Why:** No raw SDK object may cross the adapter boundary (PayPal `NoDirectSdkImports` rule);
DTOs are the typed contract downstream code tests against.
**Estimate:** M

**Tests first (TDD):**
- `tests/Unit/Adapter/Dto/MolliePaymentDtoTest.php`
  - `testFromArray_MapsStatusAmountAndCheckoutUrl`
  - `testIsReadonly_HasNoSetters`
- `tests/Unit/Adapter/Dto/MollieRefundDtoTest.php`
  - `testFromArray_MapsRefundIdAndAmount`
- `tests/Unit/Adapter/Dto/MollieAmountDtoTest.php`
  - `testToMollieArray_FormatsValueAsTwoDecimalString` (Mollie wants `{"currency","value":"10.00"}`)

**Implementation steps:**
1. `Adapter/Dto/MollieAmountDto.php` — readonly `{currency, value}`; `fromMollie()` / `toMollieArray()`.
2. `Adapter/Dto/MolliePaymentDto.php` — readonly id, status, amount, checkoutUrl, method, metadata.
3. `Adapter/Dto/MollieRefundDto.php`, `MollieCaptureDto.php` — readonly result carriers.
4. Request DTOs `CreatePaymentRequest`, `RefundRequest`, `CaptureRequest` (readonly inputs).

**SOLID/Clean check:**
- SRP: each DTO is one immutable value. DRY: `MollieAmountDto` is the single money-format place
  (Mollie's string-decimal quirk lives here, nowhere else).
- LSP: DTOs are final readonly; no inheritance.

**DevOps gate:** `phpstan` (max) ✓ · `phpmd` ✓ (DTOs excluded from autowire) · Unit ✓.

**Definition of Done:** DTOs round-trip Mollie payloads; amount formatting centralized and tested.

---

## Story 3 — MollieClientFactory + LazyMollieAdapter (lazy SDK init)

**Why:** Credentials aren't resolvable at container compile; defer client creation to first call
(PayPal `LazyPayPalAdapter` pattern). One client/one auth per request.
**Estimate:** M

**Tests first (TDD):**
- `tests/Unit/Adapter/MollieClientFactoryTest.php`
  - `testCreate_SelectsTestKeyInTestMode`
  - `testCreate_ThrowsConfigExceptionWhenKeyMissing`
- `tests/Unit/Adapter/LazyMollieAdapterTest.php`
  - `testDoesNotBuildClientUntilFirstMethodCall`
  - `testReusesSingleClientAcrossCalls`

**Implementation steps:**
1. `Service/Factory/MollieAdapterFactory.php` — reads mode + key via `ModuleConfigurationService`
   (Sprint 3 dependency; here injected as interface, faked in tests), builds `MollieApiClient`.
2. `Adapter/LazyMollieAdapter.php` — implements all 4 interfaces; holds a `?\Closure $clientFactory`;
   builds the client on first use, caches it.
3. Provide a protected `buildClient()` seam for the testable-subclass pattern.

**SOLID/Clean check:**
- SRP: factory builds, adapter delegates. DIP: factory depends on `ModuleConfigurationServiceInterface`.
- No-else: early-return guard when key missing. LSP: `LazyMollieAdapter` is a drop-in for each interface.

**DevOps gate:** `phpstan` ✓ · Unit ✓.

**Definition of Done:** Adapter builds zero clients until first call, then exactly one; missing key fails fast & typed.

---

## Story 4 — MollieAdapter: translate SDK <-> DTOs (the only SDK-aware class)

**Why:** This is the 5% provider-specific translation; everything above and below is DTO-typed.
**Estimate:** L

**Tests first (TDD):**
- `tests/Unit/Adapter/MollieAdapterTest.php`
  - `testCreatePayment_SendsAmountMethodRedirectAndWebhookUrls`
  - `testCreatePayment_ReturnsDtoWithCheckoutUrl`
  - `testCreateRefund_PartialAmount_SendsCorrectBody`
  - `testCancelPayment_DelegatesToSdk`
  - `testCreateCapture_OnNonCapturableMethod_ThrowsCaptureNotSupported`
  - `testSdkException_IsConvertedToDomainException`

**Implementation steps:**
1. `Adapter/MollieAdapter.php` implementing the 4 interfaces; ctor takes the SDK `MollieApiClient`.
2. Map create/refund/capture/cancel calls; build request bodies from request DTOs via `MollieAmountDto`.
3. `Adapter/MollieExceptionConverter.php` — SDK `ApiException` -> domain exception (no SDK type leaks).
4. `LazyMollieAdapter` delegates to a `MollieAdapter` instance.

**SOLID/Clean check:**
- SRP: `MollieAdapter` is the sole SDK translator. DRY: amount formatting via `MollieAmountDto` only.
- No-else: guard clauses for unsupported method/missing fields.

**DevOps gate:** `phpstan` ✓ · `phpmd` ✓ · Unit ✓.

**Definition of Done:** All four operations translate to/from DTOs against a faked client; SDK
exceptions surface as domain exceptions.

---

## Story 5 — MollieStatusMapper (Mollie status -> contract vocabulary)

**Why:** Webhook/return handlers must map `open|pending|paid|authorized|expired|canceled|failed`
onto contract transitions without scattering string comparisons.
**Estimate:** S

**Tests first (TDD):**
- `tests/Unit/Adapter/MollieStatusMapperTest.php`
  - `testPaid_MapsToCaptured`
  - `testAuthorized_MapsToAuthorized`
  - `testExpired_MapsToExpired`
  - `testCanceledAndFailed_MapToTerminalFailureStates`
  - `testUnknownStatus_MapsToIgnored` (safe default, no throw)

**Implementation steps:**
1. `Adapter/MollieStatusMapper.php` — single `map(string $mollieStatus): MollieOutcome` (enum/const set).
2. Cover all documented Mollie payment statuses; unknown -> `IGNORED`.

**SOLID/Clean check:**
- SRP/DRY: the only place a Mollie status string is interpreted. No-else: use a match expression.
- No overengineering: a `match`, not a strategy-class-per-status.

**DevOps gate:** `phpstan` ✓ · Unit ✓.

**Definition of Done:** Every Mollie status maps deterministically; unknown is a safe no-op outcome.

---

## Story 6 — Regression guard: no SDK imports outside Adapter/ + services.yaml wiring

**Why:** Lock the boundary so later sprints can't smuggle SDK types into services (PayPal
`NoDirectSdkImportsRegressionTest`).
**Estimate:** S

**Tests first (TDD):**
- `tests/Unit/Architecture/NoDirectSdkImportsRegressionTest.php`
  - `testNoMollieApiImportOutsideAdapterDirectory`

**Implementation steps:**
1. Regression test greps `src/Mollie` (excluding `Adapter/`) for `use Mollie\Api`.
2. `services.yaml`: register `MollieAdapterFactory` (public), `LazyMollieAdapter`
   (`factory: [..., 'create']`, `shared: true`), and alias all 4 interfaces to the one
   `LazyMollieAdapter` instance.

**SOLID/Clean check:**
- DIP wiring: 4 interface aliases -> 1 shared lazy instance (one client/auth per request).

**DevOps gate:** `phpstan` ✓ · container-compile Integration ✓ · `./bin/pre-commit-check.sh` ✓.

**Definition of Done:** Grep guard green; container exposes the 4 interfaces, all backed by one adapter.

---

## Suggested order
1. Story 1 (interfaces) — the contract everything else implements/depends on.
2. Story 2 (DTOs) — the return types the interfaces reference.
3. Story 5 (status mapper) — small, independent, unblocks Sprint 4/5 handlers early.
4. Story 3 (factory + lazy) — needs interfaces; do the sandbox spike here.
5. Story 4 (MollieAdapter) — the heavy translator; needs DTOs + factory.
6. Story 6 (guard + wiring) — locks the boundary once everything exists.
