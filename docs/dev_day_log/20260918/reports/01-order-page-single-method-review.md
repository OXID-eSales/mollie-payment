# Mollie standard order page — single-method review (2026-09-18)

**Branch:** `b-7.4.x-order-page-single-method` (from `b-7.4.x`) · **Trigger:** Daniil, after the Stripe
Sprint 137 rules landed: "now do the same for the Mollie order page".

## The rules being applied (set on the Stripe order page the same day)

1. In iframe mode nothing is gated behind a Place-Order button — the payment UI is on the page.
2. No stale status text once the payment UI is rendered.
3. payment-base skip flags (one payment method + one delivery set → payment step skipped): on the
   order page the **payment card is not shown**, the **shipping card is shown read-only** (heading +
   carrier, no pencil/form).
4. Corollary Daniil insisted on: **one option is not a choice** — inform, don't offer a selector.

## What the live probe showed (fresh Playwright context, `daniil.oxiddev.de`)

Precondition found first: the **Mollie module was deactivated** in the shop (`oe_payments_mollie.yaml`
`activated: false`), not just its payment row — with it off the order page fell through to core's
"Order now" button and the Mollie template was not in the chain at all. Activated it with
`oe-console oe:module:activate oe_payments_mollie`, set `oxpayments`: mollie active, stripe inactive
(so the customer is offered exactly one method), wiped `var/cache`.

Then, with the payment step skipped straight to `cl=order`:

| Rule | Mollie order page | Verdict |
|---|---|---|
| 1 — nothing gated | `[data-controller="mollie-components"]` visible on load, 4 Mollie Components iframes mounted, no click needed | ✅ already |
| 2 — status text | none exists | ✅ n/a |
| 3 — cards | "Shipping method" heading + "Shipping - Package", no `#orderShipping`, no `#orderPayment`, no payment heading | ✅ inherited — Mollie's template only overrides `checkout_order_next_step_side`, so payment-base's block overrides apply |
| 4 — one option | **"Choose your payment method" + a single radio "Card"** | ❌ fixed below |
| console | 0 errors, 0 page errors | ✅ |

## The fix (TDD)

- **RED:** `tests/Integration/Checkout/OrderPageSingleMollieMethodTemplateTest.php` renders the real
  order template with probe `oView`/`oViewConf` (a context `oViewConf` shadows the global) — one method
  → no `.mollie-methods` selector, `data-mollie-order-method="fixed"`, method named, exactly one
  `mollieMethod` input that is `checked` and `form="orderConfirmAgbBottom"`; two methods → selector
  with two radios. First run: 1 failure (one-method case), as intended.
- **GREEN:** `views/twig/extensions/themes/default/page/checkout/order.html.twig` — `{% if
  mollieMethods|length == 1 %}` renders a hidden, checked, form-associated radio plus a read-only line
  "Payment method: <icon> <name>" (`data-mollie-order-card="method"`); else the selector as before. The
  Components controller keeps working unchanged: it reads `input[name=mollieMethod]:checked`.
- Cache wiped → 2/2 green.

## Verification

| Check | Result |
|---|---|
| `OrderPageSingleMollieMethodTemplateTest` | 2/2 |
| `./bin/pre-commit-check.sh` | ALL PASSED — PHPCS, PHPStan, PHPMD, Unit 637/637 |
| e2e `tests/MollieStandard/OrderPageSingleMethodReadOnly.spec.ts` (new, adaptive) | ✓ 15 s — read-only shipping, no payment card, Mollie block immediate, one method named read-only, hidden field checked, card iframes mounted, 0 console errors |
| e2e `InlineCardComponents.spec.ts` (regression: tokenize → 3DS → finalize) | ✓ |
| e2e `AgbRequiredBlocksCheckout.spec.ts` | ✘ **pre-existing**: its helper waits for the `paymentid=oe_payments_mollie` radio on `cl=payment`, which the skip flags never render in a one-method shop; it would then `skip` anyway ("card-only"). Not touched. |

## Shop state left behind (deliberate, so the Mollie page can be reviewed)

`oe_payments_mollie` module **active**; `oxpayments`: `oe_payments_mollie` **active**,
`oe_payments_stripe_wallet` **inactive** (was: module inactive, mollie row inactive, stripe row active).
Flip the two rows back to review Stripe's single-method page.
