# Done 03 — Shared-storage webhook rate limiter + F17 correction (review D-M1/D-M2) — 2026-10-14

Branch: `b-7.4.x-REVIEW-01-fixes`.

## Problem

The webhook guard chain held only payload-size + HTTPS guards. Sprint 11 had honestly unwired the
old `WebhookRateLimitGuard` (per-request token buckets — could never reject), leaving only the
`tr_` id-shape precheck: any well-shaped forged id still bought an authenticated outbound
`getPayment` call, so a flood exhausts the merchant's Mollie API quota and starves legitimate
webhook verification and checkout creation. `docs/security/f-matrix.md` F17 still claimed ✅.

## Fix

- `WebhookRateLimitGuard` reworked to a per-source fixed-window counter on **payment-base's
  `RateLimitStoreInterface`** (Doctrine store reusing `oe_payments_idempotency` — no new table, no
  migration, and the binding is payment-base's own: this module consumes it, no re-binding).
  Key = `sha1(REMOTE_ADDR)` + window index (no raw IP in the table); empty REMOTE_ADDR shares the
  `unknown` budget. **Fails OPEN** on storage failure (store answers 0) — a broken limiter must not
  become a webhook outage; the endpoint's real authentication stays the API re-fetch.
- Wired into the chain as the last (only non-free) guard: payload size → HTTPS → rate limit,
  120/min per source IP (generous vs. Mollie's few delivery IPs; Clock seam for tests).
- F17 row updated: names the rework, the mechanism, and the window during which the old row
  overstated the protection.

## TDD

Red first: new `WebhookRateLimitGuardTest` (5 behaviors: over-limit 429, per-source budgets,
window reset via clock seam, empty-IP budget, fail-open on storage failure) errored against the old
ctor; the two existing construction sites (`WebhookGuardChainTest`, `WebhookSecurityParityTest`
F17) moved to the new shape.

## Proof

- Unit 756 → **761** green (both configs); Integration 40 green (3 env skips).
- Live container: `WebhookRequestGuardInterface` resolves to the 3-guard chain; **request 121 from
  one IP within a minute → `reject 429 rate_limited`** through the real Doctrine store.
- `./bin/pre-commit-check.sh` ALL CHECKS PASSED.
- Note: editing `services.yaml` invalidates the shop's generated class chain — a stale cache made
  the suite error on `MollieOrderController_parent` until `oe:cache:clear`; worth remembering for
  any yaml-touching sprint.
