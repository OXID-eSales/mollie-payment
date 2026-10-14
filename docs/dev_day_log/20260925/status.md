# 2026-09-25

- Sprint 01 (in3 / Installments offered in some checkout sessions, missing in others; ticket id tbd) — **INVESTIGATED, PLANNED, awaiting approval**; no code changed, nothing committed. See `reports/01-in3-method-availability.md`, `sprints/01-in3-method-availability.md`.
  - Root cause: the only per-session input to Mollie's `/methods` call is the *session user's billing country* (`ViewConfig::mollieBillingCountryIso()`). No session user (OPC footer rendered before the address is saved) → no filter → Mollie returns in3; DE/AT/BE billing → Mollie removes in3 (it is NL-only). 0 of this shop's users are Dutch, 397 are German.
  - Also: no amount is sent (in3's real window on the profile is 50.00–5000.00 EUR, probed), and any `/methods` failure is swallowed into `[]` with no log line, which flips the page to the redirect button.
  - Ruled out: session/persistent caching (per-request only), JS hiding tiles, admin per-method settings (none exist), OXID `oxpayments` row (one active Mollie row, no country restriction).
  - Plan: 5 stories — red e2e matrix + unit reds; send basket amount+currency; deterministic country resolver + logging; OPC footer follows the address; regression/changelog. Push, CI green, no merge.
