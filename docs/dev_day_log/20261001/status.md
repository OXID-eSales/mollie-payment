# 2026-10-01

- Sprint MOL-10 follow-up (shared contract-state Help in payment-base; Mollie and Stripe add their column; "?" popup
  on the order Payment tab) — **IMPLEMENTED** in three repos, branch `b-7.4.x-MOL-10-contract-state-help` each,
  pushed; merge order payment-base → mollie-payment / stripe (both CIs pin payment-base `b-7.4.x`). Plan + proof:
  `sprints/MOL-10-shared-contract-state-help.md`; payment-base `docs/dev_log/20261001/sprints/sprint-14-…`.
  - Proof: unit red → green in all three (pb 1392, Mollie 710, Stripe 1587); admin e2e `ConfigHelpSection` (2) and
    `SharedContractStateHelp` (3) green on the shop running all three modules.
- Sprint "Capture option remains available after the payment was captured" (Mollie admin Payment tab) — **IMPLEMENTED**,
  branch `b-7.4.x-capture-section-stale-after-capture`, pushed; CI pending, merge on the product owner's word.
  Plan `sprints/capture-section-stale-after-capture.md`; `done/…`; `reports/…`.
  - Cause: the Capture section was gated on Mollie's live payment, which still reads `authorized` with the old
    remaining amount right after a capture. Fix: capture bound capped by the contract's uncaptured remainder; Cancel
    follows the bound. Red → green unit (+4) and e2e `MollieAdmin/CaptureSectionAfterCapture` (new inline-card fixture).
  - Stripe not affected (synchronous capture, cache reset).
- Sprint "Order page: Mollie method list as a card, card-number placeholder, card holder (optional?)" — **IMPLEMENTED**,
  branch `b-7.4.x-order-page-method-card`, pushed; CI pending, merge on the product owner's word. Plan
  `sprints/order-page-method-card.md`; `done/…`; `reports/…`.
  - Method list + card fields are a `card` with the order sections' header classes, same layer as Summary/AGB;
    `0000 0000 0000 0000` placeholder (shop-side; Mollie has no placeholder-text option), digits grouped by Mollie
    itself. Card holder NOT labelled optional: Mollie refuses to tokenize without it (measured). e2e
    `MollieStandard/OrderPageMethodCard` + 4 regression specs green.
