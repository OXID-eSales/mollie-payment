# 2026-10-12

- MOL-31 — after a partial capture the remaining authorization is neither capturable nor releasable (Mollie admin
  Payment tab) — **REPORT written, nothing implemented**: `reports/MOL-31-partial-capture-remainder.md`.
  - Live reproduction on order 823 (40.00 of 99.50): Mollie answers a second capture with "The pre-authorized payment
    has a capture marked as final" and release/cancel with "Finalized payments cannot be reversed". Multi-capture for
    cards/PayPal is off by default on a Mollie account and must be requested from Mollie; without it the first capture
    is final and the remainder is released to the customer. The expected result is not reachable by module code.
  - Module gaps found on top: a partial capture fulfils the contract and stamps OXPAID (823 reads paid in full);
    the capture/cancel state guards refuse a second action on a fulfilled contract (blocks Klarna/Billie too); the
    re-render offers a capture that fails silently; no "final"/release awareness; docs silent. Options A/B/C in the
    report, recommendation: B now, A once a multi-capture profile exists.
