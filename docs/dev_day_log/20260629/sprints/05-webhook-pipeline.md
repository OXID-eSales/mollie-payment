# Sprint 5 — Webhook pipeline

**Goal:** A hardened webhook endpoint that takes Mollie's bare `POST id=tr_xxx`, verifies it by
**re-fetching from the API**, de-duplicates via `oe_payments_idempotency`, and drives the contract
to FULFILLED / FAILED / refunded. This is the source of truth that finalizes orders.
**Definition of Done (sprint-level):** A sandbox `payment.paid` webhook finalizes the order
(OXPAID set, contract FULFILLED); replays are no-ops; guard failures return correct HTTP codes.

## Out of scope
- Admin-initiated capture/refund (Sprint 6) — this sprint handles only *inbound* status changes.
- Chargeback business workflow beyond recording it (full dispute handling is Sprint 8 stretch).

## Risks & unknowns
- **No signature.** Unlike Stripe/PayPal, Mollie webhooks carry no HMAC. Verification = fetch the
  resource by id and trust the API. Risk: spoofed ids. Mitigation: the id is unguessable AND we
  fetch over TLS with our secret key; an attacker can't forge a paid status. Document this clearly.
- **IP allowlist weak.** Mollie publishes no fixed ranges. Keep the guard but default it OFF and
  config-driven, unlike PayPal's always-on allowlist. Log the decision.
- **Webhook before return / multiple deliveries.** Must be fully idempotent on terminal states.

---

## Story 1 — WebhookController + guard chain (HTTPS / payload-size / rate-limit)

**Why:** Defense-in-depth entry point; reject cheap-and-fast before any API round-trip.
**Estimate:** M

**Tests first (TDD):**
- `tests/Unit/Controller/Webhook/WebhookGuardChainTest.php`
  - `testNonHttps_Rejected400`
  - `testOversizePayload_Rejected413`
  - `testRateLimitExceeded_Rejected429`
- `tests/Unit/Controller/Webhook/WebhookControllerTest.php`
  - `testMissingId_Returns400`

**Implementation steps:**
1. `Controller/Webhook/WebhookController.php` (registered in metadata `controllers`).
2. Copy PayPal guard classes: `WebhookHttpsGuard`, `WebhookPayloadSizeGuard` (1MB),
   `WebhookRateLimitGuard` (token bucket), `WebhookGuardChain`. Omit/soft-default the IP guard.
3. Extract `id` from POST; empty -> 400.

**SOLID/Clean check:** SRP: each guard one check. ISP: `WebhookRequestGuardInterface` single method.
No-else: short-circuit chain. DRY: guards copied, not reinvented.
**DevOps gate:** `phpstan` ✓ · `phpmd` ✓ · Unit ✓.
**Definition of Done:** Guard chain returns correct codes; missing id rejected; chain order fixed.

---

## Story 2 — MollieWebhookProcessor: fetch-by-id verification (Template Method)

**Why:** Mollie verification is an API round-trip; centralize it in the `AbstractWebhookProcessor`
template so handlers receive a verified DTO, not a raw request.
**Estimate:** M

**Tests first (TDD):**
- `tests/Unit/Webhook/MollieWebhookProcessorTest.php`
  - `testParseAndValidate_FetchesPaymentByIdFromApi`
  - `testParseAndValidate_WhenApiReturnsNotFound_ReturnsUnverified`
  - `testProcess_RoutesByMappedStatus`

**Implementation steps:**
1. `Webhook/MollieWebhookProcessor.php` extends payment-base `AbstractWebhookProcessor`:
   `parseAndValidateRequest()` calls `MollieWebhookAdapterInterface::fetchByWebhookId($id)` ->
   `MollieWebhookEvent` (verified=true only if fetch succeeded).
2. `Adapter/MollieWebhookEvent.php` implements payment-base `WebhookEvent` (eventId, type from
   `MollieStatusMapper`, paymentId, verified, rawPayload).

**SOLID/Clean check:** SRP: processor verifies+routes. LSP: honors `AbstractWebhookProcessor`
hooks. DIP: depends on `MollieWebhookAdapterInterface`. DRY: status via `MollieStatusMapper`.
**DevOps gate:** `phpstan` ✓ · Unit ✓.
**Definition of Done:** Webhook id is verified by API fetch; unknown/not-found is safely unverified.

---

## Story 3 — Idempotency claim via oe_payments_idempotency

**Why:** Mollie retries on non-2xx and may double-deliver; processing must be exactly-once.
**Estimate:** S

**Tests first (TDD):**
- `tests/Unit/Webhook/WebhookIdempotencyTest.php`
  - `testFirstDelivery_Claims_AndProcesses`
  - `testReplay_SecondDelivery_IsNoOpReturns200Duplicate`
- `tests/Integration/Webhook/DoctrineIdempotencyClaimTest.php`
  - `testClaimEvent_IsAtomic`

**Implementation steps:**
1. Use payment-base `WebhookLogRepositoryInterface::claimEvent($id,'mollie',$type)`; first-seen ->
   process, else -> 200 `duplicate`.
2. `markProcessed`/status update after handling.

**SOLID/Clean check:** DRY: reuse payment-base claim (no own table). No-else: claim-or-return.
**DevOps gate:** `phpstan` ✓ · Unit ✓ · Integration ✓.
**Definition of Done:** Replaying any webhook is a verified no-op; claim is atomic (integration-proven).

---

## Story 4 — Status handlers: paid / failed / expired / canceled

**Why:** Map verified inbound status onto contract transitions and OXPAID; this is what finalizes orders.
**Estimate:** L

**Tests first (TDD):**
- `tests/Unit/Webhook/Handler/PaymentPaidHandlerTest.php`
  - `testPaid_AdvancesContractToFulfilled_AndRecordsTransaction`
  - `testPaid_WhenAlreadyFulfilled_IsNoOp`
- `tests/Unit/Webhook/Handler/PaymentFailedHandlerTest.php`
  - `testFailed_FailsContract`
- `tests/Unit/Webhook/Handler/PaymentExpiredCanceledHandlerTest.php`
  - `testExpired_ExpiresContract` / `testCanceled_CancelsContract`

**Implementation steps:**
1. `Webhook/Handler/PaymentPaidHandler`, `PaymentFailedHandler`, `PaymentExpiredHandler`,
   `PaymentCanceledHandler` implementing a `MollieWebhookEventHandlerInterface`
   (`handledStatuses()` + `handle()`).
2. `Webhook/WebhookContractFulfillmentHandler` — shared: transition ladder, record transaction
   (payment-base `TransactionRepository`), update linked order, set OXPAID. Reuse PayPal's shape.
3. All transitions wrapped try/catch so double-delivery on terminal states doesn't throw.

**SOLID/Clean check:** SRP: one handler per outcome. OCP: new status = new handler, no edit to the
router. DIP: handlers depend on repositories/interfaces. No-else: guard terminal states.
**DevOps gate:** `phpstan` ✓ · `phpmd` ✓ · Unit ✓.
**Definition of Done:** `payment.paid` drives FULFILLED + OXPAID; failed/expired/canceled reach the
right terminal state; all idempotent.

---

## Story 5 — Refund & chargeback inbound handlers

**Why:** Dashboard-initiated refunds and chargebacks must reconcile onto the contract's refunded amount.
**Estimate:** M

**Tests first (TDD):**
- `tests/Unit/Webhook/Handler/PaymentRefundedHandlerTest.php`
  - `testRefunded_AddsRefundedAmountFromApi`
  - `testRefunded_PartialThenFull_Accumulates`
- `tests/Unit/Webhook/Handler/ChargebackCreatedHandlerTest.php`
  - `testChargeback_RecordedOnContract`

**Implementation steps:**
1. `PaymentRefundedHandler` — re-fetch payment, read refunds, `addRefundedAmount` delta-only
   (idempotent vs already-recorded).
2. `ChargebackCreatedHandler` — record chargeback transaction; no auto-reversal (out of scope).

**SOLID/Clean check:** SRP per handler. DRY: refund recording via a shared recorder (mirror Stripe's
`ContractRefundRecorder`) — reused by Sprint 6's admin refund too. No-else.
**DevOps gate:** `phpstan` ✓ · Unit ✓.
**Definition of Done:** External refunds reconcile (delta-only, accumulating); chargebacks recorded.

---

## Story 6 — HTTP response contract + end-to-end webhook integration test

**Why:** Mollie retries on any non-2xx; we must emit 200 only when work completed or safely ignored.
**Estimate:** M

**Tests first (TDD):**
- `tests/Integration/Webhook/WebhookEndToEndTest.php`
  - `testPaidWebhook_FinalizesOrder_Returns200`
  - `testUnknownStatus_Returns200Ignored`
  - `testHandlerThrows_Returns500_SoMollieRetries`
  - `testReplay_Returns200Duplicate_NoDoubleSideEffects`

**Implementation steps:**
1. Map outcomes to codes (200 ok/ignored/duplicate; 400 bad request; 413/429 guards; 500 handler error).
2. Wire all handlers + processor + guards + idempotency in `services.yaml`.

**SOLID/Clean check:** SRP: controller maps result->code only. No-else: outcome match.
**DevOps gate:** `phpstan` ✓ · `phpmd` ✓ · Unit ✓ · Integration ✓ · `./bin/pre-commit-check.sh --full` ✓.
**Definition of Done:** Full inbound pipeline finalizes a sandbox order, returns correct codes, and
is replay-safe end-to-end.

---

## Suggested order
1. Story 2 (processor + webhook event) — the verification core.
2. Story 3 (idempotency) — wrap before any side effects exist.
3. Story 1 (controller + guards) — front door.
4. Story 4 (status handlers) — the finalization logic.
5. Story 5 (refund/chargeback inbound).
6. Story 6 (HTTP contract + E2E) — ties it together; highest confidence last.
