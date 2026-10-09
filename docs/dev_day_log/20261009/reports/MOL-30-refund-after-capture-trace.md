# MOL-30 — Refund option appears only after a reload following a manual capture (trace)

**Date:** 2026-10-09 · **Repo:** mollie-payment (payment-base hosts the tab controller) · read-only analysis, nothing changed.

## Symptom (ticket)

Capture mode manual, order with a capturable Mollie payment. Admin → order → Payment tab → "Execute capture" succeeds,
but the re-rendered tab shows no Refund section. A manual reload a moment later shows it.

## Request flow (no redirect — the panel is rebuilt in the capture request)

- Tab = payment-base `PaymentAdminController` (`cl=PaymentAdmin`); the form posts `fnc=dispatchAction`, which runs
  `PaymentAdminActionDispatcher::dispatch()` → `MolliePaymentPanelProvider::handleAction()` → `handleCapture()`
  (`src/Mollie/Admin/MolliePaymentPanelProvider.php:123-139`): validate → `OrderActionDispatcher::capture()` (event
  `CaptureRequestedEvent` → `MollieCaptureRequestHandler` → `CaptureService::capture()`) → `resetViewCache()`.
- OXID then calls `render()` in the same request → `MolliePanelViewDataBuilder::build()` with a fresh contract
  (`findByOrderId`) and a fresh `GET /payments/{id}` (the snapshot cache was reset; `LazyMollieAdapter::getPayment()`
  has no cache). The cache fix of 2026-09-17 holds; it is not the cause.

## The Refund gate

`MolliePanelViewDataBuilder` line 68: `'isRefundable' => $contract->getState()->isFulfilled() && $refundBound > 0.0`.

- (a) **state fulfilled** — on the usual manual-capture path the return leg has committed the contract, and
  `CaptureService::applyCapture()` (`src/Mollie/Service/CaptureService.php:121-139`) sets the captured amount, saves and
  calls `ContractFulfillmentService::fulfill()` → **FULFILLED in the capture request** (OXPAID stamped). True already.
  (On the AUTHORIZED → READY_TO_COMMIT path only the later `paid` webhook fulfils; see below.)
- (b) **refund bound > 0** — `AdminActionBounds::refundBound()` (`src/Mollie/Admin/AdminActionBounds.php:51-54`) is
  **purely live**: `snapshot($contract)?->refundableAmount() ?? 0.0`. `MolliePaymentDto::refundableAmount()`
  (`Dto/MolliePaymentDto.php:108-120`) = `amountRemaining` when Mollie sends one, else `amountCaptured − refunded −
  charged back`. Right after `POST /payments/{id}/captures` Mollie's payment still reads `authorized`,
  `amountCaptured 0.00`, no refundable remainder (live fact: `docs/dev_day_log/20260921/reports/01-manual-capture-per-method.md:41`)
  → bound **0.00** → no Refund section. Seconds later Mollie flips the payment to `paid` with the captured figure →
  bound > 0 → the reload shows the section.

Compare `captureBound()` (lines 43-49) since 2026-10-01: `min(live capturable, contract amount − contract captured)` —
the contract's own figures win over Mollie's lagging snapshot. `refundBound()` never got that treatment.

## What the `paid` webhook adds

`WebhookContractFulfillmentHandler::doPaymentPaid()` (lines 115-153): for a FULFILLED contract → `NoOp`. It supplies
nothing the gate reads on the usual path; the reload works only because Mollie's live payment has moved on. On the
AUTHORIZED → READY_TO_COMMIT path the webhook advances to COMMITTED, records the captured amount if missing, fulfils.

## Related limit

`RefundService::refund()` (`src/Mollie/Service/RefundService.php:58-66`) caps the amount by the same live
`refundableAmount()`. Showing the form earlier without touching this limit would let the admin submit a refund that
the service refuses with "exceeds refundable" while Mollie is still settling the capture.

## Stripe

Not affected: PaymentIntent capture is synchronous (`succeeded`, `amount_capturable 0`), the provider resets its
cache after the action and the fresh read is final (`20261001/reports/capture-section-stale-after-capture.md`).

## Existing tests around the gate

Unit: `AdminActionBoundsTest` (refund bound = live figure; capture bound after local capture — the 10-01 tests),
`MolliePanelViewDataBuilderTest` (`FulfilledContractWithRefundBalance_IsRefundable`; nothing for "fulfilled + live still
authorized + captured locally"), `PartialCaptureRefundableAmountTest` (captured 0.00 → nothing refundable),
`CaptureServiceTest` (committed → captures and fulfils). e2e: `MollieAdmin/CaptureSectionAfterCapture.spec.ts`
(asserts Capture/Cancel gone and "Captured" after a full capture — **does not assert the Refund form**),
`admin/mollie-admin-refund.spec.ts:89-109` waits 3 s and re-checks the Refund button (papers over this gap),
`MollieDiagnostic/RefundAvailabilityMatrix.spec.ts` (diagnostic, no assertions). Fixture for a fresh capturable order:
`fixtures/mollie-inline-card.ts::checkoutAuthorizedByInlineCard()`; `fixtures/mollie-api.ts::fetchMolliePayment()`
exposes `status`, `amountRemaining`, `amountCaptured` to measure Mollie's propagation.
