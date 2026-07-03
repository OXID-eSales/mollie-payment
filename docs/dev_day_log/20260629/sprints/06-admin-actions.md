# Sprint 6 — Admin actions (capture / refund / cancel authorization)

**Goal:** Merchant-initiated capture (where the method supports two-step), full/partial refund, and
cancel-authorization, dispatched from admin through the event translator to Mollie API calls.
**Definition of Done (sprint-level):** From admin an operator can refund (full & partial,
accumulating) any paid Mollie order, capture an authorized two-step payment, and cancel an
uncaptured authorization; each action updates the contract, records a transaction, and reconciles OXPAID.

## Out of scope
- The admin *UI* (forms/tab) — Sprint 7. This sprint is services + events + translator; drive via
  unit/integration tests dispatching the events directly.
- Methods that don't support two-step capture: Capture returns `CaptureNotSupported` (from Sprint 2), tested.

## Risks & unknowns
- **Capture semantics per method.** Only card/Klarna support authorize-then-capture. Risk: showing
  capture for auto-capture methods. De-risk: `AdminActionBounds` derives capability from the
  payment/method, not a global flag.
- **Refund bounds.** Cannot refund more than captured-minus-already-refunded. De-risk: bounds come
  from the Mollie payment (API truth), not local arithmetic alone.

---

## Story 1 — RefundService (full & partial, accumulating) on AbstractPaymentRefundService

**Why:** Refund is the most-used admin action; build it on payment-base's template so state
guards + transaction recording are inherited.
**Estimate:** L

**Tests first (TDD):**
- `tests/Unit/Service/RefundServiceTest.php`
  - `testRefund_Full_CallsAdapterAndAddsRefundedAmount`
  - `testRefund_Partial_AccumulatesAcrossClicks`
  - `testRefund_ExceedingRefundable_ThrowsException`
  - `testRefund_WhenContractNotFulfilled_Rejected`

**Implementation steps:**
1. `Service/RefundService.php` extends `AbstractPaymentRefundService`; `afterRefund()` hook records
   via the shared refund recorder (Sprint 5 Story 5) — DRY with webhook refunds.
2. Call `MollieRefundAdapterInterface::createRefund`; delta-only update.

**SOLID/Clean check:** SRP: refund orchestration. LSP: honors abstract template hooks. DRY: shared
recorder reused. No-else: guard-clause validation.
**DevOps gate:** `phpstan` ✓ · `phpmd` ✓ · Unit ✓.
**Definition of Done:** Full/partial refunds accumulate correctly and are bounded by API-derived refundable.

---

## Story 2 — CaptureService (two-step methods) on AbstractPaymentCaptureService

**Why:** Manual-capture orders (card/Klarna) need an explicit capture to take funds & advance.
**Estimate:** M

**Tests first (TDD):**
- `tests/Unit/Service/CaptureServiceTest.php`
  - `testCapture_AuthorizedPayment_CallsCreateCapture_AdvancesContract`
  - `testCapture_Partial_ReleasesRemainder`
  - `testCapture_OnAutoCaptureMethod_ThrowsCaptureNotSupported`
  - `testCapture_ExceedingAuthorized_Rejected`

**Implementation steps:**
1. `Service/CaptureService.php` extends `AbstractPaymentCaptureService`; call
   `MollieCaptureAdapterInterface::createCapture`; `captureAuthorization()` on contract.
2. Guard capability via `MollieStatusMapper`/payment method.

**SOLID/Clean check:** SRP: capture only. LSP: template hooks honored. No-else.
**DevOps gate:** `phpstan` ✓ · Unit ✓.
**Definition of Done:** Authorized two-step payments capture (full/partial); unsupported methods rejected clearly.

---

## Story 3 — CancelAuthorizationService

**Why:** Uncaptured authorizations must be releasable (cancel the Mollie payment, cancel the contract).
**Estimate:** S

**Tests first (TDD):**
- `tests/Unit/Service/CancelAuthorizationServiceTest.php`
  - `testCancel_UncapturedAuthorization_CallsCancelPayment_CancelsContract`
  - `testCancel_WhenAlreadyCaptured_Rejected`

**Implementation steps:**
1. `Service/CancelAuthorizationService.php` -> `MolliePaymentsAdapterInterface::cancelPayment`;
   `contract.cancel('mollie_authorization_canceled')`.

**SOLID/Clean check:** SRP. No-else guard on already-captured.
**DevOps gate:** `phpstan` ✓ · Unit ✓.
**Definition of Done:** Uncaptured authorizations cancel and release; captured ones are rejected.

---

## Story 4 — Admin events + handlers (Capture/Refund/CancelAuthorization request)

**Why:** Admin actions flow through events (consistent with PayPal/Stripe), keeping controllers thin.
**Estimate:** M

**Tests first (TDD):**
- `tests/Unit/EventSystem/Handler/MollieRefundRequestHandlerTest.php`
  - `testHandle_DelegatesToRefundService`
- `tests/Unit/EventSystem/Handler/MollieCaptureRequestHandlerTest.php`
  - `testHandle_DelegatesToCaptureService`
- `tests/Unit/EventSystem/Handler/MollieCancelAuthorizationRequestHandlerTest.php`
  - `testHandle_DelegatesToCancelService`

**Implementation steps:**
1. Events `Mollie{Capture,Refund,CancelAuthorization}RequestEvent` (optional `?float $amount`).
2. Handlers delegate to the Sprint 6 services; tag `payment.event_handler`.
3. `Controller/Admin/OrderActionDispatcher.php` builds `EventContext` + dispatches (copy PayPal shape).

**SOLID/Clean check:** SRP: handler delegates only. DIP: depends on service interfaces. No-else.
**DevOps gate:** `phpstan` ✓ · `phpmd` ✓ · Unit ✓.
**Definition of Done:** Each admin event invokes its service exactly once with the right args.

---

## Story 5 — MollieEventTranslator (abstract -> concrete) + idempotent admin requests

**Why:** payment-base dispatches provider-agnostic `*RequestedEvent`s; the translator routes them to
Mollie concrete events. Deterministic request ids prevent double-action.
**Estimate:** M

**Tests first (TDD):**
- `tests/Unit/EventSystem/Translator/MollieEventTranslatorTest.php`
  - `testTranslate_RefundRequested_ToMollieRefundRequestEvent`
  - `testTranslate_CaptureRequested_ToMollieCaptureRequestEvent`
  - `testSupports_OnlyMollieProvider`
- `tests/Unit/Service/AdminRequestIdempotencyTest.php`
  - `testRefund_SameContractAmountTwice_SecondIsNoOpAtApi`

**Implementation steps:**
1. `EventSystem/Translator/MollieEventTranslator.php` implements `ProviderEventTranslatorInterface`;
   tag `oe.payment.event_translator`.
2. Pass a deterministic idempotency key `{contract}:{action}[:amount]` into the adapter (Mollie
   idempotency-key header) so replays short-circuit server-side.

**SOLID/Clean check:** SRP: translation only. ISP: 2-method translator interface. DRY: one mapping table.
**DevOps gate:** `phpstan` ✓ · Unit ✓.
**Definition of Done:** Abstract requests reach Mollie concrete handlers; duplicate admin clicks are server-side no-ops.

---

## Story 6 — OxpaidReconciliationService + linked-order updater

**Why:** Self-heal OXPAID when Mollie shows paid but OXPAID is 0000 (parity with Stripe/PayPal); mirror
contract cancel/fail to the OXID order.
**Estimate:** M

**Tests first (TDD):**
- `tests/Unit/Service/OxpaidReconciliationServiceTest.php`
  - `testReconcile_WhenMolliePaidButOxpaidZero_SetsOxpaid`
  - `testReconcile_WhenConsistent_NoOp`
- `tests/Unit/Service/ContractLinkedOrderUpdaterTest.php`
  - `testCancelledContract_MarksOrderCancelled`

**Implementation steps:**
1. `Service/OxpaidReconciliationService.php` (implements payment-base interface) — fetch payment,
   compare, heal. 2. `Service/OxidContractLinkedOrderUpdater.php` mirrors terminal states to oxorder.

**SOLID/Clean check:** SRP each. DIP on repositories. No-else guard on consistency.
**DevOps gate:** `phpstan` ✓ · `phpmd` ✓ · Unit ✓ · `./bin/pre-commit-check.sh --full` ✓.
**Definition of Done:** OXPAID self-heals from API truth; order state mirrors contract terminal transitions.

---

## Suggested order
1. Story 1 (refund) — highest-value, reuses Sprint 5's recorder.
2. Story 3 (cancel) — small, independent.
3. Story 2 (capture) — needs capability detection.
4. Story 4 (events/handlers) — wires services to the event bus.
5. Story 5 (translator + idempotency) — connects payment-base abstract requests.
6. Story 6 (reconciliation) — safety net, do last.
