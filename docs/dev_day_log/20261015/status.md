# 2026-10-15

- REVIEW-01 S02–S07 merged into `b-7.4.x` (merge commit `6bbf426`) and pushed, with the feature
  branch `b-7.4.x-REVIEW-01-fixes` pushed for traceability. Post-merge local gates ALL PASSED.
- CI fix (DoD: green GitHub Actions) — the S06 `StockRestorationDbTest` queried the EE-only
  `OXMAPID` column unconditionally; the CE 7.4/7.5/7.6 integration matrices all failed on that one
  test (run 38714317423 and siblings: `Unknown column 'OXMAPID'`). The EE shop-mapping step now
  probes the edition first (`SHOW TABLES LIKE 'oxarticles2shop'`) and is a no-op on CE. Verified
  locally on the EE dev shop (test green, no warnings); CE proof is the rerun of the matrices.
  The red runs on the already-merged feature branch are historical — the fix lands on `b-7.4.x`.
- Security-advisory policy (user directive: **never use any package version with a known security
  advisory — fix forward, never waive**). CVE-2026-109449 (symfony/expression-language DoS,
  published 2026-10-14) resolved everywhere: the 7.5 server gets `6.4.48 as 6.4.32` inline-aliased
  over the metapackage's exact pin (patched code installed, alias label only); the local 7.4 shop
  and this module's own `composer.lock` were plainly bumped to 6.4.48 (`^6.4` range) —
  `composer audit --locked` clean on both, Unit 773 green on both configs after the bump. CI gate:
  a `composer audit --locked --abandoned=report` step in all three test workflows' install job,
  auditing the full resolved shop stack each run (a standalone lock-based workflow was no option —
  the module's composer.lock is gitignored, never tracked; the review's "262 KB committed lock"
  claim was wrong). `--abandoned=report` keeps the gate scoped to the policy; the default exit
  fails on transitively abandoned doctrine/cache.
  - Heads-up: the CE **7.5** metapackage pins the affected version exactly, so the 7.5 workflow
    will fail at `composer update` on any cache-miss install until OXID ships a fixed metapackage —
    that failure is the policy working, upstream's to fix.
