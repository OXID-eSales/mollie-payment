# 04 — Webhook Processing

## Mollie sends no signature — verification IS the re-fetch

Stripe signs its webhook body with a shared secret (HMAC-SHA256); PayPal signs with RSA and a
certificate chain. **Mollie signs nothing.** A Mollie webhook body is a single form-encoded field:

```
id=tr_WDqYK6vllg
```

That's it — no signature header, no HMAC, no certificate. Mollie's own guidance (and this
module's design) is: don't try to verify a signature that doesn't exist — instead, **re-fetch the
payment from Mollie's API using the shop's own secret key** and trust that authenticated response
as ground truth. If the id doesn't resolve to a payment the shop's API key can see, the request is
rejected exactly as if a signature check had failed.

```
POST /index.php?cl=MollieWebhookController   body: id=tr_WDqYK6vllg
  → WebhookController::render()
      1. guard chain (payload-size → HTTPS → rate-limit)          [WebhookGuardChain]
      2. extractPaymentId() from the request                       [WebhookController]
      3. MollieWebhookProcessor::process()
           a. parseAndValidateRequest():
                GET https://api.mollie.com/v2/payments/tr_WDqYK6vllg
                  (authenticated with the shop's sMollieTestKey/sMollieLiveKey)
                404/network error → throw WebhookSignatureException  ─┐
                200 OK             → WebhookEvent(id, type, data)     │  (AbstractWebhookProcessor
           b. claimEvent() — atomic idempotency claim                │   template method; see below)
           c. processEvent() → route by status to a tagged handler   │
           d. logWebhookResult()                                    ─┘
      4. statusCodeFor(result) → HTTP response (Mollie retries non-2xx)
```

This is the single biggest architectural divergence from Stripe/PayPal in this module — see
`docs/security/f-matrix.md` F18 for the security framing ("verification by API fetch, not
forgeable") and the parity test (`WebhookSecurityParityTest::testF18_VerificationByApiFetch_NotForgeable`).

## Why this is at least as strong as a signature check

A forged webhook (attacker POSTs an arbitrary `id=`) cannot forge the *response* to step 3a — that
response comes from Mollie's API, authenticated with a secret only the shop holds. The attacker
would need either:

- a real Mollie payment id that belongs to a **different** merchant (Mollie's API returns 404 for
  ids the calling API key doesn't own), or
- the shop's own API key (at which point signature verification wouldn't have helped either).

`AbstractWebhookProcessor::process()` (payment-base) guarantees the idempotency claim
(`WebhookLogRepositoryInterface::claimEvent()`) is **never** reached when
`parseAndValidateRequest()` throws — so an unverifiable id can never even be recorded as
"received," let alone advance a contract.

## Idempotency

`claimEvent(eventId, provider, eventType)` is an atomic INSERT-with-unique-key claim (not a
TOCTOU-vulnerable exists-then-save). Mollie can and does redeliver the same webhook; the second
delivery's `claimEvent()` call returns `false` and the processor short-circuits to
`WebhookResult::skipped(...)` before `processEvent()` runs — so a status handler never double-applies
a refund/fulfillment.

## Status routing (OCP)

`MollieWebhookProcessor` has no per-status `if`/`match` chain. It holds an
`iterable<MollieWebhookEventHandlerInterface>` (`!tagged_iterator mollie.webhook_handler`) and
picks the first handler whose `handledStatuses()` contains the event type. Adding Mollie's next
status is: implement the interface, tag the service — zero edits to the processor. See
[02-event-system.md](./02-event-system.md) for the current handler table.

## Chargebacks and refunds share the payment resource

Unlike Stripe (separate `charge.refunded`/`charge.dispute.created` events) or PayPal (separate
webhook event types), Mollie reports refunds and chargebacks as fields **on the same payment
resource** (`amountRefunded`, `amountChargedBack`) — there's no separate refund/chargeback id in
the webhook payload, only the payment id. `MollieWebhookProcessor::determineEventType()` therefore
checks those fields *before* falling back to the raw payment status:

```php
if ($payment->amountChargedBack > 0.0) return 'chargedback';
if ($payment->amountRefunded > 0.0)   return 'refunded';
return strtolower($this->statusMapper->map($payment->status)->name);
```

## Payload sanitization for storage

Received/processed webhook logs go through payment-base's `WebhookPayloadSanitizer` before
`WebhookLogRepository::save()` — the same shared PII-stripping used by Stripe/PayPal
(`WebhookPayloadSanitizer::PII_KEYS`/`PII_NESTED_KEYS`). Mollie's own payload has little PII to
begin with (payment id, status, amount, method — no customer name/email), but the shared sanitizer
runs regardless for defense-in-depth.

## The optional file-audit-trail channel (Sprint 8)

`WebhookController` additionally writes two audit lines to a level-gated file channel
(`log/mollie/mollie_webhooks_<date>.log`) via `mollie.webhook.file_logger`:
`webhook_rejected` (guard rejections, with reason + status) and `webhook_processed` (final
action + success flag). This is a supplementary, best-effort audit trail — see
[for_developer/01-module-principles.md](../for_developer/01-module-principles.md) for the logging
design — never the source of truth for processing (that's `oe_payments_webhooklogs`, written by
`WebhookLogRepository`).
