# Done — MOL-11: "Order now" inactive until the AGB checkbox is ticked

**Branch:** `b-7.4.x-agb-checkout-opc-MOL-11` (mollie-payment). Plan: `../sprints/MOL-11-agb-gates-order-button.md`.

## Story 1 — red proofs (TDD)
- `tests/e2e/playwright/tests/MollieStandard/AgbGatesOrderButton.spec.ts` — AGB on: button inactive, a forced
  click never POSTs `fnc=execute`, tick → active, untick → inactive; AGB off: no checkbox, active at once.
  Records which Mollie flow rendered the button (`mollie-flow` annotation). Red before the change (inline
  button enabled with the box unticked), green after, in both flows (iframe flag on → inline Components
  button; flipped off for one run → classic redirect button; flag restored).
- `tests/e2e/playwright/tests/MollieOpc/AgbGatesFooterButton.spec.ts` — AGB on: footer submit inactive until
  the consents are ticked, inactive again after unticking terms; AGB off: no consent rendered, active without
  ticking anything. Green against the original footer too: OPC's `checkout-footer-manager` already sweeps
  `#dynamic-footer-content` — the spec pins that behaviour for Mollie.
- `tests/Integration/Checkout/OrderPageAgbGateTemplateTest.php` — both standard order buttons carry
  `mollie-agb-gate`. Red → green. The template probes moved to `tests/Integration/Checkout/Probe/`
  (shared with `OrderPageSingleMollieMethodTemplateTest`, `inlineCard` switch added).
- `fixtures/shop-db.ts`: `confirmAgbEnabled()` / `setConfirmAgbEnabled()` — the specs set `blConfirmAGB`
  themselves and restore it in `finally`.

## Story 2 — `mollie-agb-gate`
- `resources/js/controllers/mollie_agb_gate_controller.js`: enabled exactly while every agreement checkbox
  Apex renders (`#checkAgbTop`, `#oxdownloadableproductsagreement`, `#oxserviceproductsagreement`) is ticked;
  stands down when none is on the page or the server rendered the button disabled. Registered in `app.js`,
  attached to both Mollie buttons in `page/checkout/order.html.twig`. Bundle rebuilt (`npm run build`,
  `build:dev`).

## Story 3 — OPC footer parity (hardening)
- `mollie-footer.html.twig`: `connect()` only ever disables (in flight), never enables — OPC's gate owns the
  enabled state; `_processCheckout` posts the real `confirmTerms` / `confirmPrivacy` checkbox state
  (`_consentAccepted(name)`), no checkbox → true. The old `#confirmTermsCheckout` lookup always yielded `true`.

## Story 4 — regression, gates, docs
- `AgbRequiredBlocksCheckout` (server guard) adapted: asserts the button is inactive, then submits
  `#orderConfirmAgbBottom` from the DOM past it — the guard it proves is the server's, and the UI path no longer
  reaches the server without consent.
- See `../reports/MOL-11-agb-gates-order-button.md` for the suite results.
- Gates: phpcs (CI form, warnings counted) clean, PHPMD clean, Unit 686 green. PHPStan reports 3 findings in
  `src/` files this branch does not touch (`git diff origin/b-7.4.x -- src/` is empty; CI is green on
  `388ab27` with the same sources) — environmental, left alone.
- CHANGELOG entry under Unreleased / Added.
