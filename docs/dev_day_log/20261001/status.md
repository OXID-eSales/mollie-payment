# 2026-10-01

- Sprint MOL-10 follow-up (shared contract-state Help in payment-base; Mollie and Stripe add their column; "?" popup
  on the order Payment tab) — **IMPLEMENTED** in three repos, branch `b-7.4.x-MOL-10-contract-state-help` each,
  pushed; merge order payment-base → mollie-payment / stripe (both CIs pin payment-base `b-7.4.x`). Plan + proof:
  `sprints/MOL-10-shared-contract-state-help.md`; payment-base `docs/dev_log/20261001/sprints/sprint-14-…`.
  - Proof: unit red → green in all three (pb 1392, Mollie 710, Stripe 1587); admin e2e `ConfigHelpSection` (2) and
    `SharedContractStateHelp` (3) green on the shop running all three modules.
