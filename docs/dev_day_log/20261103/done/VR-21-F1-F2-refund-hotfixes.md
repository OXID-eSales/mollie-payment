# VR-21 F1 + F2 (Mollie): one refund is one refund; a partial refund keeps the stock

**Branch:** `b-7.4.x-VR-21-mollie-refund-hotfixes` (from `b-7.4.x` @ `5f9fc0e`) · **Date:** 2026-11-03
**Commits:** `9448276` (F1), `47757e6` (F2) · **Status:** pushed, CI running; merge after green.

## F1 — the idempotency key reaches Mollie (review S-H1)

- `MollieAdapter::createRefund/createCapture` call `setIdempotencyKey()` right before the request (the SDK sends it
  with the next request only).
- `MollieEventTranslator` keys: `contract:action:amount:after:<refunded|captured so far>` (`full` for a full capture).
  A repeat of the same action repeats the key; a second refund of the same amount after the first one was booked
  starts from another state and gets its own key — not swallowed by Mollie's idempotency window.
- Red → green: `MollieAdapterTest::testCreateRefund_SendsTheIdempotencyKeyWithTheRequest`,
  `…testCreateCapture_SendsTheIdempotencyKeyWithTheRequest`, `…testWithoutAKeyNoneIsSet`;
  `MollieEventTranslatorTest::testASecondRefundOfTheSameAmountGetsItsOwnKey`,
  `…testASecondPartialCaptureOfTheSameAmountGetsItsOwnKey` + the three key-format tests updated.

## F2 — restock only with the refund that leaves nothing refundable (review S-H2)

- `RefundService`: `restoreStockIfOrderLinked()` runs only when `RefundBound` before the refund minus the refunded
  amount is < 0.005 and the refund is not `failed` / `canceled` (a `pending` refund is the normal start at Mollie).
- Red → green: `RefundServiceTest::testRefund_Partial_KeepsTheStock`,
  `…testRefund_TheRefundThatLeavesNothingRefundable_RestoresTheStock`, `…testRefund_AFullRefundMollieRefusedToRun_KeepsTheStock`.
- Not in this hotfix: honour the shop's `blUseStock` (the restorer is wired with `useStock: true`) — restocking a shop
  that does not track stock is harmless; the line storno on partial refunds was the damage. Follow-up.

## Gates (local)

- Unit (shop context, Mollie classes from the branch): 929 tests, only the 2 `AdminApiIsOptionalTest` cases that spawn a
  fresh PHP process (they load the installed module, not the branch) — environment of this run, not the change.
- Integration: 80 tests, identical to the main checkout (OK, 3 skipped).
- phpcs / phpmd clean. phpstan: 670 errors locally in the **main checkout as well** (local environment); CI runs it.
