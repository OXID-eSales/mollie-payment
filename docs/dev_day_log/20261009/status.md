# 2026-10-09

- Sprint MOL-30 — Refund option available right after a successful manual capture (Mollie admin Payment tab) —
  **PLANNED, awaiting approval** (nothing implemented). Plan `sprints/MOL-30-refund-after-capture-without-reload.md`,
  trace `reports/MOL-30-refund-after-capture-trace.md`.
  - Cause: `isRefundable` needs contract `fulfilled` (already true after the capture) **and** a positive refund bound,
    but `AdminActionBounds::refundBound()` reads only Mollie's live payment, which still says `authorized` / captured
    0.00 for a few seconds after the capture. Mirror of the 2026-10-01 Capture-section lag; that fix covered the
    capture bound only. `RefundService` caps by the same live figure.
  - Plan: red unit + red e2e assertion + measured lag (S1) → refund bound trusts the contract, lowered by Mollie's
    figure once settled (S2) → refund inside the settling window gets a plain message, not "exceeds refundable" (S3) →
    UI proof, gates, docs (S4). Mollie only; Stripe unaffected (synchronous capture).
