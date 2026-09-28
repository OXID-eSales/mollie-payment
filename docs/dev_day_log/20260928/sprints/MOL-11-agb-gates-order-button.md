# Sprint MOL-11: "Order now" inactive until the AGB checkbox is ticked (standard checkout + OPC)

**Date:** 2026-09-28
**Ticket:** MOL-11
**Branch:** `b-7.4.x-agb-checkout-opc-MOL-11` (mollie-payment only — no payment-base, OPC or theme change).
**Status:** IN PROGRESS — TDD, e2e-proven; merge only on the product owner's approval.
**Definition of Done:** with `blConfirmAGB` on, the Mollie "Order now" button on the standard order step
(both the classic redirect button and the inline-Components button) is disabled until the shopper ticks the
AGB checkbox, re-disables when it is unticked, and the server-side guard (79c5c81) stays in place. With
`blConfirmAGB` off (no checkbox rendered) the button is active at once. The OPC Mollie footer shows the same
behaviour: its submit stays disabled until the consents are ticked, active at once when no consent is
rendered. All of it proven by Playwright specs that set the flag themselves and restore it.

## Where the button lives today

| Flow | Markup (Mollie's `page/checkout/order.html.twig` override) | Gate today |
|---|---|---|
| Standard, classic redirect | `<button data-controller="mollie-place-order" …>` submits `#orderConfirmAgbBottom` | none client-side; server re-renders `READ_AND_CONFIRM_TERMS` |
| Standard, inline Components | `<button data-action="click->mollie-components#placeOrder">` | none client-side; same server guard |
| Standard, non-Mollie payment | `{{ parent() }}` — Apex's own button | Apex/core behaviour, out of Mollie's scope |
| OPC | `mollie-footer.html.twig` submit, `disabled` in markup | OPC's `checkout-footer-manager` sweeps `#dynamic-footer-content` (`_syncProviderButtonState`, opc-125 rev-60) and keeps every provider button disabled until consents + sections validate; Mollie's `connect()` fights it (`disabled = false`) and `_processCheckout` reads the long-gone `#confirmTermsCheckout` id, so it always posts `confirmTermsAndConditions: true` |

Apex renders the AGB checkbox (`#checkAgbTop`, `page/checkout/inc/agb.html.twig`) inside a separate top form
and mirrors its state into the hidden `ord_agb` fields with `agb.js`; the order button is outside both forms.
The same include renders `#oxdownloadableproductsagreement` / `#oxserviceproductsagreement` when the basket
needs them — core's `validateTermsAndConditions()` requires all of them, so the gate treats every rendered
agreement checkbox alike (one rule, no special case).

## Stories

1. **Red proofs.** e2e `MollieStandard/AgbGatesOrderButton` (AGB on: disabled → check → enabled → uncheck →
   disabled, a forced click never POSTs `fnc=execute`; AGB off: no checkbox, enabled at once) and
   `MollieOpc/AgbGatesFooterButton` (AGB on: disabled until consents ticked, disabled again after unticking;
   AGB off: enabled without consents). Both flip `blConfirmAGB` through the DB and restore it. Integration
   `OrderPageAgbGateTemplateTest` pins the gate controller on both standard buttons.
2. **`mollie-agb-gate` Stimulus controller** (one job: mirror "all agreement checkboxes ticked" into the
   button's `disabled`), registered in `app.js`, attached to both Mollie buttons in the template. Stands
   down when no checkbox is rendered or when the server rendered the button disabled (low order price).
3. **OPC footer parity:** `connect()` no longer enables the button (OPC's gate owns that; only the in-flight
   lock may disable), and `_processCheckout` forwards the real OPC consent checkboxes
   (`input[name=confirmTerms]` / `confirmPrivacy`, missing → true) instead of a dead id.
4. **Regression + docs:** `mollie-standard` and `mollie-opc` suites, gates (phpcs/phpstan/phpmd/unit),
   CHANGELOG, done/report/status.

## Decisions

- Mollie's module gates Mollie's buttons only; the non-Mollie `parent()` button is Apex's and stays as is.
- The gate is client-side UX; the server guard from 79c5c81 remains the safety net (a page without the
  bundle still submits and is bounced by the server).
- No new config switch: the presence of the checkbox is the switch (`blConfirmAGB`, PsLogin, basket).
