# Report — MOL-11: "Order now" inactive until the AGB checkbox is ticked

**Date:** 2026-09-28 · **Branch:** `b-7.4.x-agb-checkout-opc-MOL-11` (mollie-payment) · Plan:
`../sprints/MOL-11-agb-gates-order-button.md` · Done: `../done/MOL-11-agb-gates-order-button.md`

## What was wrong

With `blConfirmAGB` on, Apex renders the AGB checkbox (`#checkAgbTop`) in a form of its own and the Mollie
module renders the "Order now" button outside it, with no link between the two. Both Mollie buttons (classic
redirect, inline Components) were active with the box unticked; a click posted the order form and the server
guard (79c5c81) bounced the shopper back with `READ_AND_CONFIRM_TERMS`. Reproduced by machine: the red run of
`AgbGatesOrderButton` found the inline button enabled with the checkbox unticked.

In the one-page checkout the button was already gated — OPC's `checkout-footer-manager` keeps every provider
button in `#dynamic-footer-content` disabled until consents and sections validate — but the Mollie footer
fought it (`connect()` set `disabled = false`) and posted `confirmTermsAndConditions: true` unconditionally
because it looked up a checkbox id (`#confirmTermsCheckout`) OPC no longer renders.

## What changed

| Piece | Change |
|---|---|
| `resources/js/controllers/mollie_agb_gate_controller.js` (new) | `mollie-agb-gate`: the button is enabled exactly while every agreement checkbox on the page is ticked (`#checkAgbTop`, `#oxdownloadableproductsagreement`, `#oxserviceproductsagreement` — the same set core's `validateTermsAndConditions()` requires). No checkbox → stands down; button rendered disabled by the server (low order price) → stands down. |
| `resources/js/app.js`, `assets/js/*` | controller registered; bundle rebuilt |
| `views/twig/extensions/themes/default/page/checkout/order.html.twig` | both Mollie buttons carry the gate (`data-controller="mollie-place-order mollie-agb-gate"` / `data-controller="mollie-agb-gate"`) |
| `views/twig/widget/checkout/mollie-footer.html.twig` | `connect()` only ever disables (in flight), never enables; `_consentAccepted(name)` reads the real `confirmTerms` / `confirmPrivacy` checkboxes for `processCheckout` (none rendered → true) |
| tests | e2e `MollieStandard/AgbGatesOrderButton`, `MollieOpc/AgbGatesFooterButton`; integration `OrderPageAgbGateTemplateTest`; probes shared under `tests/Integration/Checkout/Probe/`; `shop-db.ts` gains `confirmAgbEnabled()` / `setConfirmAgbEnabled()` |

Non-Mollie payments render Apex's own button (`{{ parent() }}`) and are untouched — that is theme behaviour,
not the module's. The server guard stays: a page without the bundle still submits and is bounced.

## Proof

| Proof | Result |
|---|---|
| `OrderPageAgbGateTemplateTest` (2) + `OrderPageSingleMollieMethodTemplateTest` (2) | red → **green** (4 tests) |
| `AgbGatesOrderButton`, inline Components flow (iframe flag on) | red (button enabled with the box unticked) → **green** (2 tests) |
| `AgbGatesOrderButton`, classic redirect flow (iframe flag off for one run, restored) | **green** (2 tests) |
| `AgbGatesFooterButton` (OPC on) | **green** (2 tests); also green against the original footer — OPC already gated the button, the spec pins it and the footer no longer fights it |
| `mollie-opc` suite | **7 passed, 1 failed**: `CheckoutViaOpcPaysAndFinalizes` expects PayPal in the OPC payment select; `oe_payments_paypal` is inactive in `oxpayments` on this shop (precondition, not code). |
| `mollie-standard` suite | **18 passed, 2 Klarna skips, 1 failed** on the first run: `AgbRequiredBlocksCheckout` (the server-guard spec) clicked the now-inactive button and waited out its timeout — the intended consequence of the gate. The spec now asserts the button is inactive and submits the order form straight from the DOM (what a page without the bundle would do); the server still bounces with `READ_AND_CONFIRM_TERMS`. Re-run of the three AGB specs: **3 passed** → suite fully green. |
| Gates | phpcs (CI form, warnings counted) clean · PHPMD clean · Unit 686 green · PHPStan: 3 findings in `src/` files this branch does not touch (`git diff origin/b-7.4.x -- src/` empty; CI green on `388ab27` with the same sources) — environmental |

## CI

- First push (`d4790a8`): unit + styles green; integration jobs red on all four matrix cells — the shared probe
  classes were `not found`. CI runs the Integration suite with `--bootstrap=/var/www/source/bootstrap.php`,
  whose autoloader does not map the module's `Tests\` namespace (locally the SDK's vendor does).
  `ea44cf0`: `Probe/probes.php` requires the four probe files, both template tests include it.

## Environment notes

- OPC flag flipped on for the OPC runs and restored to off (as found). Iframe flag flipped off for one classic-flow run and restored to on.
- During the first OPC regression `oe_onepage_checkout.yaml` was rewritten from outside this session at 16:17 (flag back to `false`), which made every OPC spec hang on the missing buy-now trigger for the rest of that run; switched on again and re-run clean. Something else is working on this shared shop — check the flag before an OPC run.
- The e2e user's persistent basket had grown to 25 units over the runs; cleared (test data).
- Local PHP-FPM hit `pm.max_children (5)` once during the regression; not reproduced.
