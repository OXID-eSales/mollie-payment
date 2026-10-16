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
- **Release remaining authorization** (MOL-32) — shown once a payment has been **partially captured**
  and an authorized remainder is still held. It returns that remainder to the customer. See
  "Multiple captures" below for when this appears instead of Cancel.

## Multiple captures (MOL-32)

Whether you can capture an authorization **in several parts** depends on your Mollie account, not on
the module: Klarna and Billie allow it by default; cards and PayPal allow it **only if Mollie has
enabled it for your account** (ask your Mollie contact). Mollie exposes no API flag for this, so the
module can't detect it — you tell it via **Admin → Extensions → Modules → Mollie → Settings →
"Multiple captures enabled on the Mollie account"** (`blMollieMultiCapture`, **off by default**).

**Setting off (default, single-capture account).** The first capture is **final**: if you capture
less than the full amount, Mollie releases the rest back to the customer and the order is settled
with what you captured. This is the safe, honest default — do not turn the setting on unless Mollie
has confirmed multi-capture for your account, or captures will fail.

**Setting on (multi-capture account).** A partial capture leaves the remainder **authorized**:

- The **Captured** figure accumulates and the capturable remainder shrinks with each capture.
- The order is **not** marked paid until the authorization is **fully captured** (or released).
- **Capture** stays available while a remainder is authorized — capture again later.
- **Release remaining authorization** gives the rest back to the customer and settles the order with
  what was captured; the panel then shows a **Released to customer** figure.
- **Cancel** (which voids the *whole* hold) is only offered **before** the first capture — once
  anything is captured, use Release.

A capture or release Mollie refuses (e.g. the authorization lapsed, or "capture marked as final" on a
single-capture account) shows a clear message on the Payment tab and changes nothing.

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
