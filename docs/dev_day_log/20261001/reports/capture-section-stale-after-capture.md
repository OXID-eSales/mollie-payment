# Report — Capture option remains available after the payment has been captured

**Date:** 2026-10-01 · **Branch:** `b-7.4.x-capture-section-stale-after-capture` (mollie-payment) · Plan:
`../sprints/capture-section-stale-after-capture.md` · Done: `../done/capture-section-stale-after-capture.md`

## Reproduced by machine

Fresh card order in manual-capture mode (inline Components, authorized on Mollie's test page) → admin Payment tab →
full capture → re-rendered panel: "Captured" shows the amount, the transaction history has the CAPTURE, and the
Capture section is still rendered offering the same amount (e2e red on the pre-change code, exactly the ticket).

## Why

`AdminActionBounds::captureBound()` returned Mollie's live `capturableAmount()` and `isAuthorizedHold()` its status.
Mollie books the capture and updates the payment resource a moment later; right after "Execute capture" it still reads
`authorized` with the old `amountRemaining`. Meanwhile `CaptureService::applyCapture()` had already written the captured
amount onto the contract — the panel asked the slower source only.

## Fix

- Capture bound = `max(0, min(Mollie's capturable amount, contract amount − contract captured amount))`. What the shop
  captured is never offered again; Mollie's figure still caps it (a partial capture leaves the local remainder, never
  more than Mollie allows).
- Cancel requires a positive bound too: a fully captured authorization cannot be cancelled.
- "Captured" already read from the contract.

## Proof

| Proof | Result |
|---|---|
| `AdminActionBoundsTest` +3, `MolliePanelViewDataBuilderTest` +1 | red → **green**; Unit **714** |
| e2e `MollieAdmin/CaptureSectionAfterCapture` | red (Capture section still offered) → **green** (no Capture, no Cancel, Captured = amount, CAPTURE in history) |
| `mollie-admin` project | all green except `AdminRefundFlow` steps 2–3 skipping on their own precondition; its checkout steps adapted to the AGB lock and green |
| Gates | phpcs (CI form) clean · PHPMD clean · PHPStan: 3 environmental findings, none new |

## Stripe

Not affected: a Stripe PaymentIntent capture is synchronous (status `succeeded`, `amount_capturable` 0) and
`StripePaymentPanelProvider` resets the view cache after the action, so the re-rendered panel reads the final state.
