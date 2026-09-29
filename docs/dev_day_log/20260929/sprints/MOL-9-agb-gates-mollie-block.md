# Sprint MOL-9: the whole Mollie block stays locked until the AGB checkbox is ticked

**Date:** 2026-09-29 · **Ticket:** MOL-9 · **Branch:** `b-7.4.x-MOL-9-agb-gates-mollie-block` (mollie-payment only)
**Status:** IN PROGRESS — TDD, e2e-proven; merge on the product owner's word.
**Definition of Done:** with `blConfirmAGB` on and the box unticked, the standard order step's Mollie block — the
payment-method radios AND the inline card fields — is unclickable, unfocusable and dimmed, not only the "Order now"
button (MOL-11); ticking the box unlocks and un-dims it, unticking locks it again. With the box off (no checkbox)
the block is interactive at once. OPC shows the same behaviour (its section lock already makes the
payment-execution body `inert` until the consents validate) — pinned by a spec.

## Design

- `mollie-agb-gate` grows from "one button" to "a block": it now lives on the `mollie-components` wrapper and
  takes targets — `button` (gets `disabled`) and `region` (gets the `inert` attribute + class `mollie-agb-locked`,
  which dims it and drops pointer events as the fallback for browsers without `inert`). Attached to a `<button>`
  without targets (the classic redirect flow) it behaves as before — one controller, two shapes, same rule.
- Template: the method selector (single-method line or radio list) and the card fields move into one
  `<div data-mollie-agb-gate-target="region">`; the button becomes the `button` target.
- Stand-down rules unchanged: no agreement checkbox → nothing locked; button rendered disabled by the server →
  nothing touched.
- No OPC change: `accordion_section_controller` / `section_lock_controller` set `inert` on the payment-execution
  body until validation passes — the Mollie footer (radios, card fields, submit) is inside that body.

## Stories

1. Red: integration `OrderPageAgbGateTemplateTest` (wrapper carries the gate, region + button targets present);
   e2e `MollieStandard/AgbGatesMollieBlock` (AGB on: region inert + dimmed, a radio click changes nothing, card
   fields unreachable; tick → unlocked, radio click works; untick → locked; AGB off: no lock) and
   `MollieOpc/AgbGatesFooterBlock` (footer body inert until consents ticked).
2. Green: controller + template + CSS; bundle rebuilt.
3. Regression `mollie-standard` / `mollie-opc`, gates, CHANGELOG, done/report/status, push, CI.
