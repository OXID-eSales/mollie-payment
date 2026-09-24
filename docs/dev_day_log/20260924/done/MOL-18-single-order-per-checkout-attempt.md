# MOL-18 — DONE 2026-09-24

Sprint plan: `../../20260923/sprints/MOL-18-single-order-per-checkout-attempt.md`. Report:
`../reports/MOL-18-single-order-per-checkout-attempt.md`.

**Definition of Done (sprint):** clicking "Order now" any number of times in quick succession creates
exactly one contract, one order and one Mollie payment; every extra click is answered by the same
redirect to Mollie; no order row without articles is written again. — **Met**, proven by e2e and DB.

| Story | Repo | Commit | DoD | Gate |
|---|---|---|---|---|
| 1 red proofs | payment-base, mollie | `3e4e690` (fixture + test), `d5ee7ed` (e2e) | integration RED 800≠799; e2e RED 2 rows (cancelled + phantom) | — |
| 2 no phantom on ORDEREXISTS | payment-base | `3e4e690` | second `createOrder()` throws `order_exists`, writes nothing | Unit 1349, Integration green, phpcs/phpmd clean, phpstan unchanged |
| 3 in-flight resolver + challenge rotation | payment-base | `ca445b6`, `38b0bd4` (redirect URL persisted) | resolver answers per the seven cases; retired attempt clears `sess_challenge`; redirect URL survives reload | Unit 1367, Integration green |
| 4 execute() replays | mollie | `fc9d87f` | second POST answered with the first Mollie URL, nothing dispatched; e2e order count GREEN | Unit 649, phpcs/phpstan/phpmd clean |
| 5 button submits once | mollie | `8825cec` | inline + classic button lock; 3 clicks = 1 POST | e2e green in both modes |
| 6 prove, log, changelog | both | this commit | retry e2e green, regression green, changelogs, CI pin, docs; pushed, not merged | see `../status.md` |
