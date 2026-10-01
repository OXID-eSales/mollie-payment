# Sprint — Capture option remains available after the payment has been captured (Mollie admin)

**Date:** 2026-10-01 · **Ticket:** "Capture option remains available after Mollie payment has already been captured
(mollie/stripe)" · **Branch:** `b-7.4.x-capture-section-stale-after-capture` (mollie-payment only)
**Status:** IN PROGRESS — TDD, e2e-proven; merge on the product owner's word.

## Finding

The Payment tab gates the Capture section on Mollie's **live** payment: `AdminActionBounds::captureBound()` is the
payment's `capturableAmount()` (status `authorized` → `amountRemaining`), `isAuthorizedHold()` its status. Right after
"Execute capture" Mollie's payment resource still reads `authorized` with the old `amountRemaining` (the capture is
booked, the payment is updated a moment later), so the re-rendered panel offers the same amount again — although
`CaptureService::applyCapture()` already wrote the captured amount onto the contract and the transaction history
shows the CAPTURE. The existing e2e even logs "capture card still shown (Mollie propagation)".

Stripe is not affected: a PaymentIntent capture is synchronous (status `succeeded`, `amount_capturable` 0) and its
provider resets its cache after the action.

## Fix (Mollie)

- `AdminActionBounds::captureBound()` = `max(0, min(Mollie's capturable amount, contract amount − contract captured
  amount))`: what the shop already captured can never be offered again, whatever Mollie's snapshot says; Mollie's figure
  still caps it (never more than Mollie allows).
- `MolliePanelViewDataBuilder`: `isCancellable` requires a positive capture bound as well — an authorization that is
  fully captured locally cannot be cancelled any more.
- "Captured" already reads from the contract and was right.

## Proof

1. Unit: `AdminActionBoundsTest` (full capture → 0 while Mollie still shows the hold; partial → local remainder;
   Mollie's smaller figure wins), `MolliePanelViewDataBuilderTest` (bound 0 + hold → neither capturable nor cancellable).
2. e2e `MollieAdmin/CaptureSectionAfterCapture`: a fresh authorized card order (inline Components, manual capture),
   full capture in the admin → the Capture section and Cancel are gone on the very next render, "Captured" shows the
   amount, the transaction history has the CAPTURE. Red on the pre-change code, green after.
