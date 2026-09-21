# OPC footer — manual capture per method, and the one-option rule (2026-09-21)

**Branch:** `b-7.4.x-opc-footer-manual-capture` (from `b-7.4.x`, on top of the merged per-method
capture fix) · **Trigger:** Daniil: "do the same for the OPC footer".

## What the footer already inherits

`MollieCheckoutFooter::getMollieConfig()` takes its method list from `ViewConfig::getMollieMethods()`
— the same `PaymentMethodListService` the standard order page uses — and the chosen `mollieMethod`
rides through OPC's `processCheckout` into `MollieCheckoutSessionHandler` →
`CheckoutPaymentService::buildCreatePaymentRequest()`, where `captureModeFor()` now decides manual
vs automatic per method. So the manual-capture fix reached the footer with **no PHP change**.

## What changed here

- **Footer template** (`views/twig/widget/checkout/mollie-footer.html.twig`): one enabled method is
  named read-only (`data-mollie-order-card="method"`), with a hidden, checked radio that keeps the
  `methodRadio` target so `_selectedMethod()` still resolves it. Two or more → selector as before.
  TDD: `tests/Integration/Widget/MollieFooterSingleMethodTemplateTest.php` (RED → GREEN, 2/2).
- **E2E** (`tests/e2e/playwright`): new `MollieOpc/ManualCaptureOffersAllMethods.spec.ts`; shared
  `openOpcCheckoutModal()` (prefers the minibasket trigger — a comma-joined selector + `.first()`
  picked a per-product buy-now button whose click never opened the modal); shared
  `waitForOpcPaymentState()` / `opcPaymentSectionFolded()` + `OPC_FOLD_SKIP` so the OPC specs skip
  loudly on the OPC defect below instead of failing on it; `BuyNowPaysAndFinalizes` completes the
  payment through the inline footer too (PayPal is offered inline now).
- Deploy note (Daniil): **`oe:module:install extensions/mollie-payment` + `oe:module:activate`** were
  needed for the widget changes to be visible, on top of the `var/cache` wipe.

## Live evidence (daniil.oxiddev.de, manual-capture mode, Mollie the only method, OPC enabled)

The footer loads into the modal with **10 methods**: `ideal, creditcard, banktransfer, paypal,
paybybank, bancontact, eps, przelewy24, kbc, bancomatpay`. Driving it (probe, section unfolded at DOM
level — see below): PayPal → `processCheckout` 200 → Mollie test page → Paid → `cl=thankyou`; Mollie
API: order 589 `paid`, `method: paypal`, `captureMode: null` (settled automatically). 0 console errors.

## Blocker found in OPC (not Mollie) — FIXED in `one-page-checkout` the same day

Fix: `one-page-checkout` branch `b-7.4.x-opc-fold-keeps-payment-execution` (commit `6ad7deb`, CI
green): `SectionGateController._phantomize()` now hides `_phantomTarget()` — the picker region
(`.opc-payment-method-picker`) when the gate shares its host with payment-execution, the host
itself otherwise. With that build the modal shows the Mollie footer with all ten methods and the
submit button; only the picker folds. **Both OPC bundles must be rebuilt** (`npm run build` AND
`npm run build:dev` — debug mode serves `checkout.js`, and Cloudflare caches the versioned URL).

With the fixed OPC: `BuyNowPaysAndFinalizes`, `BuyNowModalRedirectsToMollie`, `InlineFooterWidget`
and `ManualCaptureOffersAllMethods` (OPC) all GREEN live — PayPal chosen in the footer →
`processCheckout` → Mollie → paid → thank-you, no DOM tricks.

### Original finding

With **one** available payment method and payment-base's single-method auto-assign on, OPC's
`PaymentMethodController` broadcasts `oe:section:phantomize` for `payment-method` (OPC-208) and
disables the payment select. In **iframe mode** there is no standalone `payment-method` section: the
picker is rendered **inside** `payment-execution` — the only surface where any provider footer renders
(rev-57 pivot) — and the fold hides that whole `.accordion-item` (`opc-section--phantom`, `hidden`).
Result: the Mollie footer is in the DOM but invisible, and nothing on the modal can start a payment.
Consents ticked, missing requirements 0 — still folded. Reproduced with probes; the OPC specs now skip
with `OPC_FOLD_SKIP` on this condition. Fix belongs in `one-page-checkout` (don't fold
`payment-execution` when the picker lives inside it; fold only the picker).

## E2E facts learned (helpers in `fixtures/shop-helpers.ts`)
- OPC **replacement mode** is on whenever OPC is enabled (`shouldReplaceMinibasket()` =
  `isOnePageCheckoutEnabled()` in v1.6.6x): the product page has NO standard Add-to-Cart, only
  Buy-Now. `addFirstFeaturedProductToBasket()` now clicks the main product's Buy-Now button (adds +
  opens the modal, then Escape) when the classic button is absent.
- Consequently the **MollieStandard specs cannot run while OPC is enabled** — the modal overlays the
  classic checkout (`cl=user` has no "Weiter", the standard order page carries the OPC footer). Run
  them with `oeOnePageCheckoutEnabled: false`, the OPC specs with it `true`.
- Inside the modal the provider region is `inert` until the checkout validates: consents and
  method radios are set at DOM level (`tickOpcConsents()`, `pickMollieMethodRadio()`), then the
  provider submit button is awaited enabled.
- `waitForOpcPaymentState()` → `'select' | 'auto' | 'folded'`; `chooseMollieInOpcModal()` selects
  Mollie only in `'select'` and tolerates OPC's late single-method fold.
- Test orders drain demo stock (`blUseStock` on, no negative stock): "Ocean Eyes" and "Querlenker
  Aluminium" hit 0 after ~10 orders and the basket silently stays empty (`BASKET_EMPTY`). Restocked
  both to 500 on 2026-09-21.

## Environment changes made for this work
- `oe_onepage_checkout.yaml` `oeOnePageCheckoutEnabled` toggled for the runs; left **true** (Daniil
  is reviewing OPC). The standard-checkout specs need it `false`.
- `oxarticles.OXSTOCK` of the two demo products set to 500.
- Mollie module reinstalled (`oe:module:install` + `oe:module:activate`), settings intact
  (`sMollieCaptureMode: manual`, profile id, keys).
