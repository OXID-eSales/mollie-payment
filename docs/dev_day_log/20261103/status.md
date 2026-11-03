# Status — 2026-11-03 (violations review, epic VR — Mollie part)

Plan and reports: payment-base `docs/dev_log/20261030/` (sprints 16–25, `reports/violations-review-mollie.md`,
`reports/deadcode-check-mollie.md`). payment-base VR-16…VR-22 merged into `b-7.4.x` at `80ae467` (green).

| Story | Branch | Status |
|---|---|---|
| F1 idempotency key reaches Mollie | `b-7.4.x-VR-21-mollie-refund-hotfixes` | MERGED into `b-7.4.x` |
| F2 restock only on the refund that leaves nothing refundable | same | MERGED into `b-7.4.x` |
| F6 refunds booked once through payment-base's ledger | `b-7.4.x-VR-21-mollie-refund-ledger` | MERGED into `b-7.4.x` |
| DC-M1/M2 dead code | `b-7.4.x-VR-17-mollie-dead-code` | MERGED into `b-7.4.x` (`dbcbbc7`) |
| R6 `paid` commits through payment-base's ContractCommitService | `b-7.4.x-VR-20-mollie-one-commit-path` | MERGED into `b-7.4.x` (`eb48a21`) |
| W5 webhook on payment-base's shared guard chain + TrustedProxyClientIp | `b-7.4.x-VR-19-mollie-shared-guards` | MERGED into `b-7.4.x` (`eb48a21`) |
| T2 late money, DC-M3..5, D-items, `blUseStock` follow-up | — | PLANNED (T2 needs payment-base sprint 23 T1 first) |
