# Report: Unnecessary and Dangerous Fallbacks in the Mollie Module

**Date:** 2026-08-12
**Scope:** `source/extensions/mollie-payment/src/` (129 files, ~9.2k LOC), read in full for the
files named below; `payment-base` and the Mollie SDK consulted only where Mollie's behaviour
depends on them.
**Method:** static reading. Every finding below is traceable in the source; the ones marked
**needs runtime confirmation** depend on real Mollie delivery behaviour that code alone cannot
settle.

> **Regenerated 2026-08-12 after file loss.** This file was deleted from disk at ~14:21 during
> Sprint 11's implementation (not by that session, and not via git). It has been rewritten from the
> analysis it originally contained. **Sections A–D below are the original audit, unchanged in
> substance** — they are the reasoning that drove [Sprint 11](../sprints/11-fallback-hardening.md),
> and are deliberately left as they were written *before* implementation. What implementation then
> proved, corrected, or contradicted is collected in the [Post-implementation
> addenda](#post-implementation-addenda) at the end. Read the addenda before acting on any
> individual recommendation — one of them (F5) turned out to be wrong in a way that matters.

## What counts as a finding here

A *fallback* is any place the module substitutes a guess for a fact: `?? default`, a `catch` that
returns a benign value, a guard that passes when it cannot run, a lenient `default =>` arm. The
question asked of each one was: **if this fallback fires, does anyone find out, and is the
substituted value safe?** A fallback is dangerous when it converts a hard failure into a
plausible-looking success — most acutely on a payment path, where "plausible success" means the
shop ships goods it was not paid for, or refunds money twice.

## Summary

| # | Finding | Severity | Certain? |
|---|---|---|---|
| F1 | Webhook event id = payment id under `UNIQUE(OXEVENTID)` → every later delivery answered `200 skipped` | **High** | code-certain; impact needs runtime confirmation |
| F2 | `WebhookResult::skipped()` is `success=true` → "contract not found" / "could not commit" answered 200, never retried | **High** | code-certain |
| F3 | Webhook guard chain fails **open** when the guard service cannot be built | **High** | code-certain |
| F4 | `getMode()` silently falls back to **test** mode (and swaps the API key) on any config-read failure | **High** | code-certain |
| F5 | `X-Forwarded-Proto: https` trusted unconditionally → client-spoofable HTTPS-guard bypass | Medium | code-certain |
| F6 | `WebhookRateLimitGuard` state is per-request → the limiter can never fire | Medium | code-certain |
| F7 | `amountChargedBack` never mapped; DTO default `0.0` hides it → over-refund after a chargeback | Medium | code-certain |
| F8 | `attemptTransition()` swallows every `DomainException`, unlogged | Medium | code-certain |
| F9 | `Events::ensureMolliePaymentMethods()` silent `catch (Throwable)` → module activates with no payment method | Medium | code-certain |
| F10 | OPC handler forges `$_POST['sDeliveryAddressMD5']`, neutralising OXID's address-tamper check | Medium | code-certain |
| F11 | `capturableAmount()`: `amountRemaining === 0` read as "nothing captured yet → full amount" | Medium | code-certain |
| F12 | `AdminActionBounds` returns `0.00` bounds on API failure, unlogged | Medium | code-certain |
| F13 | `userDataIsValid()` fails open with no log or metric | Low-Med | code-certain |
| F14 | `MollieLinesBuilder` residual fold is unbounded | Low-Med | code-certain |
| F15 | Customer's chosen pay-later method silently dropped when the address is incomplete | Low-Med | code-certain |
| F16 | `supportsCurrency()` returns `true` for an empty list / unknown payment id | Low | code-certain |
| F17 | The `'EUR'` fallback family — four independent copies | Low | code-certain |
| F18 | `?? $redirectUrl` persists a shop URL carrying a live `contract_token` into contract metadata | Low | code-certain |
| F19–F24 | Unnecessary-but-harmless leniency (see Section C) | Info | code-certain |

Findings F1, F2 and F3 compound: each one independently converts a lost webhook into `HTTP 200`,
and Mollie retries only on non-2xx.

---

## Section A — Dangerous

### F1. The webhook idempotency key is the payment id, and the unique index is on that alone

`src/Mollie/Webhook/MollieWebhookProcessor.php:80-85`

```php
return new WebhookEvent(
    id: $payment->id,              // ← tr_xxx — identical for every delivery of this payment
    type: $this->determineEventType($payment),
    …
);
```

`payment-base`'s template method then claims the event:

- `payment-base/src/Webhook/AbstractWebhookProcessor.php:69` — `claimEvent($event->id, $provider, $event->type)`
- `payment-base/src/Repository/DoctrineWebhookLogRepository.php:63-79` — the claim is an `INSERT`
  that returns `false` on `UniqueConstraintViolationException`
- `payment-base/migration/data/Version20251031140200.php:311` —
  `$table->addUniqueIndex(['OXEVENTID'], 'UK_EVENT_ID')`

**The unique key is `OXEVENTID` only.** `OXEVENTTYPE` is passed to `claimEvent()` but is not part of
the constraint. So the *first* webhook delivery for a Mollie payment claims `tr_xxx` permanently,
and every later delivery for that same payment — including the one that says `paid` — returns
`WebhookResult::skipped('Already processed: tr_xxx')`.

This works for Stripe (`evt_…` is unique per event) and PayPal. Mollie is the odd one out: it POSTs
only `id=tr_xxx` and expects you to re-fetch, so the payment id is all the module has, and it used
it directly.

Sequences where the decisive delivery is therefore dropped:

- `authorized` → `paid` (cards, Klarna two-step) — the `authorized` delivery claims the id
- `pending` → `paid` (bank-style methods, PayPal via Mollie — note the module has a
  `PaypalPendingReturn.spec.ts` e2e test, so this sequence is known to occur)
- any `refunded` or `chargedback` delivery on a payment that already got a `paid` delivery →
  `PaymentRefundedHandler` and `ChargebackCreatedHandler` can essentially never run from a live
  webhook

**Impact is narrower than "all payments break", and the report should be read that way.** The
return leg is also a fulfilment path: `MollieOrderController::checkoutReturn()` →
`payment-base`'s `CheckoutReturnResponder::respond()` dispatches `PaymentAuthorizedEvent`, which
commits and stamps OXPAID. So a shopper who *does* come back finalizes their order regardless.
What F1 kills is the **backstop** — precisely the cases the backstop exists for: the shopper
closes the tab, the return leg errors, or the status only settles later. The
`mollie:reconcile-oxpaid` console command (`src/Mollie/Command/ReconcileOxpaidCommand.php`) is a
manual compensating control for exactly this symptom, which suggests it has already been observed.

Note also that `WebhookContractFulfillmentHandler`'s class docblock claims the webhook "is the ONLY
path that climbs the contract from PENDING to COMMITTED". That is not true given
`CheckoutReturnResponder`, and the inaccuracy matters: it is the sentence that would make a
reviewer treat F1 as fatal rather than as a dead backstop.

**Fix:** give the event a per-delivery identity in `MollieWebhookProcessor::parseAndValidateRequest()`
— e.g. `id: $payment->id . ':' . $this->determineEventType($payment)`, or include a
refund/chargeback discriminator so successive refunds each claim their own row. Mollie owns no
migrations (per `CLAUDE.md`), so **do not** try to fix this by widening `UK_EVENT_ID`; the event id
is the module's own to choose. Guard it with a unit test that asserts two different statuses on the
same payment id produce two different event ids.

### F2. `skipped()` reports success, so a lost webhook gets HTTP 200

`payment-base/src/Webhook/WebhookResult.php:47` — `skipped()` constructs `new self(true, 'skipped', $reason)`,
and `isSuccess()` returns that `true`. `src/Mollie/Controller/Webhook/WebhookController.php:167-174`
maps any success to `200`.

Mollie retries only on non-2xx. So all of these are answered "all good, don't come back":

- `AbstractMollieWebhookHandler::mapHandlerResult()` with `$result === null` →
  `skipped('Contract not found')`. A webhook that arrives before the contract row is visible —
  Mollie can call the webhook very fast — is permanently discarded.
- `PaymentPaidHandler` skip reason `'Contract already fulfilled or could not be committed'`. That
  string is the tell: two completely different outcomes share one response. "Already fulfilled" is
  fine and should be 200. "Could not be committed" is a failure that a retry might fix, and it gets
  200 as well.
- `MollieWebhookProcessor::processEvent():103` — `skipped("Unhandled Mollie payment status: …")`.
  Intentional and correct for genuinely uninteresting statuses.

**Fix:** separate "safely nothing to do" from "we failed to do it". Return `WebhookResult::failure(...)`
(→ 500, Mollie retries) for contract-not-found and for a fulfilment that did not complete, and keep
`skipped()` for already-fulfilled and unhandled-status. This is the single highest-value change in
this report and it is small.

### F3. The guard chain fails open

`src/Mollie/Controller/Webhook/WebhookController.php:54-59, 75-82`

```php
try {
    $guard = $container->get(WebhookRequestGuardInterface::class);
    $this->guard = $guard instanceof WebhookRequestGuardInterface ? $guard : null;
} catch (Throwable $e) {
    Registry::getLogger()->warning('Mollie webhook guard chain unavailable', …);
}
…
$guardResult = $this->getGuard()?->check($this->buildGuardRequest());
if ($guardResult !== null && !$guardResult->ok) { … reject … }
```

If the guard service cannot be built, `$this->guard` stays `null`, `$guardResult` is `null`, and the
`!== null` test skips **the entire guard chain** — HTTPS enforcement, body-size limit, rate limit,
IP allowlist — while the endpoint continues to process webhooks normally. One `warning` line is the
only trace.

The inconsistency in the same method makes the intent clear: a missing *processor* is fail-closed
(`sendResponse(500, 'processor_unavailable')`, line 89-91), a missing *guard* is fail-open. Security
controls should be the fail-closed ones.

**Fix:** treat an unavailable guard chain as `503`, or at minimum log it at `error` and apply a
built-in minimum check (HTTPS + size) inline. The `?->` here reads as defensive style but it is a
policy decision hidden in an operator.

### F4. A config-read hiccup silently switches a live shop into test mode

`src/Mollie/Service/ModuleConfigurationService.php:36-44, 55-65, 67-83`

```php
} catch (Throwable) { $this->moduleConfig = null; }          // ctor, unlogged
…
protected function readSetting(string $name): mixed {
    if ($this->moduleConfig === null) { return ''; }         // unlogged
    try { return …->getValue(); } catch (Throwable) { return ''; }   // unlogged
}
public function getMode(): string {
    return $this->get('sMollieMode') === MODE_LIVE ? MODE_LIVE : MODE_TEST;   // ← anything ≠ 'live' means test
}
public function getApiKey(): string {
    $value = $this->get($this->isTestMode() ? 'sMollieTestKey' : 'sMollieLiveKey');
    …
}
```

Any failure to read settings — DAO error, wrong shop id, missing setting — yields `''`, which is
not `'live'`, so `getMode()` returns **test** and `getApiKey()` returns the **test** key. The class
has no logger at all, so nothing is recorded.

Consequences in a live shop, in order of severity:

1. If a test key is configured (normal for a shop that was ever tested), checkout keeps working
   against Mollie's **test** account. Shoppers complete payment, the return leg fulfils the order,
   OXPAID is stamped — and no real money is ever collected. This is the worst available failure
   direction: it looks exactly like success.
2. `ContractTokenService::getSecret()` derives the return-token HMAC from the API key
   (`src/Mollie/Service/ContractTokenService.php:82-96`). A mode flip changes the secret, so every
   in-flight return token becomes invalid and returning shoppers get `MOLLIE_RETURN_INVALID_TOKEN`
   after paying.

To be fair to the current design: defaulting to *live* would be worse (real cards charged under an
uncertain configuration), which is presumably why test was chosen. But the choice is a false
dilemma — the correct behaviour for a payment module that cannot read its own configuration is to
**fail closed**: throw a typed configuration exception so Mollie is not offered at all, rather than
silently transacting against the other account.

**Fix:** inject a logger and log every fallback in `readSetting()` at `error`; make `getMode()`
throw `MollieConfigurationException` when the mode cannot be read (as opposed to being legitimately
absent, where the metadata default `test` applies); keep the mode+key pair resolved together so
they can never disagree.

### F5. `X-Forwarded-Proto` is trusted from anyone

> ⚠️ **This finding's recommendation was implemented and then reversed. See
> [Addendum A1](#a1-f5-the-recommended-fix-broke-webhook-delivery-entirely).**

`src/Mollie/Controller/Webhook/WebhookController.php:140-143`

```php
$forwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
$isHttps = (…$_SERVER['HTTPS']…) || $forwardedProto === 'https';
```

`HTTP_X_FORWARDED_PROTO` is a request header. Any client can send it, so any client can tell the
HTTPS guard that a plaintext request was encrypted. The header is only meaningful when it is known
to have been set by a trusted reverse proxy — that is a deployment fact the code cannot assume.

**Fix:** gate the forwarded-header branch behind a config flag (`trust proxy headers`, default off),
consistent with how `WebhookIpAllowlistGuard` is already gated off by default. Same shape, same
justification.

### F6. The rate-limit guard cannot rate-limit

`src/Mollie/Controller/Webhook/WebhookRateLimitGuard.php:25, 33-51`

`private array $buckets = []` is instance state on a request-scoped service in a share-nothing PHP
process. Every webhook request starts with an empty bucket map, takes the
`?? ['tokens' => $this->burst, …]` branch, spends one of 60 tokens and discards the map at the end
of the request. It can only reject if `check()` is called more than 60 times **within a single
request**, which never happens.

The docblock states "In-memory only (per PHP-FPM worker) — fine for single-node deployments". Both
halves are wrong: the state is per-*request*, not per-worker, and it protects nothing on a single
node either. A guard that reads as protection but provides none is worse than an absent one,
because it satisfies a reviewer looking for a rate limit.

**Fix:** either back it with shared storage (APCu / the shop cache / a DB counter keyed by IP+minute)
or delete it and correct the guard-chain documentation. Do not leave it as is with that docblock.

### F7. `amountChargedBack` is never mapped, and a default of `0.0` hides that

`src/Mollie/Adapter/MollieAdapter.php:261-276` builds the DTO with `getAmountRefunded()` and
`getAmountRemaining()` but **not** `getAmountChargedBack()` — which the vendored SDK does provide
(`vendor/mollie/mollie-api-php/src/Resources/Payment.php:575`). The DTO's constructor default
(`src/Mollie/Adapter/Dto/MolliePaymentDto.php:32`, `public float $amountChargedBack = 0.0`)
silently fills the gap, so nothing fails and no test notices.

Two consequences:

1. `MollieWebhookProcessor::determineEventType():127` — `if ($payment->amountChargedBack > 0.0)` is
   dead on the real path. Chargebacks route as their underlying status instead, so
   `ChargebackCreatedHandler` never fires from an API-fetched payment. (F1 would suppress it anyway;
   both need fixing.)
2. `MolliePaymentDto::refundableAmount():79` and the duplicate in
   `RefundService::refundableAmount():132` both subtract `amountChargedBack`, which is always `0.0`.
   **The refundable ceiling is therefore overstated by exactly the charged-back amount**, and
   `AdminActionBounds::refundBound()` shows that inflated figure in the admin panel. An admin can
   refund money the customer has already clawed back — the shop pays twice.

This is the cleanest example in the codebase of a constructor default masking a missing mapping.

**Fix:** map `getAmountChargedBack()` in `mapPayment()`; make `amountChargedBack` a required
constructor parameter so the next omission is a type error rather than a wrong number; delete
`RefundService::refundableAmount()` in favour of the DTO method (F21).

### F8. Every `DomainException` in the fulfilment ladder is swallowed, unlogged

`src/Mollie/Webhook/Handler/WebhookContractFulfillmentHandler.php:213-221`

```php
private function attemptTransition(callable $transition): void {
    try { $transition(); }
    catch (DomainException) {
        // Idempotent: either the contract already advanced past this step, or this step's
        // precondition isn't met yet — safe to continue the ladder either way.
    }
}
```

The comment enumerates two benign causes; the `catch` accepts *all* of them. Any other
`DomainException` a `payment-base` transition raises — an integrity guard, an amount mismatch, an
invalid condition — is discarded identically, and the ladder proceeds to call `fulfill()` on a
contract that never reached the state it needed. There is no logger in the class, so a genuinely
failed commit leaves no trace beyond a `skipped` webhook-log row (F2).

Note `advanceToCommitted():207-210` also skips `commitToOrder()` entirely when the contract has no
order id — silently, by design, in the one place where "no order id" is a real problem.

**Fix:** log at `debug` when the transition was a no-op and at `warning` otherwise (compare the
contract state before and after, or catch the specific precondition exception type if
`payment-base` offers one). At minimum, record *that* a transition was skipped.

### F9. Activation swallows payment-method installation failures

`src/Mollie/Core/Events.php:41-50`

```php
} catch (Throwable) {
    // Silent: activation must never fail. Admin can re-run `oe:module:activate`.
}
```

If `PaymentMethodInstaller::ensureMolliePaymentMethods()` throws, `oe:module:activate` reports
success and the module is active — with no `oxpayments` row, so Mollie never appears in checkout.
The comment's recovery plan ("admin can re-run") depends on the admin knowing something failed, and
the code makes sure they cannot. `Registry::getLogger()` is already used elsewhere in this class's
neighbourhood and is available here.

**Fix:** keep the catch (activation robustness is a legitimate goal) and log the throwable at
`error`. Consider surfacing it via `Registry::getUtilsView()->addErrorToDisplay()` when
`isAdmin()`.

### F10. The OPC handler forges the delivery-address hash

`src/Mollie/PaymentHandler/MolliePaymentHandler.php:201`

```php
$_POST['sDeliveryAddressMD5'] = $user->getEncodedDeliveryAddress();
```

`sDeliveryAddressMD5` is OXID's guard against the delivery address changing between the address step
and order finalisation. Writing the *current* encoded address into `$_POST` server-side makes the
comparison compare a value with itself: it can never fail again for the OPC path. The docblock
frames this as basket preparation ("so the early-order creation does not reject with an
invalid-delivery/user state"), which is exactly the check being disabled.

Also on line 195-198: when the OPC-supplied user is missing, the handler falls back to the session
user, so the basket can be attributed to a different user than the caller passed. Lower severity,
but it belongs in the same review.

**Fix:** pass the hash through from the OPC request instead of synthesising it, and let the guard
fail when it should. If OPC genuinely cannot supply it, this needs an explicit, documented decision
with the security implication written down — not a one-line `$_POST` write inside a method named
`prepareOxidBasket()`.

### F11. `amountRemaining === 0` is read as "nothing captured yet"

`src/Mollie/Service/CaptureService.php:99-102` and the identical formula in
`src/Mollie/Adapter/Dto/MolliePaymentDto.php:capturableAmount()`:

```php
return $payment->amountRemaining > 0.0 ? $payment->amountRemaining : $payment->amount->value;
```

Zero remaining is ambiguous — it means both "no partial capture has happened" and "fully captured".
The fallback resolves it to the first, so after a **full** capture the local bound reports the whole
amount as capturable again, and `assertWithinCapturable()` waves a second full capture through. The
only thing that stops it is Mollie's own status check in
`MollieAdapter::captureAuthorization():91` (`status !== authorized` → `CaptureNotSupportedException`).

So this is not currently exploitable, and I want to be precise about that. But it means the local
guard is a no-op in exactly the case it was written to catch, and
`AdminActionBounds::captureBound()` shows a full capturable amount for an already-captured payment,
inviting an admin action that can only end in an error.

**Fix:** distinguish the states explicitly — use the payment status (`authorized` vs not) to decide
whether a capture is possible at all, and treat `amountRemaining` as authoritative only while the
payment is `authorized`.

### F12. Zero bounds are indistinguishable from "Mollie is down"

`src/Mollie/Admin/AdminActionBounds.php:33-38, 50-58`

```php
public function refundBound(…): float { return $this->loadPayment($contract)?->refundableAmount() ?? 0.0; }
…
try { return $this->paymentsAdapter->getPayment($providerOrderId); }
catch (Throwable) { return null; }        // unlogged — no logger in this class
```

Fail-closed is the right direction. The problem is that the admin panel renders `0.00` refundable
for both "already fully refunded" and "the Mollie API call failed", with no message. An operator
can reasonably conclude a refund has already been issued and stop investigating.

**Fix:** inject a logger and log the throwable; return a nullable/typed result so the panel can
render "Mollie unavailable" instead of `0.00`. `MolliePanelViewDataBuilder` already has an
`errorMessage` field (`private function empty()`), so the presentation slot exists.

### F13. The user-data validation gate fails open with no trace

`src/Mollie/Controller/PaymentController.php:79-88` — documented and deliberate:

> Fails open (returns true) when the validator or a usable field reader is unavailable, so a
> wiring problem in the validation subsystem never blocks checkout entirely; the shared
> character-level rules are defense-in-depth, not the only gate.

The reasoning is sound and I would not change the direction. What is missing is the signal: if the
DI wiring for `UserDataValidatorInterface` breaks, the shop loses this layer **permanently and
silently**. `CLAUDE.md` makes adopting this subsystem a hard requirement for Mollie, which makes a
silent opt-out worse than for a module where it is optional.

**Fix:** log at `warning` when the fallback fires. One line.

### F14. The residual fold has no sanity bound

`src/Mollie/Service/MollieLinesBuilder.php:53-58`

```php
$delta = self::round($expectedTotal - self::sumTotals($lines));
if (abs($delta) >= self::CENT) {
    $type = $delta < 0 ? TYPE_DISCOUNT : TYPE_SURCHARGE;
    $lines[] = self::flatLine($currency, 'Rounding adjustment', $delta, $type);
}
```

The fold exists to absorb a cent or two of OXID rounding, and it is described as such. But it
absorbs *any* discrepancy. If the basket read comes back empty or partial —
`MollieOrderDataProvider::lines()` returns `[]` when the session basket is unavailable — the delta
equals the entire order total and the module sends Klarna a single line reading
"Rounding adjustment €249.00". Mollie's invariant is satisfied, so nothing rejects it, and the
customer receives a pay-later invoice with no itemisation. On a BNPL invoice that is a
consumer-facing document.

**Fix:** cap the fold (e.g. `abs($delta) <= max(0.05, 0.01 * $expectedTotal)`) and throw or log
loudly above the cap. A discrepancy that large is a data-mapping bug, not rounding.

### F15. The customer's chosen method is silently replaced

`src/Mollie/Service/CheckoutPaymentService.php:47-53`

```php
// Safety: a pay-later method without the required order data would 422. Rather than fail the
// shopper, drop the forced method so Mollie presents its hosted page …
if ($needsOrderData && $billingAddress === null) { $effectiveMethod = null; }
```

The shopper picked Klarna; they get Mollie's full method list instead, with no explanation. The
trade-off (don't dead-end the checkout) is defensible, but the class has no logger, so nobody learns
that the address mapping is failing — and an address-completeness problem affecting every Klarna
order would be invisible.

**Fix:** inject a logger and record the substitution with the contract id. Consider surfacing a
notice to the shopper.

### F16. `supportsCurrency()` is permissive on missing data

`src/Mollie/Core/MollieDefinitions.php:163-181`

```php
$currencies = self::MOLLIE_DEFINITIONS[$paymentId]['currencies'] ?? [];   // unknown id → []
…
return $currencies === [] || in_array(strtoupper($currency), $currencies, true);   // [] → true
```

An unknown payment id, or a typo'd/removed `currencies` key, makes the gate return `true` for every
currency. Today `MOLLIE_DEFINITIONS[…]['currencies'] === ['EUR']` so it is moot — but this is a
gate whose failure mode is "allow everything", keyed on a string.

Compounding it: `ViewConfig::mollieActiveCurrency():92-98` falls back to `'EUR'` when the shop
currency object cannot be read, and `PaymentMethodListService::listActiveMethods()` gates on
`supportsCurrency()`. In a GBP shop with a currency-read problem, the EUR guess *passes* the
EUR-only gate and the shopper is offered methods the create-payment call cannot honour.

**Fix:** make an empty/absent currency list mean "not supported" and let the caller decide, or
require the list to exist for a known id and throw otherwise.

### F17. Four independent `'EUR'` guesses

- `src/Mollie/Adapter/OxidShopAdapter.php:76` — `$currency->name ?? 'EUR'` (and `?: 'EUR'` again on the next line)
- `src/Mollie/Adapter/OxidShopOrderService.php:169` — `$currency->name ?? 'EUR'` in the order response
- `src/Mollie/Core/ViewConfig.php:97` — `?: 'EUR'` for the method-list filter
- `src/Mollie/Service/Return/MollieReturnResolver.php:118` — `$currency !== '' ? $currency : 'EUR'`,
  which flows into `PaymentAuthorizedEvent` → `TransactionRecordingHandler`, i.e. into the recorded
  transaction row

To be accurate about blame: the currency that actually drives the **charge** comes from the contract,
and `payment-base/src/Service/ContractService.php:309` already defaults it to `'EUR'`. That one is
inherited and out of Mollie's hands. Mollie's own four copies affect method filtering, the order
response, and audit records — not the charge amount. The problem with them is corroboration: the
same unverified guess appearing in four places reads like a verified fact, and `MollieAmountDto::fromArray()`
adds a fifth flavour by defaulting currency to `''`.

**Fix:** one shared accessor that either returns the real shop currency or fails. Since the module
is EUR-only today (`MOLLIE_DEFINITIONS`), failing is cheap and honest.

### F18. A live `contract_token` is persisted into the provider-redirect field

`src/Mollie/EventSystem/Handler/MollieCheckoutSessionHandler.php:106-108`

```php
$contract->setProvider(PROVIDER_NAME, $payment->id, $payment->checkoutUrl ?? $redirectUrl);
$contract->setMetadata(self::METADATA_MOLLIE_CHECKOUT_URL, $payment->checkoutUrl ?? $redirectUrl);
```

`$redirectUrl` is our own return URL, built four lines earlier by `buildRedirectUrl()` and carrying
`contract_token=…` — the HMAC bearer token that authorises the return leg. When Mollie returns no
checkout URL (the inline-card-cleared-without-3DS path), that token-bearing shop URL is written into
the contract's provider-redirect column *and* into `OXMETADATA`, where it persists at rest and shows
up in any log or admin view that renders metadata.

The fallback is also unnecessary: `resolveDestination()` (lines 123-130) has already computed the
correct destination, `setProvider()`'s third argument is `?string`, and nothing in the module ever
reads either value back (grepped: no `getProviderRedirectUrl` / `mollie_checkout_url` reader). Both
sites can simply pass `$payment->checkoutUrl`.

**Fix:** drop `?? $redirectUrl` in both places.

---

## Section C — Unnecessary but not dangerous

**F19. Three different NullLogger idioms, all dormant.**
`RefundService.php:49` (`LoggerInterface $logger = new NullLogger()`),
`ContractRefundRecorder.php:43` and `OxidStockRestorationService.php:44` (`$logger ?? new NullLogger()`).
`Psr\Log\LoggerInterface` *is* registered in the shop container
(`source/Internal/Framework/Logger/services.yaml:23`), so autowiring supplies a real logger and these
defaults never fire in production. They are worth removing anyway: they make
`ContractRefundRecorder`'s documented "logged as a warning for operator visibility" conditional on
DI wiring nobody re-checks, and they silence unit tests by default. Pick one idiom — a required
constructor argument.

**F20. `getWebhookUrl()` reaches into `Registry::getConfig()`.**
`ModuleConfigurationService.php:110`. `CLAUDE.md` forbids `Registry::getConfig()` reach-ins in
services, and this class's own docblock claims "no `Registry::getConfig()` reach-ins live in the rest
of the module" while doing it on line 110. Inject `ShopAdapterInterface` (which already exposes
`getShopUrl()`). Secondary risk: the derived URL inherits whatever `getShopUrl()` returns, so an
`http://` or wrong-host shop URL silently produces a webhook URL Mollie cannot reach — which is
indistinguishable from the F1/F2 symptoms.

**F21. `refundableAmount()` duplicated.** `RefundService.php:130-133` reimplements
`MolliePaymentDto::refundableAmount()`. Two copies of one money formula, both wrong in the same way
until F7 is fixed.

**F22. Hardcoded version string.** `ViewConfig::getMollieModuleVersion():161` returns `'1.0.0-' . $mtime`
while `metadata.php` declares `0.1.0`. Only a cache-bust prefix, so harmless — but it is a hardcoded
fallback for a value the module already knows.

**F23. `fromArray()` leniency on DTOs that are never built from arrays.**
`MolliePaymentDto::fromArray()`, `MollieRefundDto`, `MollieCaptureDto`, `MollieAmountDto` all default
`id`/`status` to `''` and amounts to `0.0`. On the production path these DTOs come from
`MollieAdapter::mapPayment()`/`mapRefund()`/`mapCapture()`, not from arrays. The leniency is
therefore dead code that would, if ever used, convert a missing status into `''` → `MollieOutcome::IGNORED`
→ `skipped` → 200 (F2). Either delete the array constructors or make the required fields required.

**F24. `WebhookIpAllowlistGuard` is IPv4-only.** `ipInCidr()` uses `ip2long()`, which returns `false`
for IPv6, so an enabled allowlist rejects every IPv6 delivery with `403` — Mollie retries, then gives
up. Also `-1 << (32 - (int) $bits)` raises `ArithmeticError` for a CIDR suffix above 32. The guard is
off by default (documented, and correct given Mollie publishes no source ranges), so this only bites
merchants who switch it on. Worth a note in the setting's help text at minimum.

---

## Section D — Fallbacks that are fine as they are

Listed so a future reader does not "fix" them:

- `MollieStatusMapper::map()` `default => MollieOutcome::IGNORED` — a new Mollie status must not
  break the pipeline. Correct, and documented. (Its interaction with F2 is F2's problem, not the
  mapper's.)
- `WebhookIpAllowlistGuard` empty allowlist = pass — matches the documented design decision in
  `CLAUDE.md` (Mollie publishes no fixed ranges; default off).
- `TransactionHistoryService::safeListCaptures()/safeListRefunds()` → `[]` — a read-only admin
  display degrading to a partial row set rather than a blank panel. Deliberate and explained.
  `safeGetPayment()` → `null` → `[]` deserves a log line, but the direction is right.
- `MolliePaymentHandler::logIframeFallbackIfRequested()` — a redirect fallback for a PSP that
  genuinely cannot be framed, logged once. Model behaviour: substitute, and say so.
- `ContractRefundRecorder::record()` skipping non-FULFILLED contracts — the Mollie-side refund
  already succeeded, so throwing would be worse. It logs a warning (see F19 for why that logging is
  more fragile than it looks).
- `MollieOrderController::resolveService()` catch → `null` — every caller handles `null` with an
  explicit user-facing error path, so this controller is fail-closed throughout. Adding a log line
  to the catch would speed up diagnosis, but no behaviour change is needed.
- `LazyMollieAdapter::adapter() ??=` — lazy init, not a fallback.

---

## Cross-cutting patterns

1. **Silence is the common thread.** Almost every dangerous finding shares one property: the class
   performing the fallback has **no logger injected at all** — `ModuleConfigurationService`,
   `WebhookContractFulfillmentHandler`, `AdminActionBounds`, `CheckoutPaymentService`,
   `PaymentController`, `TransactionHistoryService`, `Events`. Adding a logger to those seven
   classes and logging every fallback would not fix a single bug, but it would make all of them
   detectable, which is the precondition for fixing them.

2. **`?->` and `?? default` are being used to make decisions.** F3 is the sharpest case: whether the
   webhook guard chain runs at all is decided by a `?->` and a `!== null`. Null-safe operators read
   as tidiness, which is why a policy hidden inside one is easy to miss in review.

3. **Defaults on constructor parameters mask missing mappings** (F7) and **defaults on
   array-hydration masks missing fields** (F23). Required parameters would have turned F7 into a
   type error at the one call site that matters.

4. **Two failure directions are conflated with "success"**: `skipped()` (F2) on the webhook path and
   `return true` (F13) on the validation path. Both need a third state.

## Suggested order of work

A sprint-sized slice, ordered by value per unit of risk:

1. **F2 + F1** — the webhook contract. Split `skipped` from `failure`, and make the event id
   per-delivery. Test: two statuses on one payment id claim two rows; a not-found contract answers
   5xx. This is the difference between "the backstop works" and "the backstop is decorative".
2. **F7** — map `amountChargedBack`, make it required, delete the duplicate formula. This one is
   live money.
3. **F3 + F5 + F6** — the guard chain: fail closed, gate the proxy header, and either fix or remove
   the rate limiter with its docblock.
4. **F4** — fail closed on an unreadable mode; log every `readSetting()` fallback.
5. **The logging sweep** — the seven loggerless classes above; convert each silent fallback into a
   logged one. Cheap, mechanical, and it is what makes the remaining findings observable.
6. **F10** — decide the `sDeliveryAddressMD5` question explicitly rather than leaving it inside
   `prepareOxidBasket()`.
7. **F11, F12, F14, F15, F16, F17, F18** — a clean-up pass, each small and independently testable.
8. **Section C** — fold into whatever sprint touches those files next.

Each item above is small enough to be TDD-first with a failing test that encodes the failure
scenario named in the finding.

---

# Post-implementation addenda

Added 2026-08-12 after [Sprint 11](../sprints/11-fallback-hardening.md) shipped
([outcome](../done/11-fallback-hardening.md)). Everything above this line is the audit as written
before any code changed; everything below is what running it proved.

## A1. F5: the recommended fix broke webhook delivery entirely

**The recommendation in F5 was wrong, and the error was in the cost side, not the analysis.** The
observation stands — `X-Forwarded-Proto` is client-supplied and cannot be trusted on its own — but
"gate it behind a default-off flag" was implemented and immediately rejected *every genuine Mollie
delivery* on the dev/staging shop:

```
$ curl -s -X POST -d "id=tr_…" https://daniil.oxiddev.de/index.php?cl=MollieWebhookController
{"action":"tls_required"}   HTTP 400
```

That shop is behind Cloudflare. TLS terminates at the proxy, so the origin sees `HTTPS='(unset)'`,
`SERVER_PORT='80'` and `HTTP_X_FORWARDED_PROTO='https'` — verified by probing the origin directly.
This is the normal production topology for a reverse-proxied shop, not an edge case. With trust off,
`WebhookHttpsGuard` rejects everything Mollie sends; Mollie retries a few times and gives up; the
shop silently stops finalizing orders. That is **the same failure mode as F1 and F2**, reintroduced
by a hardening measure aimed at a much smaller problem.

And the problem it aims at is genuinely small: Mollie only ever calls an HTTPS URL, the request body
is a bare payment id, and verification is the authenticated API re-fetch. Spoofing the header gains
an attacker nothing they did not already have against an endpoint that is unauthenticated by design.

**Resolution shipped:** honoured by default; the flag kept so a shop that terminates TLS at the
origin can harden it; and a `debug` line whenever the HTTPS verdict rests only on the header, so the
weak signal stays visible instead of being silently trusted. The reasoning lives in
`WebhookController::TRUST_PROXY_HEADERS_DEFAULT`.

**Lesson for future audits in this report's style:** a finding can be entirely correct about the
mechanism and still carry a recommendation whose cost dwarfs the risk. Transport-level hardening on
a proxied endpoint needs to be tested against the real deployment before it is called cheap.

## A2. F7: "make the parameter required" would not have caught the bug

F7's stated fix included making `MolliePaymentDto::$amountChargedBack` a required constructor
parameter. That forces an author to *type* a value; it does not stop them typing `0.0`, which is
precisely the wrong value. It would also have churned ~26 fixtures across 15 test files.

What shipped instead: `PaymentMoneyMappingRegressionTest` feeds the adapter an SDK payment whose
money fields carry **distinct non-zero values** and asserts each arrives intact, plus a reflection
assertion that fails when a new float field appears on the DTO without being added to the test. It
catches a dropped mapping *and* a mapping crossed with the wrong accessor. Its first run failed with
`Failed asserting that 95.0 is identical to 55.0` — the over-refund from F7, reproduced exactly.

## A3. F20: the suggested injection closes a dependency cycle

F20 says to inject `ShopAdapterInterface`. That cannot work: `OxidShopAdapter` depends on
`ModuleConfigurationServiceInterface`, so injecting the shop adapter into the config service closes a
cycle the container refuses to compile. Deriving a URL is also a different responsibility from
reading configuration. Shipped as a separate `MollieWebhookUrlProvider`, asserted against the real
container in `ServicesContainerTest`.

## A4. F1: runtime confirmation, and the production footprint

F1 was the one finding marked "impact needs runtime confirmation". Both halves are now settled.

**The shop's own webhook log** (`oe_payments_webhooklogs`, 56 rows, 51 of them real deliveries):

- Three payments carry an `authorized` row and **no `paid` row** — `tr_xv7ngHN4BWAqjQn2sEjUJ`,
  `tr_3dBbzSaeEEyTPju2FiiUJ`, `tr_CDpAkLhhvoHxfDnyDhiUJ`. That is the `authorized → paid` sequence
  with the decisive delivery dropped, exactly as predicted.
- **Not one payment id in 56 rows has more than one row** — because under the old scheme it was
  impossible.

**Live, through the real endpoint**, using a payment whose bare id was claimed on 2026-07-31:

```
BEFORE:  tr_xv7ngHN4BWAqjQn2sEjUJ             authorized  processed  (2026-07-31)
POST #1  →  {"action":"skipped"}  HTTP 200
POST #2  →  {"action":"skipped"}  HTTP 200
AFTER:   tr_xv7ngHN4BWAqjQn2sEjUJ             authorized  processed  (2026-07-31)
         tr_xv7ngHN4BWAqjQn2sEjUJ:authorized  authorized  processed  (2026-08-12)
```

POST #1 claimed its own delivery-scoped row and was processed despite the bare id having been
claimed twelve days earlier — under the old code `claimEvent()` returns false and the delivery is
dropped with a 200. POST #2 created no row, so a true replay still dedupes correctly.

**Still not observed:** a fresh `authorized → paid` pair generated by Mollie itself, which needs a
new manual-capture payment plus an admin capture. The mechanism is confirmed; that specific
end-to-end sequence remains untested.

## A5. Corrections to specific claims above

- **F1's docblock complaint was right.** `WebhookContractFulfillmentHandler`'s "the ONLY path that
  climbs the contract from PENDING to COMMITTED" is false — `CheckoutReturnResponder` also commits.
  Confirmed by reading `payment-base/src/Controller/CheckoutReturnResponder.php:respond()`.
- **F8's scan found one silent catch the audit missed.** `NoSilentCatchRegressionTest` turned up
  `OxidShopOrderService::getOrderCreationDate():232`, which substitutes "now" for an unparseable
  `oxorderdate`. Low impact, now logged. The audit's grep-based pass had not looked at that file.
- **F11 is confirmed non-exploitable but the guard was confirmed useless.** The status check in
  `MollieAdapter::captureAuthorization()` is the only thing preventing a double capture, as stated.
- **F19's "dormant" assessment was right.** `Psr\Log\LoggerInterface` resolves from the real
  container; the NullLogger defaults never fired in production. Removed anyway, and
  `ServicesContainerTest` now proves the container satisfies the required parameters.
- **F16/F17 were moot in this shop, as suspected.** The active shop currency reads cleanly as `EUR`
  (`OxidCurrencyReader::codeFrom()` → `'EUR'`), so neither the permissive gate nor the four guesses
  were firing here. Both were still closed; the value is preventing a future non-EUR shop from
  hitting them.

## A6. What this report did not find

Two things surfaced during implementation that a static fallback audit was never going to catch, and
both are worth a future pass:

1. **The classic redirect checkout flow is effectively untested on this shop.** It has inline-card
   checkout enabled (payment-base iframe flag + `sMollieProfileId` set), so
   `MollieStandard/CheckoutPaysAndFinalizes` and `MollieStandard/InlineMethodRedirect` fail against
   it — verified as pre-existing by reverting `src/` to HEAD and reproducing identical failures.
   Those specs assume the redirect flow.
2. **Configuration-dependent behaviour needs a config matrix, not a code read.** F5's reversal, the
   two failing specs, and the moot F16/F17 all trace to the same gap: the module's behaviour forks on
   deployment facts (proxy topology, iframe flag, profile id, capture mode) that no amount of reading
   `src/` reveals. A "which fallbacks fire under which configuration" table would have caught F5
   before it shipped.
