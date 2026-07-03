# Sprint 4 — Checkout flow (contract -> early order -> Mollie payment -> redirect -> return)

**Goal:** "Place Order" with a Mollie method creates a contract + early NOT_FINISHED order,
creates a Mollie payment, and redirects the customer to Mollie's checkout URL; on return the
contract advances. (Final fulfillment is the webhook's job — Sprint 5.)
**Definition of Done (sprint-level):** Selecting a Mollie method and placing an order yields a 302
to a real sandbox checkout URL with an order number attached; returning lands on the shop with the
contract in the expected pending/authorized state.

## Out of scope
- Webhook-driven fulfillment to FULFILLED (Sprint 5) — return alone does NOT finalize.
- Storefront method *selector* UI/JS (Sprint 7); this sprint can hardcode/auto-pick a method or
  use a minimal twig stub to drive the flow.
- Admin capture/refund (Sprint 6).

## Risks & unknowns
- **Redirect vs webhook race.** Customer may return before the webhook arrives. De-risk: return
  handler must be idempotent and NOT assume payment final — it reads status, advances only what's
  safe, and lets the webhook do fulfillment. Mirror PayPal's return/webhook split.
- **Order number before payment.** Mollie `metadata`/`description` should carry the order number;
  rely on payment-base's early-order handler (priority 90) to mint it before the create call.

---

## Story 1 — MollieCheckoutSessionRequestEvent + MollieContractCreationHandler

**Why:** "Place Order" must create a DRAFT contract with a basket snapshot before anything else
(priority 100), mirroring PayPal/Stripe contract-creation.
**Estimate:** M

**Tests first (TDD):**
- `tests/Unit/EventSystem/Handler/MollieContractCreationHandlerTest.php`
  - `testHandle_CreatesDraftContractWithBasketSnapshot`
  - `testHandle_StoresSelectedMethodInContractMetadata`
  - `testHandle_DispatchesContractCreatedEvent`

**Implementation steps:**
1. `EventSystem/Event/MollieCheckoutSessionRequestEvent.php` (extends payment-base `AbstractEvent`).
2. `EventSystem/Handler/MollieContractCreationHandler.php` extends payment-base
   `ContractCreationHandler`; stores method + return/webhook intent in metadata; tag
   `payment.event_handler` priority 100.

**SOLID/Clean check:** SRP: contract creation only. LSP: honors abstract `ContractCreationHandler`
contract (dispatch `ContractCreatedEvent`). DIP: depends on `ContractRepositoryInterface`.
**DevOps gate:** `phpstan` ✓ · Unit ✓.
**Definition of Done:** Placing an order creates a DRAFT contract; downstream `ContractCreatedEvent` fires.

---

## Story 2 — PaymentController integration: dispatch the request event

**Why:** The OXID payment/order controller is the thin entry point that emits the event; it must
stay thin (dispatch + inspect + redirect).
**Estimate:** M

**Tests first (TDD):**
- `tests/Unit/Controller/MolliePaymentControllerTest.php`
  - `testExecute_WhenMollieMethodSelected_DispatchesCheckoutSessionRequestEvent`
  - `testExecute_WhenNonMollieMethod_DelegatesToParent`

**Implementation steps:**
1. `Controller/MolliePaymentController.php` extends `PaymentController_parent`; on Mollie method,
   build `EventContext` and dispatch the request event; read resulting checkout URL from context.
2. Keep all logic in services/handlers — controller only orchestrates.

**SOLID/Clean check:** SRP: thin controller. LSP: preserves OXID `PaymentController::execute()`
contract for non-Mollie payments (early return / parent delegate). No-else.
**DevOps gate:** `phpstan` ✓ · `phpmd` ✓ · Unit ✓.
**Definition of Done:** Mollie selection routes through the event; other methods are untouched.

---

## Story 3 — MollieCheckoutSessionHandler: create the Mollie payment

**Why:** After the early order exists (priority 90 shared handler), create the Mollie payment and
stash its id + checkout URL on the contract (priority ~10).
**Estimate:** L

**Tests first (TDD):**
- `tests/Unit/EventSystem/Handler/MollieCheckoutSessionHandlerTest.php`
  - `testHandle_CallsCreatePaymentWithAmountMethodAndOrderNumber`
  - `testHandle_SendsRedirectUrlAndWebhookUrl`
  - `testHandle_StoresMolliePaymentIdAndCheckoutUrlOnContract`
  - `testHandle_OnAdapterException_FailsContract`

**Implementation steps:**
1. `Service/CheckoutPaymentService.php` builds the `CreatePaymentRequest` (amount via
   `MollieAmountDto`, method, description=order no., `redirectUrl`, `webhookUrl`).
2. `EventSystem/Handler/MollieCheckoutSessionHandler.php` calls `MolliePaymentsAdapterInterface`,
   `setProvider('mollie', $payment->id, $payment->checkoutUrl)`, stores ids in metadata.
3. On adapter exception -> `contract.fail(...)`; tag `payment.event_handler` priority 10.

**SOLID/Clean check:** SRP: service builds request, handler orchestrates. DIP: handler depends on
`MolliePaymentsAdapterInterface` (not the lazy concrete). DRY: amount via `MollieAmountDto`.
**DevOps gate:** `phpstan` ✓ · Unit ✓.
**Definition of Done:** A real sandbox payment is created with the order number; checkout URL stored on contract.

---

## Story 4 — Redirect to Mollie checkout URL

**Why:** Mollie is redirect-first; the controller must 302 to `_links.checkout`.
**Estimate:** S

**Tests first (TDD):**
- `tests/Unit/Controller/MollieRedirectTest.php`
  - `testExecute_RedirectsToStoredCheckoutUrl`
  - `testExecute_WhenNoCheckoutUrl_ShowsError`

**Implementation steps:**
1. After Story 3 stores the URL on the contract/context, controller returns the redirect.
2. Guard: missing URL -> user-facing error, contract already failed.

**SOLID/Clean check:** SRP: controller redirect only. No-else: guard then return.
**DevOps gate:** `phpstan` ✓ · Unit ✓.
**Definition of Done:** Place Order ends at Mollie's hosted checkout (sandbox) with the order number visible.

---

## Story 5 — Return handler: MollieCheckoutReturnEvent + return security

**Why:** Customer returns to `redirectUrl`; we must resolve the contract safely (token-checked) and
advance it to pending/authorized WITHOUT assuming the payment is final.
**Estimate:** L

**Tests first (TDD):**
- `tests/Unit/Service/CheckoutReturnServiceTest.php`
  - `testResolve_WithValidToken_LoadsContract`
  - `testResolve_WithTamperedToken_Rejected`
  - `testResolve_FetchesPaymentStatusAndAdvancesContractIdempotently`
  - `testResolve_WhenStillOpen_LeavesContractPendingForWebhook`
- `tests/Unit/Controller/MollieOrderControllerTest.php`
  - `testCheckoutReturn_DispatchesReturnEvent`

**Implementation steps:**
1. `EventSystem/Event/MollieCheckoutReturnEvent.php` + `Service/MollieReturnResolver.php`
   (implements payment-base `ReturnResolverInterface`).
2. Reuse payment-base return-security/token primitives (HMAC) for tamper-proof contract id.
3. `Controller/MollieOrderController.php` (or service-tagged order controller) handles
   `fnc=checkoutReturn`; fetch status via `getPayment`, map via `MollieStatusMapper`, advance
   contract idempotently (paid->toward ready_to_commit; authorized->authorize(); open->leave pending).

**SOLID/Clean check:** SRP: resolver resolves, controller routes. DIP: `ReturnResolverInterface`.
Idempotent: re-entry is a no-op (webhook may have already advanced). No-else: match on outcome.
**DevOps gate:** `phpstan` ✓ · `phpmd` ✓ · Unit ✓ · `./bin/pre-commit-check.sh` ✓.
**Definition of Done:** Returning with a valid token advances the contract safely and idempotently;
tampered tokens are rejected; an open payment leaves fulfillment to the webhook.

---

## Story 6 — Storefront user-data validation via payment-base validation system

**Why:** Stripe adopted payment-base's **central user-input validation** (Sprint 119-124,
STRP-129); **PayPal never did**. Mollie sends customer name/address to Mollie at create-payment
and collects OXID user fields at checkout, so that input must be character-validated
(anti-injection) using the **shared** system — not a bespoke validator, and not skipped.
**Estimate:** L

**Tests first (TDD):**
- `tests/Unit/Service/UserDataValidatorTest.php`
  - `testValidate_FieldWithBlockedCharacter_Fails_ReportsFieldCodeAndChar`
  - `testValidate_UnicodeLetters_Umlauts_Pass` (oeaeuess + Polish letters)
  - `testValidate_WhenInvalid_BlocksCheckoutDispatch`
- `tests/Unit/Service/ValidationRulesProviderTest.php`
  - `testProvides_RulesForAllCollectedUserFields`
- `tests/Integration/Validation/ValidationApiEndpointTest.php`
  - `testPost_MollieModuleId_BadField_ReturnsValidFalseWithErrors`
  - `testPost_InactiveModuleId_Rejected422` (PluginIdAllowlistGuard)
  - `testPost_MissingCsrf_Rejected` (shared CsrfTokenGuard)

**Implementation steps:**
1. `src/Resources/validation-rules.php` — per-field allow/block character rules for the fields
   Mollie collects/sends (firstName, lastName, street, houseNumber, postalCode, city, company,
   vatId, additionalInfo, phone, email). Copy Stripe's UNICODE_LETTERS-based set; adjust to the
   exact Mollie create-payment payload.
2. `Service/ValidationRulesProvider.php` + bind `ValidationBaseInterface` for the Mollie module id
   in `services.yaml` (module id as a YAML literal, mirroring Stripe).
3. `Service/UserDataValidator.php` (+ interface) consumed **server-side** in the checkout flow
   (Story 1/2) before `createPayment`; invalid -> do not dispatch, surface error.
4. `Service/UserDataValidationMessageFormatter.php` tagged
   `oe.payment_base.validation_message_formatter` (picked up by the shared `ValidationApiController`).
5. Frontend: post to `index.php?cl=oepaymentvalidationapi&fnc=validate` with `pluginModuleId` for
   live field validation; CSRF / same-origin / rate-limit are enforced by the shared guard chain.
   No own endpoint, no own guard chain.
6. (Optional) `oe.payment_base.rate_limit_override` tag only if Mollie needs a non-default limit.

**SOLID/Clean check:**
- DRY: reuse payment-base `ValidationBase` + guard chain — do NOT reimplement character classes
  or a second endpoint (the whole point of the shared system). DIP: depend on
  `ValidationBaseInterface` / `ValidationRuleLoaderInterface`. SRP: rules-data vs validator vs
  formatter are separate. No overengineering: rules are **data** (`validation-rules.php`), not classes.
- No-else: guard-clause rejection on first invalid field.

**DevOps gate:** `phpstan` (max) ✓ · `phpmd` ✓ · `phpcs` ✓ · Unit ✓ · Integration (endpoint + guards) ✓ · `./bin/pre-commit-check.sh --full` ✓.

**Definition of Done:** Malformed characters in customer data are rejected **server-side** (before
create-payment) **and** via the shared frontend endpoint; rules cover every collected field; the
module reuses the payment-base system (no bespoke validator/endpoint) — closing the gap PayPal left open.

---

## Suggested order
1. Story 1 (contract creation) — root of the flow.
2. Story 3 (create payment handler) — the core money call (needs Sprint 2 adapter + Sprint 3 config).
3. Story 2 (controller dispatch) — wires the event from the UI entry point.
4. Story 4 (redirect) — tiny, completes the outbound leg.
5. Story 6 (user-data validation) — wire before create-payment so Story 3 dispatches only clean data.
6. Story 5 (return) — the inbound leg; idempotent, token-checked.
