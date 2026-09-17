# Report: the panel's "Refunded" figure counted a refund Mollie had canceled

**Date:** 2026-09-17 · **Branch:** `b-7.4.x` (local commit)
**Follows:** [`02-panel-rebuild-after-action.md`](02-panel-rebuild-after-action.md) (observation "outside this fix")

## Symptom

Order 559: the Payment tab showed **11.31** refunded. Mollie held one *canceled* 10.00 refund and
1.31 of pending/refunded ones. The refund bound (already live from Mollie) said 998.69, so the
two figures on the same card did not add up.

## Root cause

The row rendered `PaymentContract::getRefundedAmount()`. That column only ever accumulates
(`addRefundedAmount()`; payment-base offers no way down), and it is written the moment a refund is
*created* — by the admin refund path and by the `refunded` webhook alike. A refund that Mollie
later cancels or fails stays counted for the life of the contract. Nothing in the module listens
for a refund leaving the pending state, and the admin refund path never made the 10.00 disappear
when the operator canceled it in the Mollie dashboard.

## Fix — display from API, audit from DB

The panel already fetches every refund of the payment for its transaction table
(`TransactionHistoryService`). The displayed total is now the sum of exactly those refund rows,
excluding the ones Mollie voided (`CANCELED`, `FAILED`, `EXPIRED` outcomes), so the number and the
table underneath it can never disagree. Pending refunds count — the same stance Mollie's own
`amountRemaining` takes, which keeps "Refunded" and the refund bound consistent
(refunded + bound = captured).

| File | Change |
|---|---|
| `src/Mollie/Admin/RefundedAmountResolver.php` | new, pure: sum of non-voided refund rows; falls back to the local record when there are no rows at all |
| `src/Mollie/Admin/MolliePanelViewDataBuilder.php` | fetches the transaction rows once, feeds both the table and the resolver |
| `services.yaml` | registers the resolver (Admin classes are wired explicitly, not swept) |
| `tests/Unit/Admin/RefundedAmountResolverTest.php` | canceled/failed excluded; captures and the payment row never count; live payment with no refunds → 0.00 even if the local record says 10.00; no rows → local fallback |
| `tests/Unit/Admin/MolliePanelViewDataBuilderTest.php` | the 11.31-vs-1.31 case end to end through `build()` |

**Why the fallback:** `TransactionHistoryService::fetch()` returns `[]` when the *payment* could
not be read. "Mollie unreachable" must not render as "nothing refunded" — the same rule Sprint 11
Story 8 set for the bounds — so that one case shows the local record. Once Mollie answered, Mollie
is the truth: a live payment with no refunds shows 0.00 whatever the contract says.

## Proof

- Unit: 5 new tests red first (class missing), green after. Gates: PHPCS ✓ PHPStan ✓ PHPMD ✓
  Unit **637/637**.
- Live (`mollie-admin-panel-rebuild-after-refund.spec.ts`, order 559): before this fix the row
  read 11.31; after it reads **201.31** = 200.00 + 1.00 + 0.31 (the canceled 10.00 gone; a 200.00
  refund had been made on the order in the meantime), and 1000.00 captured − 201.31 = **798.69**,
  exactly the refund bound on the same card. After this run's 0.59 refund: 201.90 / 798.10.

## Not changed (tickets)

- The contract's `OXREFUNDEDAMOUNT` still carries the canceled 10.00. It is an audit column, and
  correcting it needs either a refund-status webhook path (Mollie sends a *payment* webhook when a
  refund changes state; the processor would have to diff the refund list) or a payment-base API to
  lower the figure. Downstream readers of that column — `isFullyRefunded()`,
  `getRemainingRefundableAmount()` — are not used by this module's panel any more, but other
  consumers of payment-base might rely on them.
- Stripe's panel has the same shape (`contract.getRefundedAmount()` in its view data) but Stripe
  refunds do not linger in a cancelable pending state, so the exposure is smaller there.
