# Done 06 — Tests for the untested DB-writing services (review C-M1) — 2026-10-14

Branch: `b-7.4.x-REVIEW-01-fixes`.

## Problem

`OxidStockRestorationService` (118 L of refund-path stock restoration mirroring core's
`OrderArticle::storno()`) and `PaymentMethodInstaller` (127 L of activation-time DB writes) had
zero tests; RefundServiceTest only ever proved the stock-restoration *interface mock* was called.

## What was added

- **`Unit/Service/OxidStockRestorationServiceTest`** (5 tests) — branch logic via a new
  `loadOrder()` seam (the inline `oxNew` made the class untestable) and a `RecordingOrderArticle`
  fake: missing order → 0 + no SQL; already-storno'd articles are never restored twice; the fresh
  article gets exactly `updateArticleStock(amount, allowNegativeStock)` plus the storno UPDATE
  with its id; `useStock=false` and `amount=0` storno without touching stock;
  `allowNegativeStock` passes through.
- **`Integration/Service/StockRestorationDbTest`** — the REAL write path: a real oxorder with two
  oxorderarticles over a real oxarticles row; asserts stock +5 on the live row, both OXSTORNO=1,
  second (webhook-retry) run is a no-op, and the order money columns stay untouched (the
  documented no-recalculateOrder invariant). EE find: a bare `oxarticles` insert is invisible to
  the `oxv_` view without an `oxarticles2shop` mapping row — `Article::load()` fails silently in
  `updateArticleStock()` and core then warns on unloaded fields; the fixture writes the mapping.
- **`Integration/Service/PaymentMethodInstallerTest`** — the creation path against a THROWAWAY
  payment id (row values, both language descriptions, one `oxobject2payment` assignment per
  active delivery set), plus the idempotency contract on the live Mollie row (re-activation
  neither duplicates nor clobbers admin edits). `PaymentMethodInstaller` un-finaled — it already
  had protected seams, and `final` + protected seams contradict each other.

## Proof

Unit 765 → **770** green (both configs); Integration 39 → **42** green (3 env skips), zero new
warnings after the EE-mapping fix; `pre-commit-check.sh` ALL CHECKS PASSED.
