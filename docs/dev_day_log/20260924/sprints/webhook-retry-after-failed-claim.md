# Sprint: a failed webhook delivery must stay retryable

**Date:** 2026-09-24
**Ticket:** (id to be filled in) — follow-up from MOL-17
**Repos:** payment-base (the fix; every provider inherits it), mollie-payment (integration proof, docs).
Branches `b-7.4.x-webhook-retry-after-failed-claim` in both.
**Status:** IN PROGRESS (started on the product owner's instruction; merge only on approval).
**Definition of Done:** a webhook delivery whose processing ended `failed` (5xx answered, PSP will retry)
can be processed again by the PSP's retry; a delivery that ended `processed` or is still being processed is
still deduplicated; the reason of a failure is stored on the log row.

## Problem

`AbstractWebhookProcessor::process()` claims the event id atomically before processing
(`WebhookLogRepositoryInterface::claimEvent()` = INSERT on `UNIQUE(OXEVENTID)`, status `claimed`). On
failure the row is set to `failed` and the PSP gets a non-2xx answer, so it retries — but the retry's
`claimEvent()` hits the unique key and is answered `skipped('Already processed')`, 200. The failure is
therefore final by construction: Mollie's `paid` webhook that lost a race (MOL-17 before the in-request
retry), a contract not yet created, a transient DB error — all of them leave the order wherever the
failure left it, with a log row that even lacks the error text (`updateStatus()` is called with `null`).

## Approach

| Principle | Application |
|---|---|
| Fix the cause | the claim must distinguish "done" from "tried and failed": a `failed` row is re-claimable, atomically |
| TDD-first | red unit test on the repository (mock connection), red integration test in Mollie's `DoctrineIdempotencyClaimTest` |
| SRP / smallest change | one repository method changes its second branch; the processor only starts recording the error text |
| Provider-agnostic | payment-base only; Stripe, PayPal and Mollie inherit it through `AbstractWebhookProcessor` |
| No overengineering | no retry counters, no back-off, no schema change; the PSP owns the retry schedule |

## Stories

1. **Red proofs.** payment-base `tests/Unit/Repository/DoctrineWebhookLogRepositoryClaimTest`: first delivery
   inserts; retry after `failed` re-claims via `UPDATE … WHERE OXEVENTID = ? AND OXSTATUS = 'failed'`; replay of
   `processed` refused. Mollie `tests/Integration/Webhook/DoctrineIdempotencyClaimTest`: real table — `failed`
   row re-claimable, `processed` row not, `claimed` row not.
2. **Repository.** `DoctrineWebhookLogRepository::claimEvent()`: on `UniqueConstraintViolationException` run the
   atomic re-claim UPDATE (status `claimed`, fresh `OXRECEIVEDAT`, `OXERROR` cleared, `OXPROCESSEDAT` cleared);
   `1` affected row → claimed. Interface docblock states the semantics.
3. **Processor.** `AbstractWebhookProcessor` passes the exception message / result error to `updateStatus()` on
   failure so the row explains itself; unchanged otherwise. Existing processor tests updated for the new
   `updateStatus` argument.
4. **Gates, docs, CI.** Both repos' gates (CI form of phpcs for Mollie), payment-base CI, then Mollie CI after
   payment-base merges; changelogs; report; **no merge without approval**.

## Out of scope
- A `claimed` row left behind by a crashed process (no `failed`, no `processed`) still blocks forever. A
  time-based re-claim ("claimed longer than N minutes") is a separate decision.
- Provider-side changes to what counts as failure vs. terminal skip (Mollie's `WebhookRetrySemanticsTest`
  already pins that).
