# 02 — Admin Panel Guide

**Admin → Orders → {order} → Payment tab** shows the Mollie panel for any order paid with Mollie
(payment-base's shared "Payment" tab, populated by `MolliePaymentPanelProvider`).

## What you'll see

- The Mollie payment id and status.
- **Refund** — full or partial, on any **fulfilled** (fully paid) order. Amounts accumulate: two
  €20 partial refunds against a €50 order leave €10 refundable, not €30. The remaining refundable
  amount shown is read live from Mollie (not a locally cached total), so it always reflects
  chargebacks too.
- **Capture** and **Cancel authorization** — shown only for two-step methods (card/Klarna with
  `sMollieCaptureMode=manual`) on an authorized-but-not-yet-captured payment. Orders paid with an
  instant method (iDEAL, PayPal, bank transfer, …) on a manual-capture shop arrive already captured
  and show neither button — there is nothing left to capture.

  **Known limitation:** as of this release, the module does not yet drive a two-step payment into
  the "authorized" state through any live path (see
  `docs/architecture/00-overview.md`'s "Known limitation" note) — these two actions are built and
  unit-tested, but will not currently have a real order to act on. Auto-capture
  (`sMollieCaptureMode=automatic`, the default) is unaffected and fully functional.

## Refunding an order

1. Enter an amount (leave blank for "refund everything still refundable") and an optional reason.
2. Submit. The amount is validated against the live Mollie payment's remaining refundable balance
   **before** any API call — an amount that's too large is rejected with a clear message, not a
   failed Mollie API call.
3. The refund is recorded on the contract and a transaction row is written for the order history
   (**Admin → Orders → {order} → Payment tab → transaction history**, via
   `TransactionHistoryService`).

## "Amount exceeds available balance" / "not a valid amount"

Protection against admin typos — the same validator that protects the API call also produces this
message before anything is sent to Mollie. Check the amount shown next to the input for the
current refundable/capturable ceiling.

## Idempotent by design

Clicking Refund/Capture/Cancel twice in quick succession (double-click, page reload after a slow
response) does not double-charge or double-refund: the admin action carries a deterministic
idempotency key derived from the contract id, action, and amount, which Mollie's API
short-circuits server-side on a repeat.
