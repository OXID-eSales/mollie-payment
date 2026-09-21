# Manual capture is decided per method, not per shop (2026-09-21)

**Branch:** `b-7.4.x-manual-capture-per-method` (from `b-7.4.x`) · **Trigger:** Daniil: with capture
mode *manual* the shopper only gets the methods that can be captured manually; they must see all
methods, instant ones capture automatically, manual capture applies only where it exists.

## What the module did

- `PaymentMethodListService` dropped every method outside `MollieDefinitions::MANUAL_CAPTURE_METHODS`
  when `sMollieCaptureMode=manual` — so the inline selector (standard order page + OPC footer)
  showed "Card" only. The dev shop is in manual mode, which is exactly what the 2026-09-18 probe saw.
- `CheckoutPaymentService` sent the shop's capture mode on every create-payment, regardless of method.

## What Mollie actually does (live, test profile, 2026-09-21)

| Request | Result |
|---|---|
| `method: ideal` + `captureMode: manual` | **422** "At least one of the provided payment methods must support captures" |
| `method: creditcard` + manual | 201, `captureMode: manual` |
| `method: [ideal, creditcard, paypal]` + manual | 201, hosted select-method page lists all three |
| no method + manual | 201, hosted page lists **every** enabled method (iDEAL, bank transfer, PayPal, Bancontact, eps, …) — identical to automatic |
| no method + manual, shopper picks iDEAL | payment comes back `method: ideal`, **`captureMode: null`** — Mollie drops manual capture itself |

So the hosted page never was the restriction; the module's own filter was, and the 422 only bites
when the module *pins* an instant method together with manual capture.

## The fix (TDD)

- **RED** `PaymentMethodListServiceTest::testList_OffersEveryMethodWhateverTheCaptureMode` (replaces
  the filter test) and four `CheckoutPaymentServiceTest` cases: no method → manual passed through;
  card/Klarna → manual; iDEAL/PayPal → automatic; card-token path → manual; automatic shop → automatic.
  2 failures on first run, as intended.
- **GREEN** `PaymentMethodListService`: capture filter removed, `ModuleConfigurationServiceInterface`
  dependency removed (it had no other use; DI is autowired). `CheckoutPaymentService::captureModeFor()`:
  manual only when the shop is manual AND (no method pinned OR `supportsManualCapture($method)`), else
  automatic. `MollieDefinitions` docblocks say so. 31/31 in the three files; gate ALL PASSED (Unit 641/641).
- **E2E** `tests/MollieStandard/ManualCaptureOffersAllMethods.spec.ts` (new, adaptive): inline selector
  offers `ideal, creditcard, banktransfer, paypal, paybybank, bancontact, eps, przelewy24, kbc,
  bancomatpay`; pays with PayPal → thank-you. Mollie API afterwards: PayPal payments `paid`,
  `captureMode: null` (settled automatically); the card payment from `InlineCardComponents` on the same
  manual-mode shop is **`authorized`, `captureMode: manual`, `amountCaptured: 0.00`** — the two-step
  flow is real on this profile (the old "known limitation" note is outdated for cards here).
- **E2E helpers** `selectMolliePaymentMethod` / `continueToOrderReview` are now no-ops when the payment
  step was skipped (single method + single set) — `CheckoutPaysAndFinalizes` and
  `AgbRequiredBlocksCheckout` were timing out on a radio that never renders in this shop state.
  All green: ManualCaptureOffersAllMethods, CheckoutPaysAndFinalizes, AgbRequiredBlocksCheckout,
  InlineCardComponents.

## Not changed

- `MollieReturnResolver::warnIfManualCaptureWasIgnored()` (cards captured despite manual → log) stays.
- Admin *Payment* tab gating on the live `authorized` status stays: instant-method orders arrive
  `paid` and show no capture button, which is the intended outcome.
- Merchant docs updated (`01-setup.md` capture-mode row, `02-admin-panel.md`). CHANGELOG `Unreleased → Fixed`.
