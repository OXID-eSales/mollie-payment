# Done — Capture option remained available after the payment was captured (Mollie admin)

**Branch:** `b-7.4.x-capture-section-stale-after-capture` (mollie-payment). Plan: `../sprints/capture-section-stale-after-capture.md`.

- Red → green unit: `AdminActionBoundsTest` +3 (full capture → bound 0 while Mollie still shows the hold; partial →
  local remainder; Mollie's smaller figure wins; the existing live-bound test now stubs the contract amount),
  `MolliePanelViewDataBuilderTest` +1 (bound 0 + hold → neither capturable nor cancellable). Unit suite 714 green.
- Red → green e2e `MollieAdmin/CaptureSectionAfterCapture`: fresh authorized card order (new fixture
  `fixtures/mollie-inline-card.ts`, extracted from the inline-card spec: fills the Components iframes, authorizes on
  Mollie's test page), full capture on the Payment tab → red before (Capture section still rendered with the same
  amount, "Captured" and the CAPTURE row already correct), green after (no Capture, no Cancel, "Captured" = amount).
- Fix: `AdminActionBounds::captureBound()` = `max(0, min(live capturable, contract amount − captured))`;
  `MolliePanelViewDataBuilder` `isCancellable = hold && bound > 0`.
- Collateral: `MollieAdmin/AdminRefundFlow.spec.ts` (first run under the `mollie-admin` project) ticked no AGB box
  and timed out on the locked button since MOL-11/MOL-9 — it now ticks first and picks a redirect method; its
  steps 2–3 still skip on their own order-number precondition (pre-existing).
- Gates: phpcs (CI form) clean, PHPMD clean, PHPStan the 3 environmental findings in untouched files.
- Stripe (named in the ticket title) is not affected: PaymentIntent capture is synchronous and its provider resets its
  cache after the action.
