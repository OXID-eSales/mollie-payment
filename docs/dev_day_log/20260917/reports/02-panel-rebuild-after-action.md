# Report: the Payment tab kept showing pre-action amounts until the operator reloaded

**Date:** 2026-09-17 · **Branch:** `b-7.4.x` (local commit, not pushed)
**Sprint record:** [`../sprints/03-panel-rebuild-after-action.md`](../sprints/03-panel-rebuild-after-action.md)
**Proof:** `tests/e2e/playwright/tests/admin/mollie-admin-panel-rebuild-after-refund.spec.ts` — green on the
local test-mode shop against order 559 (`tr_96W9HcjzibL5Bk9PAguWJ`, a partially captured card payment).

## Symptom

After a refund (and likewise capture / cancel) on the admin *Payment* tab, the panel that came back
still showed the old refundable amount. Only a manual reload of the tab showed the new one.
`mollie-admin-refund.spec.ts` had been papering over this by re-opening the tab before asserting.

## Was it the JavaScript?

No. Every action form POSTs to `PaymentAdmin::dispatchAction`, and OXID runs `dispatchAction()` and
then `render()` in that same request — the response *is* a full server-side rebuild of the tab. The
shared payment-base script only adds the busy overlay. Red run of the new spec, before any fix,
against the very response of the refund POST:

| field in the action response | before | after refund of 1.00 | fresh? |
|---|---|---|---|
| refunded total (`contract.getRefundedAmount()`, DB) | 10.00 | 11.00 | yes |
| transaction history (live refund list from Mollie) | 1 row | 2 rows | yes |
| **refundable bound** (`AdminActionBounds::refundBound()`) | 1000.00 | **1000.00** | **no** |

Two server-side causes, both needed fixing.

## Cause 1 — the memoized Mollie payment survived the action

Sprint 136 made the live payment a per-request memo (`MolliePaymentSnapshotProvider`) so a render
costs one Mollie round trip. The action handler validates the requested amount against
`refundBound()` **before** acting — that read fills the memo — and the same request then re-renders
from the same memo. `MolliePanelViewDataBuilder::resetViewCache()`, which the provider dutifully
called after every action, was a documented no-op from Sprint 9 ("Mollie caches nothing") and was
never revisited when the memo arrived.

**Fix:** `MolliePaymentSnapshotProviderInterface::reset()`; `resetViewCache()` calls it. The
provider code is unchanged — it already called the hook after every successful capture, refund and
cancel, and its tests already pinned that.

## Cause 2 — the formula only saw *settled* refunds

Even with a fresh read, the bound would not have moved. Live Mollie state right after the refund:

```
amountRefunded  = 0.00     (the 1.00 refund is still `pending`)
amountRemaining = 999.00   (Mollie's "remaining amount that can be refunded")
amountCaptured  = 1000.00
```

`refundableAmount()` computed `captured − amountRefunded − amountChargedBack` = 1000.00: correct
for settled refunds, blind to pending ones. Mollie's `amountRemaining` is by definition the
refundable remainder and already nets out pending refunds. Report 01 flagged this field's semantics
as a follow-up; the live data made it the fix.

**Fix:** `amountRemaining` becomes nullable on the DTO (Mollie sends it "only when refunds are
available for this payment", so absence must stay observable — same F7/F11 lesson as
`amountCaptured`), the adapter maps it through `toOptionalAmountValue()`, and `refundableAmount()`
returns it when present. The captured-based arithmetic from report 01 stays as the fallback.
`capturableAmount()` treats `null` exactly as it treated `0.0`, so its F11 behaviour is unchanged.

## Files

| File | Change |
|---|---|
| `src/Mollie/Admin/MolliePaymentSnapshotProviderInterface.php` / `MolliePaymentSnapshotProvider.php` | `reset()` |
| `src/Mollie/Admin/MolliePanelViewDataBuilder.php` | `resetViewCache()` resets the snapshot memo; PHPDoc tells the story |
| `src/Mollie/Adapter/Dto/MolliePaymentDto.php` | `?float $amountRemaining = null`; `refundableAmount()` prefers it |
| `src/Mollie/Adapter/MollieAdapter.php` | nullable mapping of `amountRemaining` |
| `views/twig/admin/panel/mollie_panel.html.twig` | `data-testid="refund-bound"` on the displayed bound (test hook only) |
| `tests/Unit/Admin/MolliePaymentSnapshotProviderTest.php` | reset → next read hits Mollie again |
| `tests/Unit/Admin/MolliePanelViewDataBuilderTest.php` | `resetViewCache()` resets the memo; same-request validate → act → reset → render shows the post-action bound |
| `tests/Unit/Adapter/Dto/RefundableAmountFollowsMollieRemainingTest.php` | new: remaining wins, 0.00 remaining = nothing left, fallback without it, nullable `fromArray` |
| `tests/Unit/Adapter/PaymentMoneyMappingRegressionTest.php` | remaining wins (7.00); absent remaining maps to `null` and falls back to 15.00 |
| `tests/e2e/playwright/tests/admin/mollie-admin-panel-rebuild-after-refund.spec.ts` | new: the no-reload proof |

## TDD trail

1. RED unit: `reset()` undefined; same-request rebuild showed 100.0 instead of 75.0.
2. RED e2e: the table above (bound stuck at 1000.00 while the neighbouring fields moved).
3. GREEN for cause 1 alone → e2e still red at 1000.00: fresh read, same blind formula.
4. RED unit for cause 2: 1000.0 vs 999.0; `fromArray` gave 0.0 instead of `null`.
5. GREEN: e2e `before 999.00 → after 998.69` for a 0.31 refund, read from the POST response.

## Gates

`./bin/pre-commit-check.sh`: PHPCS ✓ · PHPStan ✓ · PHPMD ✓ · Unit **632/632** (624 before; 8 new).

## Running the proof

```bash
cd tests/e2e/playwright
MOLLIE_E2E_ORDER_NUMBER=559 npx playwright test tests/admin/mollie-admin-panel-rebuild-after-refund.spec.ts --project=mollie-all
```
It needs a fulfilled, refundable Mollie test-mode order and refunds a run-unique amount below 1.00
each time (equal amounts on the same order are deduplicated by the refund idempotency key — that
is what made one red run look like "nothing happened"). The shop redirects the admin login to its
configured URL, so the spec navigates from the frames it lands in rather than from `SHOP_URL`.

## Observations outside this fix

- The local contract on order 559 records 11.31 refunded while Mollie holds one *canceled* 10.00
  refund plus the pending ones — the panel's "Refunded" row is the local figure and therefore
  overstates. Worth a ticket: mirror Mollie's refund states into the contract, or display Mollie's
  `amountRefunded` next to it.
- `AdminOrdersPage.openPaymentTab()` / `navigateToOrders()` in the e2e page objects do not work on
  this admin theme (sidebar labels, `editThis()` selection). The new spec bypasses them.
