# Report — MOL-9: the whole Mollie block stays locked until the AGB checkbox is ticked

**Date:** 2026-09-29 · **Branch:** `b-7.4.x-MOL-9-agb-gates-mollie-block` (mollie-payment) · Plan:
`../sprints/MOL-9-agb-gates-mollie-block.md` · Done: `../done/MOL-9-agb-gates-mollie-block.md`

## Ask

With the AGB required and unticked, not only "Order now" (MOL-11) but the whole Mollie block — the payment-method
selection and the inline card fields — must be unclickable, and only become usable ("unblended") once the box is
ticked.

## What changed

- The `mollie-agb-gate` Stimulus controller moved from the button to the inline block. It now has two targets:
  `region` (the method selector and the card fields) and `button`. While any agreement checkbox is unticked the
  region carries the `inert` attribute — no pointer, no keyboard, no focus, the Mollie card iframes included —
  plus the class `mollie-agb-locked` (opacity .5, `pointer-events: none` as the fallback for browsers without
  `inert`), and the button is disabled. Ticking removes all of it; unticking puts it back.
- The classic redirect flow keeps its MOL-11 shape: the gate on a lone button gates that button.
- `inert` does not affect form submission, so the checked method radio still rides with the order form.
- One-page checkout: nothing to change — OPC's section lock sets `inert` on the payment-execution accordion body
  until the consents (and the other requirements) validate, and the Mollie footer renders inside that body. A
  spec now pins that for Mollie.

## Proof

| Proof | Result |
|---|---|
| `OrderPageAgbGateTemplateTest` (block shape) + `OrderPageSingleMollieMethodTemplateTest` | red → **green** (4 tests) |
| `MollieStandard/AgbGatesMollieBlock` — inline flow (iframe flag on, ≥ 2 methods) | red (no region) → **green** (2) |
| `MollieStandard/AgbGatesOrderButton` (MOL-11) — inline flow | **green** (2) |
| Classic flow (iframe flag off for one run, restored) | `AgbGatesOrderButton` **green** (2) — the lone-button shape still gates; `AgbGatesMollieBlock` skips loudly (no block to lock) |
| `MollieOpc/AgbGatesFooterBlock` | **green** (1) — the footer sits inside OPC's inert payment-execution body until the consents are ticked; a method click does not land while locked |
| `mollie-opc` suite | **9 passed, 1 failed** — `CheckoutViaOpcPaysAndFinalizes` expects PayPal in the OPC payment select; `oe_payments_paypal` is inactive in `oxpayments` on this shop (precondition, same as the two previous sprints) |
| `mollie-standard` suite | first run **16 passed, 7 failed, 2 Klarna skips**: every failure was a `check()` on a Mollie method radio picked BEFORE the AGB tick — the lock working as asked. Seven specs now tick the AGB box first (the real shopper order) and `pickRedirectMollieMethod()` picks at DOM level (the server-guard spec keeps the box unticked); re-run of the seven: **7 passed, 1 Klarna skip**; final full run **22 passed, 1 failed, 2 Klarna skips** — the failure was this sprint's own `AgbGatesMollieBlock`, a forced click on `#checkAgbTop` that "did not change its state" (1 of 5 runs). Both AGB gate specs now tick the box through its label with a state check (`setAgbChecked()`), re-run twice each: **8 passed** |
| Gates | phpcs (CI form) clean · PHPMD clean · Unit 702 · PHPStan: 3 environmental findings, no PHP changed |

## CI

- `4c12e29`: Mollie full tests OXID CE 7.4 / 7.5 and Secret scan **green**. The follow-up commit changes e2e specs and
  docs only.

## Notes

- Adapting the specs is part of the feature: a Playwright `check()` on an `inert` radio either times out or
  reports "did not change its state" — exactly what a shopper's click does. Real-shopper specs tick the AGB box
  first; `AgbRequiredBlocksCheckout` (server guard, box unticked on purpose) picks the method at DOM level.

- Playwright's actionability check treats an `inert` region as not receiving pointer events, so the specs prove
  "unclickable" by attempting the click with a 2 s timeout and asserting the radio's state did not change.
- Stand-down rules are unchanged: no agreement checkbox on the page → nothing locked; a button the server rendered
  disabled (low order price) → nothing touched.
