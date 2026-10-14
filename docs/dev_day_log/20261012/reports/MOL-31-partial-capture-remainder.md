# MOL-31 — After a partial capture the remaining authorization can be neither captured nor released: why the expected result is not achievable (report)

**Date:** 2026-10-12 · **Repo:** mollie-payment · read-only analysis + a live reproduction on the dev shop (order 823), nothing changed.

## The ask

Capture mode manual, an authorized Mollie payment, partial capture in the admin Payment tab. Expected: the captured
amount refundable **and** the remaining authorized amount still capturable (or releasable) afterwards. Actual: Refund
appears for the captured amount, Capture and Cancel for the remainder disappear.

## Reproduction (dev shop, 2026-10-12, inline card, manual capture)

Order **823**, 99.50 EUR, Mollie payment `tr_eeMMwDHW9a3oMzowxm3YJ`, before the capture: `authorized`, captured 0.00,
`captureBefore 2026-10-19`. Capture of **40.00** through the Payment tab:

| Render | Capture | Cancel | Refund | "Captured" | Contract |
|---|---|---|---|---|---|
| before | yes (99.50) | yes | no | 0.00 | committed |
| re-render right after the capture | **yes (59.50)** | **yes** | yes, 40.00 | 40.00 | **fulfilled** |
| reload a few seconds later | no | no | yes, 40.00 | 40.00 | fulfilled |

Mollie's payment after the capture: `status paid`, `amountCaptured 40.00`, `captureBefore null` (the authorization is
gone), `amountRemaining 40.00` — which is the **refundable** remainder of the 40.00, not a capturable one. Asked
directly through the module's own key:

| Call | Mollie's answer |
|---|---|
| `POST /payments/{id}/captures` 10.00 (second capture) | 422 **"The pre-authorized payment has a capture marked as final"** |
| `POST /payments/{id}/release-authorization` | 422 **"Finalized payments cannot be reversed"** |
| `DELETE /payments/{id}` (cancel) | 422 "Finalized payments cannot be reversed" |

Shop side: `oxorder` 823 `OXTRANSSTATUS OK`, **`OXPAID` stamped 10:17:47** although 40.00 of 99.50 were captured.

## Why the expected result cannot be reached — Mollie's rule, not a module defect

Mollie, "Place a hold for a payment" (docs.mollie.com/docs/place-a-hold-for-a-payment, read 2026-10-12):

- "For most payment methods a capture can either be for the full amount or for a reduced amount." Riverty and
  Billink only support full captures.
- **Multiple captures** on one authorization: Klarna and Billie **yes**; Riverty, Vipps, MobilePay, Billink **no**;
  **cards (Visa 7 days, Mastercard 30 days, Amex 7 days) and PayPal: "yes, if enabled" — "disabled by default" and
  must be requested from your Mollie contact person.**
- With multi-capture: "When a partial capture succeeds, the remaining authorized amount will still be available to
  capture at a later time"; the payment stays `authorized` until fully captured or the authorization lapses.
- Without it: the first capture is final; "the remaining funds … will be automatically released back to the
  customer" (issuer permitting) and the payment moves to `paid`. "It is not possible to extend the authorization period."
- Releasing: `release-authorization` on an `authorized` payment "will release the full remaining amount"; after a
  successful capture the payment is `paid`, and a finalized payment cannot be reversed (confirmed above).

This merchant's Mollie profile has multi-capture **off** (default): the probe's first capture came back "marked as
final". So after a partial card capture there is, at Mollie, nothing left to capture and nothing left to release — the
remainder was already given back to the customer by Mollie. Points 2–4 of the expected result ("remaining amount
available for subsequent capture", "releasable", "should not disappear") describe multi-capture behaviour that this
account does not have, and no module code can obtain it: every API path answers 422. Point 1 (captured amount
refundable) is met.

The panel's two renders are both "right" for their moment: the re-render asks Mollie within milliseconds of the capture
(still `authorized`, the MOL-30 window) and offers 59.50; the reload sees `paid` and offers nothing. The *disappearing*
the ticket observes is Mollie releasing the remainder.

## What the module gets wrong on top (would also block the expected result once Mollie enables multi-capture)

1. **A partial capture fulfils the contract and stamps OXPAID.** `CaptureService::applyCapture()` (COMMITTED →
   `fulfill()`): order 823 reads paid in full with 40.00 captured. Follow-up noted on 2026-10-01, still open.
2. **A second capture is refused by the module's own state guard.** `CaptureService::assertCapturable()` accepts
   AUTHORIZED/COMMITTED only; after the first capture the contract is FULFILLED → `DomainException` — even on a
   Klarna/Billie payment, where Mollie allows it today. `CancelAuthorizationService` has the same guard.
3. **The re-render offers a capture that cannot happen** (59.50 above) and a click would fail silently: the capture
   handler swallows failures like the refund handler did before MOL-30 (no admin message). With MOL-30's channel this is
   one optional argument away.
4. **No notion of "capture marked as final" / no `release-authorization` support**; the panel shows no "released"
   figure, so the merchant cannot tell that 59.50 went back to the customer.
5. **Merchant docs** (`docs/for_merchant/02-admin-panel.md`) describe Capture/Cancel but not that a partial capture is
   final on a default Mollie account.

## Options

| | What | Achieves the ticket? | Cost |
|---|---|---|---|
| **A. Merchant asks Mollie to enable multi-capture** for cards (and PayPal) on the profile | Mollie keeps the authorization open after a partial capture | yes — **together with** module work: stop fulfilling/OXPAID on a partial capture (fulfil when fully captured or on an explicit decision), allow capture/release while a remainder exists, call `release-authorization`, show `amountRemaining`/`captureBefore`. Not e2e-provable until a multi-capture-enabled test profile exists | M–L (module) + an account change outside the code |
| **B. Honest single-capture behaviour** (today's account) | warn before a partial capture ("the remaining X will be released by Mollie, this capture is final"), require confirmation; after it, no Capture/Cancel on the same render (bound → 0 once the shop recorded a capture and the account is single-capture); show "Released: 59.50"; stop stamping OXPAID for a partial capture (or stamp with the captured amount recorded — product decision); docs | no — it makes the actual behaviour visible and safe instead | S–M |
| **C. Both**, B now, A when the account is ready | — | — | — |

Mollie exposes no API flag for multi-capture; the module can only learn it from the outcome (payment `paid` +
`captureBefore null` after a capture) or from a module setting the merchant sets after Mollie confirmed it. A setting
("Mollie multi-capture enabled, confirmed by Mollie") is the pragmatic switch between A and B behaviour.

## Recommendation

Answer the ticket as "works as Mollie's default account behaves; the expected result needs multi-capture enabled by
Mollie" and plan **B** as the sprint (the remainder's fate must be visible and the shop must not call a partially
captured order paid), with **A** as a follow-up story gated on a multi-capture-enabled profile for the dev shop. Klarna
and Billie, which multi-capture by default, are the only methods on which A can be developed and proven today —
and item 2 above blocks even them.
