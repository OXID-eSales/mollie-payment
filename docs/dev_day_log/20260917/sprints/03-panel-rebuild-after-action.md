# Sprint 03: Payment tab must show post-action state without a reload

**Date:** 2026-09-17
**Status: DONE.** Full analysis, both root causes and the e2e proof:
[`../reports/02-panel-rebuild-after-action.md`](../reports/02-panel-rebuild-after-action.md)

## Problem
After refund / capture / cancel on the admin Payment tab the refundable amount stays at its
pre-action value until the operator reloads.

## Approach
The request was framed as "update the JS to rebuild the tab". Story 0 checks that premise first:
the action POST already re-renders the whole tab server-side. The proof spec therefore asserts on
the response of the action POST itself and makes no second navigation.

| Principle | Application |
|---|---|
| TDD-first | Red unit tests for each cause and a red Playwright run before any production edit |
| Fix causes, not symptoms | No JS reload added; two server-side defects removed |
| Single source of truth (F21) | Both fixes live in the DTO / snapshot seam; panel, validator and RefundService inherit |
| Absence ≠ zero (F7/F11) | `amountRemaining` nullable like `amountCaptured` |
| DevOps-first | pre-commit gates green; **not pushed** on request |

## Stories
1. **Repro without reload** — `mollie-admin-panel-rebuild-after-refund.spec.ts` (red: bound 1000 → 1000).
2. **Snapshot memo survives the action** — `reset()` on the snapshot provider, wired into the
   existing `resetViewCache()` hook (unit red → green; e2e still red).
3. **Bound blind to pending refunds** — `refundableAmount()` prefers Mollie's `amountRemaining`
   (unit red → green; e2e green: 999.00 → 998.69).

## Out of scope
- The local "Refunded" figure vs Mollie's refund states (see report).
- Repairing the shared e2e page objects for this admin theme.
