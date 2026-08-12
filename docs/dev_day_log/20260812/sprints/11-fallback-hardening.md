# Sprint 11: Fallback Hardening — Webhook Delivery Contract, Fail-Closed Guards, Observability

**Reference:** `../../20260710/sprints/_engeneering_requirements.md` (engineering requirements)
**Parent report:** [`../reports/01-unnecessary-and-dangerous-fallbacks.md`](../reports/01-unnecessary-and-dangerous-fallbacks.md)
— finding ids (F1…F24) below refer to that report and are not repeated in full here
**Date:** 2026-08-12
**Status: DONE.** Outcome, including the three places this plan was wrong:
[`../done/11-fallback-hardening.md`](../done/11-fallback-hardening.md)

> **Regenerated 2026-08-12 after file loss.** This file was deleted from disk at ~14:21 during its
> own implementation (not by the implementing session, and not via git). It is reproduced here as
> the plan that was executed, so the record and the outcome write-up's references resolve. **The
> plan is left as written — including the parts that turned out to be wrong.** Deviations are marked
> inline with ⚠️ and explained in the `done/` write-up; do not treat a ⚠️ story as describing the
> shipped code.

## Overview

The fallback audit found 24 places where the module substitutes a guess for a fact. Four of them
convert a lost payment event into `HTTP 200 OK`, one silently switches a live shop onto its test API
key, and one overstates the refundable balance by the charged-back amount. This sprint fixes the
findings that can cost money or hide a failure, in the report's priority order, and stops there.

The sprint has one organising principle: **a fallback must either be safe or be loud.** Every story
below either changes the substituted value to a safe one, or makes the substitution observable —
never both silently.

Phases, in dependency order:

| Phase | Stories | What it buys |
|---|---|---|
| **A — the webhook delivery contract** | 1, 2, 3 | Mollie retries what we failed to process; the backstop starts working |
| **B — money correctness** | 4 | The refundable ceiling stops overstating by the charged-back amount |
| **C — fail-closed guards** | 5, 6, 7 | Security controls stop failing open; the mode can't silently flip |
| **D — observability** | 8, 9 | The seven loggerless classes report their fallbacks |
| **E — bounded clean-up** | 10, 11 | The cheap correctness fixes that need no design decision |

Story 8 (the logging sweep) is what makes the rest of the report's findings detectable in
production, so it is inside this sprint rather than deferred.

## Engineering Requirements (from `_engeneering_requirements.md`)

| Principle | Application to Sprint 11 |
|-----------|--------------------------|
| **TDD-first** | Every story starts from a test that encodes the report's named failure scenario and fails against `main` before the fix |
| **DevOps-first** | `./bin/pre-commit-check.sh` green before staging; `--full` (incl. Integration) green before the sprint closes |
| **SOLID / SRP** | Webhook *identity* (Story 2), webhook *result semantics* (Story 1) and *retry policy* (Story 3) are three separate changes, tested separately |
| **DIP** | Loggers arrive by constructor injection, never `Registry::getLogger()` inside a service (Story 8) |
| **LSP** | `MollieWebhookProcessor` keeps honouring `AbstractWebhookProcessor`'s contract — no template-method behaviour is overridden, only the values passed into it |
| **DRY** | One refundable-amount formula (Story 4), one shop-currency accessor (Story 11), one event-id builder (Story 2) |
| **No overengineering** | No retry queue, no shared-cache abstraction, no `SecretFieldRenderer`-style indirection. Where a fallback has no safe value, the fix is to fail and log — not to build machinery |
| **No own DB migrations** | The `UNIQUE(OXEVENTID)` constraint stays exactly as `payment-base` defines it (see D1) |

## Key decisions

### D1 — F1 is fixed in Mollie's event id, never in payment-base's schema

The collision is `UNIQUE(OXEVENTID)` in `payment-base/migration/data/Version20251031140200.php:311`
meeting `id: $payment->id` in `MollieWebhookProcessor.php:81`. Widening that index would be a
`payment-base` migration, and `CLAUDE.md` is unambiguous: **Mollie owns no migrations.** The event id
is Mollie's own value to choose, and choosing it correctly is the whole fix. Stripe never hit this
because `evt_…` is already unique per delivery; Mollie must synthesise what Mollie does not send.

### D2 — Event id format: `{paymentId}:{type}` plus a monetary discriminator

`{paymentId}:{type}` alone is not enough. `determineEventType()` returns `refunded` whenever
`amountRefunded > 0`, so two successive **partial** refunds both yield `tr_x:refunded` — the second
would be swallowed exactly as today. Same for `chargedback`.

The event id therefore carries the cumulative amount, in integer cents, for the two monetary types:

```
paid          → tr_x:paid
authorized    → tr_x:authorized
refunded      → tr_x:refunded:1250        (amountRefunded = 12.50)
chargedback   → tr_x:chargedback:4000
```

Cents, not floats — a float in a string key invites `12.5` vs `12.50`. The column is
`VARCHAR(128)`; a Mollie payment id is ≤ 32 chars, so there is ample room, but Story 2 asserts the
length bound anyway.

What this preserves: a genuine **replay** of the same status at the same amount still collides and is
still correctly skipped. That is the idempotency we actually want, and it is what
`WebhookIdempotencyTest::testReplay_SecondDelivery_IsNoOpReturnsDuplicate()` already pins.

### D3 — "Contract not found" retries, but not forever

Story 1 makes contract-not-found a `failure` so Mollie retries — which is right for the race where
the webhook beats our own commit. Unbounded, it is also a self-inflicted retry storm for any payment
that will *never* have a contract in this shop (a payment created by another system on the same
Mollie account, a contract deleted by hand, a shop restored from backup).

Bound it by the payment's own age: retry while the payment is young, go terminal after that.

```
contract not found AND payment createdAt < CONTRACT_WAIT_WINDOW (10 min)  → failure  → 5xx → Mollie retries
contract not found AND payment older, or createdAt unknown                → skipped  → 200 + warning log
```

`MolliePaymentDto` already carries `createdAt` (`MollieAdapter::mapPayment()` maps it), but
`MollieWebhookProcessor::toEventObject()` does not currently pass it through — Story 3 adds it.
Ten minutes is a deliberate over-estimate of "our own transaction should have committed by now";
it is a `const`, not a setting, until someone shows a shop that needs it configurable.

### D4 — An unbuildable guard chain is a 503, not a bypass

F3's fix direction: `WebhookController::render()` must not treat "no guard" as "guard passed". The
endpoint answers `503 guard_unavailable` and processes nothing. This is consistent with the
fail-closed treatment the *processor* already gets three lines below (`500 processor_unavailable`),
and 503 is retry-worthy, so a transient container problem does not lose the event.

### D5 — The rate limiter is removed from the chain, and replaced by a cheap id precheck

F6's guard cannot work: `private array $buckets` on a request-scoped service is empty on every
request. Two honest options — back it with shared storage, or stop pretending. Shared storage means
either a counter table (forbidden: no migrations) or APCu (not guaranteed present, and a silently
disabled limiter is exactly the problem we are fixing).

So: **remove `WebhookRateLimitGuard` from the default chain** and correct its documentation, and
close the actual hole it was aiming at with something that works. The real exposure is that an
unauthenticated POST triggers an outbound Mollie API round-trip, so the cheap fix is to reject
implausible ids *before* spending the call: `WebhookController::extractPaymentId()` requires the
`tr_` prefix and a sane length/charset. That is a pure-function guard with no state, so it cannot
rot the way the token bucket did.

The class itself is kept (it is correct token-bucket arithmetic) with a docblock that says plainly
it is not wired in and needs a shared backend before it is. If ops wants real rate limiting, that
is a follow-up sprint with a decided storage backend — not a comment claiming protection.

### D6 — Loggers by injection, one message shape

Every fallback log line uses the class's own prefix and names the substituted value:

```php
$this->logger->warning('[ModuleConfigurationService] setting read failed, substituting empty', [
    'setting' => $name,
]);
```

`Psr\Log\LoggerInterface` is registered in the shop container
(`source/Internal/Framework/Logger/services.yaml:23`), so autowiring supplies it. No
`Registry::getLogger()` inside a service; no optional/nullable logger parameters (see Story 9).

## Stories

### Phase A — the webhook delivery contract

#### Story 1: `skipped` stops meaning "success" for failures (F2)

**As a** merchant
**I want** Mollie to retry a webhook the shop failed to process
**So that** a transient failure does not permanently lose a paid order

**Acceptance Criteria — tests first:**
- [x] Test: `PaymentPaidHandler` with a contract that cannot be committed returns a result where
      `isFailure()` is true (today: `skipped`, `isSuccess() === true`)
- [x] Test: `PaymentPaidHandler` with an **already fulfilled** contract still returns `skipped`
      (`isSuccess() === true`) — the two cases must stop sharing one answer
- [x] Test: `WebhookController::statusCodeFor()` maps that failure to `500`, and the
      already-fulfilled skip to `200`
- [x] Test: the `'Contract already fulfilled or could not be committed'` string no longer exists
      anywhere in `src/` (grep-style regression assertion — that string *is* the bug)
- [x] Implementation: split `AbstractMollieWebhookHandler::mapHandlerResult()`'s tri-state mapping
      so `false` from the fulfilment handler is distinguishable from "no-op by state guard".
      `WebhookContractFulfillmentHandler::handlePaymentPaid()` currently returns `false` for both
      "already fulfilled" (line 55-57) and "fulfil returned false" (line 62-72) — it needs a third
      value or a typed outcome
- [x] Implementation: same treatment for `handlePaymentAuthorized()`, whose `false` at line 167-169
      is a legitimate no-op and must stay `200`

**Files:**
- `src/Mollie/Webhook/Handler/AbstractMollieWebhookHandler.php`
- `src/Mollie/Webhook/Handler/WebhookContractFulfillmentHandler.php`
- `src/Mollie/Webhook/Handler/WebhookContractFulfillmentHandlerInterface.php` (return type widens
  from `?bool` — a tri-state `?bool` is already at its limit; prefer a small enum)
- `src/Mollie/Webhook/Handler/PaymentPaidHandler.php`
- `tests/Unit/Webhook/Handler/PaymentPaidHandlerTest.php`
- `tests/Unit/Webhook/Handler/WebhookContractFulfillmentHandlerTest.php`
- `tests/Unit/Controller/Webhook/WebhookControllerTest.php`

**Note on the `?bool`:** the interface's tri-state `?bool` is what forced the conflation — `null` =
no contract, `true` = acted, `false` = *either* kind of not-acting. Replacing it with an enum
(`NotFound` / `Acted` / `NoOp` / `Failed`) is the smallest change that makes the four cases
expressible. One enum, one present caller each — not speculative abstraction.

---

#### Story 2: A per-delivery event id (F1)

**As a** merchant
**I want** each Mollie status change to be processed once, not each *payment* to be processed once
**So that** the `paid` webhook is not swallowed by the earlier `authorized`/`pending` one

**Acceptance Criteria — tests first:**
- [x] Test: two `WebhookEvent`s built from the same payment id with statuses `authorized` and `paid`
      have **different** `id` values (today: identical — this is the headline regression test)
- [x] Test: `pending` then `paid` on one payment id → two different event ids
- [x] Test: two partial refunds (`amountRefunded` 12.50 then 20.00) → two different event ids (D2's
      monetary discriminator; without it this story is only half done)
- [x] Test: the **same** status at the same amount delivered twice → identical event id, so
      `claimEvent()` still dedupes a true replay
- [x] Test: `chargedback` gets its own id, distinct from the `paid` id for the same payment
- [x] Test: generated event ids are ≤ 128 chars (the `OXEVENTID` column width)
- [x] Implementation: private `buildEventId(MolliePaymentDto $payment, string $type): string` in
      `MollieWebhookProcessor`, cents via `(int) round($amount * 100)`
- [x] **Update the existing test that pins the bug:**
      `tests/Unit/Webhook/WebhookIdempotencyTest.php:68` asserts
      `claimEvent('tr_dupe', 'mollie', 'paid')`. It must become `'tr_dupe:paid'`. Change it
      deliberately and say so in the commit body — silently editing an assertion that encoded the
      old behaviour is how a fix gets undone later
- [x] Integration test: `tests/Integration/Webhook/DoctrineIdempotencyClaimTest.php` gains a case
      proving two statuses on one payment id both claim successfully against the real unique index

**Files:**
- `src/Mollie/Webhook/MollieWebhookProcessor.php`
- `tests/Unit/Webhook/MollieWebhookProcessorTest.php`
- `tests/Unit/Webhook/WebhookIdempotencyTest.php` (update)
- `tests/Integration/Webhook/DoctrineIdempotencyClaimTest.php`

**Migration/back-compat note:** existing rows hold bare `tr_x` ids. After this change a payment that
already has a claimed row will claim again under the new format — one extra webhook-log row and one
extra (idempotent) handler run per in-flight payment at deploy time. `handlePaymentPaid()` early-exits
on `isFulfilled()`, `PaymentRefundedHandler` computes a delta, and the OXPAID write is
`WHERE OXPAID = '0000-00-00 00:00:00'`, so a re-run is safe. State this in the release note; do not
attempt a data migration.

---

#### Story 3: Bounded retry for a missing contract (F2 / D3)

**As a** merchant
**I want** a webhook that arrives before the contract is visible to be retried
**So that** a fast Mollie callback does not permanently lose the order

**Acceptance Criteria — tests first:**
- [x] Test: contract not found, payment `createdAt` 30 s ago → result `isFailure()` → controller
      maps to `5xx`
- [x] Test: contract not found, payment `createdAt` 2 h ago → `skipped` → `200`, and a `warning` is
      logged naming the payment id
- [x] Test: contract not found, `createdAt` absent/unparseable → treated as old → `200` + warning
      (fail terminal, not fail retry — an unbounded retry loop is worse than a logged drop)
- [x] Test: `toEventObject()` includes `createdAt`
- [x] Implementation: `MollieWebhookProcessor::toEventObject()` passes `createdAt`; the handlers read
      it through a small helper on `AbstractMollieWebhookHandler`; window is a `const`
      (`CONTRACT_WAIT_WINDOW_SECONDS = 600`)
- [x] The clock is injected or read through an overridable seam — no bare `new DateTimeImmutable()`
      inside the decision, so the age boundary is testable

**Files:**
- `src/Mollie/Webhook/MollieWebhookProcessor.php`
- `src/Mollie/Webhook/Handler/AbstractMollieWebhookHandler.php`
- `tests/Unit/Webhook/Handler/*` (each of the four ladder handlers)

---

### Phase B — money correctness

#### Story 4: Map `amountChargedBack`, and make it impossible to forget again (F7, F21)

**As a** merchant
**I want** the refundable balance to account for chargebacks
**So that** I cannot refund money a customer has already clawed back

**Acceptance Criteria — tests first:**
- [x] Test: `MollieAdapter::getPayment()` on an SDK `Payment` whose `getAmountChargedBack()` returns
      `40.00` produces a DTO with `amountChargedBack === 40.0` (today: `0.0`)
- [x] Test: `refundableAmount()` for amount 100, refunded 0, chargedback 40 → `60.0`
- [x] Test: `AdminActionBounds::refundBound()` reflects the chargeback
- [x] Test: `MollieWebhookProcessor::determineEventType()` returns `chargedback` for an
      API-fetched payment carrying a chargeback — proving the branch is no longer dead
- [x] Test: `RefundService` rejects a refund above the chargeback-adjusted ceiling
- [x] Implementation: map `getAmountChargedBack()` in `MollieAdapter::mapPayment()`
- [ ] ⚠️ Implementation: **`amountChargedBack` becomes a required constructor parameter** on
      `MolliePaymentDto` — the `= 0.0` default is what hid the omission, so removing the default is
      the actual fix; the compiler then finds every construction site
      — **NOT DONE, deliberately.** A required parameter forces an author to *type* a value, not the
      right one; it would not have caught this bug and would churn ~26 fixtures. Replaced by a
      distinct-value mapping regression test. See `done/` §2.
- [x] Implementation: delete `RefundService::refundableAmount()` (lines 130-133) and call
      `MolliePaymentDto::refundableAmount()` — one formula, one place
- [x] Regression test: a reflection/AST assertion that no `MolliePaymentDto` constructor parameter
      carrying money has a default (guards the next such omission)
      — shipped as a *field-inventory* assertion instead, for the reason above

**Files:**
- `src/Mollie/Adapter/MollieAdapter.php`
- `src/Mollie/Adapter/Dto/MolliePaymentDto.php`
- `src/Mollie/Service/RefundService.php`
- `tests/Unit/Adapter/MollieAdapterTest.php`
- `tests/Unit/Adapter/Dto/MolliePaymentDtoTest.php`
- `tests/Unit/Service/RefundServiceTest.php`
- `tests/Unit/Admin/AdminActionBoundsTest.php`

**Expect fallout:** making the parameter required will break every test that constructs the DTO
positionally. That churn is the point — it is the compiler doing the audit for us. Use named
arguments in the fixed tests.

---

### Phase C — fail-closed guards

#### Story 5: The guard chain fails closed (F3)

**Acceptance Criteria — tests first:**
- [x] Test: guard service unavailable (`$this->guard === null`) → response `503 guard_unavailable`
      and **no** call to the processor (today: guards skipped, webhook processed normally)
- [x] Test: guard present and passing → processing continues unchanged
- [x] Test: guard present and rejecting → existing behaviour preserved (status + `reason`)
- [x] Test: the `init()` catch logs at `error`, not `warning`
- [x] Implementation: replace `if ($guardResult !== null && !$guardResult->ok)` with an explicit
      three-way — no guard / rejected / passed. The `?->` is doing policy work and must go
- [x] Extend `tests/Unit/Controller/Webhook/WebhookGuardChainTest.php` (the file already exists and
      is the natural home) — shipped as a new `WebhookFailClosedTest`, with the existing tests that
      passed `testGuard: null` updated to state a passing guard explicitly

**Files:**
- `src/Mollie/Controller/Webhook/WebhookController.php`
- `tests/Unit/Controller/Webhook/WebhookGuardChainTest.php`

---

#### Story 6: Proxy header gated; rate limiter told the truth about itself (F5, F6, D5)

**Acceptance Criteria — tests first:**
- [ ] ⚠️ Test: `X-Forwarded-Proto: https` on a plaintext request does **not** satisfy the HTTPS guard
      when proxy trust is off (today: it does)
- [x] Test: with proxy trust on, the header is honoured
- [ ] ⚠️ Test: proxy trust defaults to **off**
      — **REVERSED.** Shipped as default-off, then measured against the real deployment: this shop is
      Cloudflare-fronted, the origin sees `HTTPS` unset and port 80, and default-off rejected *every*
      genuine Mollie delivery with `400 tls_required` — reintroducing the silent
      "order never finalizes" failure F1/F2 exist to prevent. Default is now **on**, the flag is kept
      for origin-TLS shops, and the verdict is logged when it rests only on the header. See `done/` §1.
- [x] Test: `extractPaymentId()` rejects an id without the `tr_` prefix, an over-long id, and one
      with non-`[A-Za-z0-9_]` characters — **before** any adapter call (assert the webhook adapter
      mock is never invoked)
- [x] Test: a valid `tr_…` id still passes through unchanged
- [x] Implementation: proxy trust as a constructor/config flag on the request builder
- [x] Implementation: id precheck in `extractPaymentId()`
- [x] Implementation: remove `WebhookRateLimitGuard` from the default chain in `services.yaml`;
      rewrite its docblock to state it is unwired and requires a shared backend. Its existing unit
      test stays green (the arithmetic is fine) but gains a comment pointing at this decision
- [x] Document the removal in the sprint's `done/` note — silently dropping a guard from a chain is
      exactly the kind of change that must leave a paper trail

**Files:**
- `src/Mollie/Controller/Webhook/WebhookController.php`
- `src/Mollie/Controller/Webhook/WebhookRateLimitGuard.php` (docblock only)
- `services.yaml`
- `tests/Unit/Controller/Webhook/WebhookControllerTest.php`
- `tests/Unit/Controller/Webhook/WebhookGuardChainTest.php`

---

#### Story 7: An unreadable configuration fails closed, loudly (F4)

**As a** merchant running live
**I want** the module to refuse to transact rather than silently switch to my test key
**So that** I cannot ship goods against payments that were never really taken

**Acceptance Criteria — tests first:**
- [x] Test: DAO throws in the constructor → `getMode()` throws `MollieConfigurationException`
      (today: returns `test`)
- [x] Test: `getModuleSetting()` throws for `sMollieMode` → same
- [x] Test: `sMollieMode` legitimately **absent/empty** → still `test` (the metadata default is a
      real default and must keep working — this is the case the current code conflates with failure)
- [x] Test: every `readSetting()` fallback logs at `error` with the setting name
- [x] Test: `getApiKey()` never returns a key for a mode it could not confirm
- [x] Test: the payment-availability path treats the exception as "Mollie not available" rather than
      propagating a fatal to the storefront (`ViewConfig::isMollieTestMode()` catches and logs)
- [x] Implementation: inject `LoggerInterface`; distinguish *unreadable* from *unset*; throw the
      typed exception on unreadable
- [ ] ⚠️ Implementation (F20, same file): `getWebhookUrl()` stops calling `Registry::getConfig()` and
      takes `ShopAdapterInterface::getShopUrl()`
      — **cannot be done that way.** `OxidShopAdapter` depends on the config service, so injecting it
      there closes a DI cycle the container refuses to compile. Derivation moved to a new
      `MollieWebhookUrlProvider`; the config service now exposes `getWebhookUrlOverride()`.
      See `done/` §3.
- [x] Test: the derived webhook URL is `https://` — log a `warning` if the shop URL is not, since an
      unreachable webhook URL is indistinguishable from the F1/F2 symptoms

**Files:**
- `src/Mollie/Service/ModuleConfigurationService.php`
- `services.yaml` (logger + shop adapter wiring)
- `tests/Unit/Service/ModuleConfigurationServiceTest.php`

**Related, verify only (no code):** `ContractTokenService` derives its HMAC secret from the API key,
so a mode flip invalidates in-flight return tokens. With Story 7 in place a *silent* flip can no
longer happen.

---

### Phase D — observability

#### Story 8: The loggerless-class sweep (F8, F9, F12, F13, F15)

**As a** developer on call
**I want** every fallback to leave a trace
**So that** the remaining findings in the report are detectable instead of silent

Seven classes perform fallbacks with **no logger injected at all**. This story adds one and uses it.
No behaviour changes beyond logging, so it is safe to land independently of Phases A–C.

**Acceptance Criteria — tests first (each is "a logger receives a call with these keys"):**
- [x] `WebhookContractFulfillmentHandler::attemptTransition()` (F8) — logs the swallowed
      `DomainException` at `warning` with contract id and the attempted transition
- [x] `WebhookContractFulfillmentHandler::advanceToCommitted()` — logs at `warning` when
      `commitToOrder()` is skipped because the contract has no order id
- [x] `Events::ensureMolliePaymentMethods()` (F9) — logs the throwable at `error`; the catch stays
      (activation robustness is deliberate) but stops being silent
- [x] `AdminActionBounds::loadPayment()` (F12) — logs at `warning`
- [x] `PaymentController::userDataIsValid()` (F13) — logs at `warning` when it fails open. Direction
      stays fail-open (it is documented defence-in-depth); only the silence changes
- [x] `CheckoutPaymentService` (F15) — logs at `warning` when the shopper's pay-later method is
      dropped for an incomplete address, with contract id and dropped method
- [x] `MollieOrderController::resolveService()` — logs at `warning` on a failed resolve (5 call
      sites, all currently silent)
- [x] `TransactionHistoryService::safeGetPayment()` — logs at `warning` (the two list methods'
      degradation is documented and fine, but the payment fetch failing means an empty panel)

**Guard against regression:** add `tests/Unit/Architecture/NoSilentCatchRegressionTest.php` asserting
that no `catch` block in `src/` is empty or comment-only. Two currently-accepted exceptions may be
allowlisted with a reason (`WebhookController::init()`'s file-logger catch, which is genuinely
best-effort). The allowlist is the point: adding to it becomes a deliberate act.

> It found a third silent catch the audit had missed —
> `OxidShopOrderService::getOrderCreationDate()` substituting "now" for an unparseable
> `oxorderdate`. Now logged.

---

#### Story 9: Remove the NullLogger defaults (F19)

**Acceptance Criteria:**
- [x] Test: `RefundService`, `ContractRefundRecorder`, `OxidStockRestorationService` all require a
      logger
- [x] Implementation: drop `= new NullLogger()` / `?? new NullLogger()`; make the parameter required;
      confirm `services.yaml` autowiring supplies `Psr\Log\LoggerInterface`
- [x] Integration test: `tests/Integration/Module/ServicesContainerTest.php` asserts each of the
      three resolves from the real container (proves autowiring, not just the type hint)
- [x] Existing unit tests pass an explicit test double instead of relying on the default

**Rationale:** these defaults are dormant today (the shop container registers
`Psr\Log\LoggerInterface`), so this is a tidiness story — but `ContractRefundRecorder`'s docblock
promises a warning "for operator visibility", and that promise should not be contingent on DI wiring
nobody re-checks.

---

### Phase E — bounded clean-up

#### Story 10: Capture bounds, the token-bearing metadata, and the residual fold (F11, F18, F14)

Three independent, small correctness fixes with no design decision left open.

**Acceptance Criteria — tests first:**
- [x] **F11** Test: a fully-captured payment (`amountRemaining === 0.0`, status not `authorized`)
      reports capture bound `0.0`, not the full amount (today: full amount)
- [x] **F11** Test: a partially-captured `authorized` payment reports `amountRemaining`
- [x] **F11** Implementation: `capturableAmount()` keys on the payment **status** as well as
      `amountRemaining`. Fix it in `MolliePaymentDto::capturableAmount()` and delete
      `CaptureService::capturableAmount()` — same duplicate-formula problem as Story 4
- [x] **F18** Test: when Mollie returns no `checkoutUrl`, neither `setProvider()`'s third argument
      nor the `mollie_checkout_url` metadata contains `contract_token`
- [x] **F18** Implementation: drop `?? $redirectUrl` at
      `MollieCheckoutSessionHandler.php:106` and `:108`
- [x] **F14** Test: `MollieLinesBuilder::build()` with an empty product list and a €249 expected
      total throws instead of emitting a single "Rounding adjustment" line
- [x] **F14** Test: a genuine 1–2 cent rounding delta still folds silently, as designed
- [x] **F14** Implementation: cap the fold at `max(0.05, 1% of expectedTotal)`

**Files:**
- `src/Mollie/Adapter/Dto/MolliePaymentDto.php`, `src/Mollie/Service/CaptureService.php`
- `src/Mollie/EventSystem/Handler/MollieCheckoutSessionHandler.php`
- `src/Mollie/Service/MollieLinesBuilder.php`

---

#### Story 11: One shop-currency accessor; close the permissive currency gate (F16, F17)

**Acceptance Criteria — tests first:**
- [x] Test: `supportsCurrency()` returns **false** for an unknown payment id (today: `true`)
- [x] Test: `supportsCurrency()` returns **false** when the currency list is empty (today: `true`)
- [x] Test: `MollieDefinitions::PAYMENT_ID` + `'EUR'` still returns true (no behaviour change on the
      real path)
- [x] Test: the shop-currency accessor reports failure rather than guessing `'EUR'`, and each caller
      decides what that means for it
- [x] Implementation: one accessor used by `OxidShopAdapter::getShopCurrency()`,
      `ViewConfig::mollieActiveCurrency()`, `MollieReturnResolver::currencyOf()` and
      `OxidShopOrderService::buildOrderResponse()` — four copies of the same guess become one
      explicit decision
- [x] Regression test: no `'EUR'` string literal outside `MollieDefinitions` and its tests

**Scope note:** the currency that drives the actual **charge** defaults to `'EUR'` in
`payment-base/src/Service/ContractService.php:309`. That is inherited and out of scope here; this
story only stops Mollie from adding four corroborating guesses of its own.

---

## Explicitly out of scope

| Deferred | Why |
|---|---|
| **F10 — `$_POST['sDeliveryAddressMD5']` forging** | Neutralises an OXID tamper check on the OPC path. The fix (pass the hash through from OPC, fail if absent) changes OPC checkout behaviour and needs a decision from whoever owns that integration. **Write the test that documents the current behaviour in this sprint**, mark it `@group known-issue`, and file the decision |
| Real webhook rate limiting | Needs a decided shared-storage backend and no new migrations (D5). Follow-up sprint |
| F22 hardcoded `'1.0.0-'` version, F23 dead `fromArray()` leniency, F24 IPv4-only allowlist | Info-level; fold into whichever sprint next touches those files |
| Raising the `'EUR'` default in `payment-base` | Not Mollie's code |
| Widening `UNIQUE(OXEVENTID)` | Mollie owns no migrations (D1) |

## Definition of Done

- [x] Every story's first commit contains a **failing** test; the fix follows in a later commit
- [x] `composer phpcs` clean
- [x] `composer phpstan` at level max, **no new baseline entries**
- [x] `composer phpmd` clean
- [x] `vendor/bin/phpunit -c tests/phpunit.xml --testsuite Unit` green
- [x] `./bin/pre-commit-check.sh --full` green (Integration included)
- [x] **Runtime confirmation of F1** recorded in `done/` — the one finding static reading could not
      settle
- [x] Playwright suite still green — **two `MollieStandard` specs fail; verified pre-existing** by
      reverting `src/` to HEAD and reproducing identical failures. OPC 3/4 pass including a real
      payment through to order finalization
- [x] `WebhookIdempotencyTest`'s changed assertion called out explicitly (Story 2)
- [x] `status.md` updated; `done/` note covers the guard removal (D5), the F1 back-compat re-run
      (Story 2), and the F10 deferral
- [x] Commit messages end with the `Co-Authored-By` trailer

## Risk register

| Risk | Likelihood | Impact | Mitigation | Outcome |
|---|---|---|---|---|
| Story 1 turns transient failures into 5xx storms | medium | high | D3's age bound; terminal drops log at `warning` | Bound shipped; watch the webhook-log table after deploy |
| Story 2's new event-id format re-runs handlers once per in-flight payment at deploy | high | low | All four ladder handlers are idempotent | Confirmed live: the re-run was a correct `NoOp` → 200 |
| Story 4's required constructor parameter breaks many test fixtures | high | low | Convert fixtures to named arguments | Avoided — the required parameter was dropped (⚠️ Story 4) |
| Story 5's 503 hides a permanently broken container behind a retry-worthy status | low | medium | `error`-level log in `init()` | As designed |
| Story 7 makes Mollie unavailable in checkout where it previously "worked" | low | medium-high | Intended behaviour change; call it out in the release note | Unchanged — a shop silently on its test key will now see Mollie disappear until configured |
| Removing the rate-limit guard reads as a security regression in review | medium | low | D5 states the reasoning; the id precheck replaces the real exposure | Recorded in `done/` |
| Story 8's log volume on a busy shop | medium | low | Every new line sits on a rare fallback path | No hot paths observed |
| **Not foreseen:** hardening the HTTPS guard breaks delivery behind a TLS-terminating proxy | — | **high** | — | **Occurred.** Caught by testing the live endpoint, not by any test in this plan. See `done/` §1 — the risk register should have had a "does this change how the endpoint answers a real proxied request?" line |
