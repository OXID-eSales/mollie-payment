# Done — MOL-9: the whole Mollie block stays locked until the AGB checkbox is ticked

**Branch:** `b-7.4.x-MOL-9-agb-gates-mollie-block` (mollie-payment). Plan: `../sprints/MOL-9-agb-gates-mollie-block.md`.

## Story 1 — red proofs (TDD)
- Integration `OrderPageAgbGateTemplateTest::testInlineComponentsBlockIsGatedByTheAgbCheckboxAsAWhole`: the gate
  lives on the `mollie-components` wrapper, the method selector and the card fields sit inside one
  `data-mollie-agb-gate-target="region"`, the button is the `button` target and carries no gate of its own.
  Red (no region) → green. `OrderPageSingleMollieMethodTemplateTest` relaxed to a word match on the wrapper's
  `data-controller` (it now carries two controllers).
- e2e `MollieStandard/AgbGatesMollieBlock` (2): AGB on → region `inert` + `.mollie-agb-locked` (opacity < 1), a
  click on the second method does not land, button disabled; tick → region interactive, method pick lands,
  button enabled; untick → locked again. AGB off → no lock, method pick lands. Skips loudly on the classic
  flow (no block) and on a single-method shop (no selector). Red (no region) → green.
- e2e `MollieOpc/AgbGatesFooterBlock` (1): the Mollie footer sits inside an `inert` ancestor (OPC's section
  lock on the payment-execution body) until the consents are ticked, and a method click does not land while
  locked; no inert ancestor once ticked.

## Story 2 — implementation
- `resources/js/controllers/mollie_agb_gate_controller.js`: targets `button` (disabled) and `region`
  (`inert` + class `mollie-agb-locked`); on a lone `<button>` without targets it gates the element itself
  (classic flow, MOL-11 shape). Stand-down rules unchanged (no checkbox; server-disabled button).
- `page/checkout/order.html.twig`: wrapper `data-controller="mollie-components mollie-agb-gate"`, one region
  around the selector (single-method line or radio list) and the card fields, button target, CSS
  `.mollie-agb-locked { opacity: .5; pointer-events: none; }` (dim + pointer fallback without `inert`).
- Bundle rebuilt. No PHP source change, no OPC change.

## Story 3 — regression, gates, docs
- Seven standard specs adapted to the lock (tick AGB before picking a method: InlineCardComponents,
  InlineMethodRedirect, PaypalPendingReturn, ManualCaptureOffersAllMethods, KlarnaOrderData, KlarnaEndToEnd,
  NotOrderableItemShowsClearMessage); `pickRedirectMollieMethod()` picks at DOM level via `pickMollieMethodRadio()`.
- Suites: see `../reports/MOL-9-agb-gates-mollie-block.md`.
- Gates: phpcs (CI form, warnings counted) clean, PHPMD clean, Unit 702 green; PHPStan: the 3 environmental
  findings of 2026-09-28 in untouched files (no PHP changed in this sprint).
- CHANGELOG entry under Unreleased / Added.
