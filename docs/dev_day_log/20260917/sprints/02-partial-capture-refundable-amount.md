# Sprint 02: Refundable amount must be bounded by what was captured

**Date:** 2026-09-17
**Status: DONE.** Report with root cause, fix and verification limits:
[`../reports/01-partial-capture-refundable-amount.md`](../reports/01-partial-capture-refundable-amount.md)

## Problem
A partially captured order (authorized 100.00, captured 60.00) shows 100.00 as refundable on the
admin Payment tab, and the refund service accepts a 100.00 request.

## Engineering requirements
| Principle | Application |
|---|---|
| TDD-first | Red tests at DTO, adapter-mapping, admin-bound and refund-service level before any production edit |
| DRY / single source of truth | The fix lives in `MolliePaymentDto::refundableAmount()` only; panel and refund service inherit it (F21) |
| No ambiguity by default (F7/F11 lessons) | `amountCaptured` is `?float`; absent ≠ 0.00. The money-field inventory test grows by one |
| DIP / ISP | No consumer or interface changes; `MollieValueMapper` gains one pure helper |
| DevOps-first | `pre-commit-check.sh` green before commit; **not pushed** on request |

## Story 1 — `MolliePaymentDto` knows the settled amount
Tests: `PartialCaptureRefundableAmountTest` (new), `MolliePaymentDtoTest` (unchanged, still green).
Implementation: nullable `amountCaptured`, `fromArray` mapping, `settledAmount()` as the refund base.

## Story 2 — the adapter maps it, keeping absence observable
Tests: `PaymentMoneyMappingRegressionTest` (inventory + distinct value + null-stays-null).
Implementation: `MollieValueMapper::toOptionalAmountValue()`, one named argument in `mapPayment()`.

## Story 3 — consumers prove they inherit the bound
Tests: `AdminActionBoundsTest` (panel seam), `RefundServiceTest` (full refund → 60.00; 80.00 rejected).
Implementation: none — that is the point.

## Out of scope
- `capturableAmount()`'s use of `amountRemaining` (see the report's related finding).
- A live check against a partially captured Klarna test payment.
