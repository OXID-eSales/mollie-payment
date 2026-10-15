# 2026-10-15

- REVIEW-01 S02–S07 merged into `b-7.4.x` (merge commit `6bbf426`) and pushed, with the feature
  branch `b-7.4.x-REVIEW-01-fixes` pushed for traceability. Post-merge local gates ALL PASSED.
- CI fix (DoD: green GitHub Actions) — the S06 `StockRestorationDbTest` queried the EE-only
  `OXMAPID` column unconditionally; the CE 7.4/7.5/7.6 integration matrices all failed on that one
  test (run 38714317423 and siblings: `Unknown column 'OXMAPID'`). The EE shop-mapping step now
  probes the edition first (`SHOW TABLES LIKE 'oxarticles2shop'`) and is a no-op on CE. Verified
  locally on the EE dev shop (test green, no warnings); CE proof is the rerun of the matrices.
  The red runs on the already-merged feature branch are historical — the fix lands on `b-7.4.x`.
