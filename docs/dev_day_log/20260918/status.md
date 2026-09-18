# 2026-09-18

- Order page single-method review — DONE. See `reports/01-order-page-single-method-review.md`.
  - Rules 1–3 from the Stripe Sprint 137 review already hold on the Mollie order page (nothing
    gated, no status text, read-only shipping card / no payment card inherited from payment-base).
  - Rule 4 applied: one enabled Mollie method is named read-only instead of a one-radio selector.
    Template test RED→GREEN (2/2); new adaptive e2e `OrderPageSingleMethodReadOnly` green live;
    `InlineCardComponents` regression green. Gate ALL PASSED (Unit 637/637). CHANGELOG `Unreleased → Fixed`.
  - Pre-existing: `AgbRequiredBlocksCheckout.spec.ts` cannot run when the payment step is skipped.
  - Shop state changed for the review: Mollie module + payment row active, Stripe row inactive.
