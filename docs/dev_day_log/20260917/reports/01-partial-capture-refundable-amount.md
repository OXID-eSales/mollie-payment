# Report: partially captured Mollie payments showed the full authorized amount as refundable

**Date:** 2026-09-17 · **Branch:** `b-7.4.x` (local commit, not pushed)
**Sprint record:** [`../sprints/02-partial-capture-refundable-amount.md`](../sprints/02-partial-capture-refundable-amount.md)

## Symptom

On the admin *Payment* tab of an order paid via a manual-capture Mollie method (Klarna family,
cards with `captureMode: manual`), an order authorized for 100.00 and captured for 60.00 showed
**100.00** as the refundable amount. The refund form's default and `max` were 100.00, and a
"refund everything" click asked Mollie for 100.00 — Mollie rejects that, but only after the click,
and the panel had invited it.

## Root cause

Two things combined, both in the adapter layer:

1. **The formula started from the wrong base.** `MolliePaymentDto::refundableAmount()` was
   `amount − amountRefunded − amountChargedBack`. `amount` is what was *authorized*; refunds can only
   return what was *settled*. For automatic-capture methods the two are equal, which is why this
   never showed up before manual capture was exercised.
2. **The settled amount was not even read.** Mollie reports it as `amountCaptured` on the payment
   resource, but `MollieAdapter::mapPayment()` never mapped it and the DTO had no field for it.
   `PaymentMoneyMappingRegressionTest` (F7) pins "every float on the DTO is mapped", and it was
   green — because the field did not exist yet. The inventory guard catches an *unmapped* field,
   not a *missing* one.

Both consumers of the formula were affected identically, by design (F21 made the DTO the single
source of truth): `AdminActionBounds::refundBound()` (what the panel shows and
`AdminAmountValidator` validates against) and `RefundService::refund()` (the ceiling for the actual
PSP call).

## Fix

| File | Change |
|---|---|
| `src/Mollie/Adapter/Dto/MolliePaymentDto.php` | New `?float $amountCaptured = null` (constructor + `fromArray`). `refundableAmount()` now subtracts from `settledAmount()` = `amountCaptured ?? amount->value` |
| `src/Mollie/Adapter/MollieValueMapper.php` | `toOptionalAmountValue()`: null stays null, otherwise the money object's value |
| `src/Mollie/Adapter/MollieAdapter.php` | `mapPayment()` maps `amountCaptured` through that helper |

### Why nullable, not `0.0`

Mollie sends `amountCaptured` "only when this payment supports captures". Its absence therefore
carries information: the payment settled for its full amount (iDEAL, PayPal, bank transfer, …).
A present `0.00` means the opposite — an authorization nothing has been captured from yet. The
SDK's `getAmountCaptured()` returns `0.0` for both, which would have made every iDEAL order read
as unrefundable. This is the same ambiguity shape F11 fixed for `capturableAmount()`
(`amountRemaining === 0`), resolved the same way: keep the distinction in the type.

| `amountCaptured` | meaning | refundable base |
|---|---|---|
| absent (`null`) | method has no captures; settled in full | `amount` |
| `0.00` | uncaptured authorization | `0.00` → cancel, not refund |
| `60.00` of `100.00` | partial capture | `60.00` |

## TDD trail

RED (all failing on `b-7.4.x` before the change, with the bug's own numbers):

- `tests/Unit/Adapter/Dto/PartialCaptureRefundableAmountTest.php` (new, 7 tests) — the three rows
  of the table above, refunds/chargebacks subtracted from the captured base, never negative,
  `fromArray` maps the field and leaves it null when absent.
- `tests/Unit/Adapter/PaymentMoneyMappingRegressionTest.php` — `amountCaptured` joins the
  distinct-value inventory (60.00); `refundableAmount()` expectation moves from 55.00 to 15.00
  (60 − 5 − 40); new test: an SDK payment without `amountCaptured` maps to `null`, not `0.0`.
- `tests/Unit/Admin/AdminActionBoundsTest.php` — partial capture through the panel seam: 100
  authorized, 60 captured, 10 refunded → bound 50.00 (was 90.00).
- `tests/Unit/Service/RefundServiceTest.php` — full refund on a partially captured payment asks
  Mollie for 60.00; an 80.00 request is rejected although it is within the 100.00 authorized.

GREEN: the three production edits above. No consumer changed — `AdminActionBounds`,
`MolliePanelViewDataBuilder`, the Twig template, `RefundService` and `AdminAmountValidator` all
pick the fix up through the DTO.

## Gates

`./bin/pre-commit-check.sh`: PHPCS ✓ · PHPStan ✓ · PHPMD ✓ · Unit **624/624** (613 before; 11 new).

## Verification limits

- Verified at unit level against the Mollie API contract (field semantics quoted from
  `docs.mollie.com/reference/get-payment`). Not verified against a live partially captured Mollie
  payment in this session — the test-mode shop has none. Suggested manual check before release:
  Klarna test order → capture a partial amount → open the Payment tab → refundable equals the
  captured amount.
- Not pushed, per instruction. CI on `b-7.4.x` still has to confirm the isolated suite; the change
  touches no `*_parent` stub surface, so no fallout is expected.

## Related finding (not changed here)

`capturableAmount()` uses `amountRemaining` as "remaining *capturable*" (F11). Mollie's API
documents `amountRemaining` as "the remaining amount that can be **refunded**". For an
`authorized` payment before any capture the two can coincide, which is why the existing tests pass,
but the documented semantics differ. Worth a ticket: the capturable remainder should probably be
`amount − amountCaptured` for `authorized` payments, now that `amountCaptured` is on the DTO.
