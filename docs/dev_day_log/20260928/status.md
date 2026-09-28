# 2026-09-28

- Sprint MOL-11 ("Order now" inactive until the AGB checkbox is ticked — standard checkout + OPC) — **DONE**, branch
  `b-7.4.x-agb-checkout-opc-MOL-11`. Plan `sprints/MOL-11-agb-gates-order-button.md`; `done/MOL-11-agb-gates-order-button.md`;
  `reports/MOL-11-agb-gates-order-button.md`.
  - Standard order step: new `mollie-agb-gate` Stimulus controller on both Mollie buttons (classic redirect, inline
    Components) — enabled exactly while every agreement checkbox Apex renders is ticked; no checkbox (`blConfirmAGB`
    off) → active at once; server-rendered `disabled` respected. Server guard (79c5c81) stays the safety net.
  - OPC: OPC's `checkout-footer-manager` already gated the footer button; the Mollie footer no longer enables itself on
    connect and posts the real `confirmTerms` / `confirmPrivacy` state (the old `#confirmTermsCheckout` id never
    existed → always `true`).
  - Proof (TDD): e2e `MollieStandard/AgbGatesOrderButton` (red → green, both flows), `MollieOpc/AgbGatesFooterButton`
    (green, also against the original footer), integration `OrderPageAgbGateTemplateTest` (red → green); the
    server-guard spec `AgbRequiredBlocksCheckout` now submits the form past the inactive button. `mollie-standard`
    18 + 3 green / 2 Klarna skips; `mollie-opc` 7 green, 1 precondition failure (PayPal inactive in `oxpayments`).
  - CI: first push red on the integration jobs (probe classes not autoloadable under the shop bootstrap), fixed
    with an explicit loader (`ea44cf0`).
  - Env: OPC yaml was rewritten from outside the session mid-run (flag → false) — re-enabled, re-run, restored to off.
    PHPStan shows 3 environmental findings in untouched `src/` (CI green on the same sources).
