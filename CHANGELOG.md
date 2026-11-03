# Changelog

All notable changes to this module are documented here. Format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions adhere to [SemVer](https://semver.org/).

## [Unreleased]

### Changed - VR-23 T1/T2: late money and "payment at risk" through payment-base
- New `Gateway\MollieProviderPaymentGateway` (tag `oe.payment.provider_payment_gateway`): `statusOf` (Mollie `paid` ->
  paid, `authorized` -> authorized, anything else holds nothing), `refund` (the whole payment, under the resolver's key
  `late:<payment id>`), `release`. Without it payment-base's stale-order cleanup cannot ask Mollie about a payment.
- **`paid` / `authorized` on a contract the shop closed without payment** (cancelled by the stale-order cleanup or an
  admin, expired, failed) is no longer answered 5xx until Mollie gives up with the money taken: payment-base's
  `LateMoneyResolver` refunds it (a paid payment) or releases it (an authorization) once and records it in the
  contract's metadata; Mollie is answered 200. A refund Mollie refuses raises the order's "payment at risk" flag
  (`late_money_not_returned`, owner mail) and is retried by the next delivery. A payment of a replaced checkout
  attempt goes the same way (`ReplacedAttemptFinderInterface` finds the contract). A delivery for a fulfilled
  contract is still a plain redelivery.
- **`expired` on a committed contract** (a manual-capture authorization that ran out before the capture) raises the
  "payment at risk" flag `authorization_expired` instead of being silently skipped as "payment already taken"; the
  contract is still not touched (payment-base refuses `expire()` on a committed one) and Mollie is answered 200. The
  STRP-168 pin test is rewritten accordingly.
- Removed `Service\MollieLatePaymentRefunder` (and its test, service definition). Contracts that were refunded by it
  keep their `lateMoney` record in the replaced-attempt history; the resolver does not read it, so a redelivery for
  such an old payment asks Mollie again (the payment is already refunded there, the refund is refused, the order is
  flagged once).
- The handler takes `LateMoneyResolverInterface`, `PaymentAtRiskFlagInterface`, `ReplacedAttemptFinderInterface`.

### Changed - VR-22 A6: Mollie's gateways for payment-base's money runner
- New `Gateway\MollieCaptureGateway`, `MollieRefundGateway`, `MollieCancelGateway` (tag `oe.payment.gateway`).
  payment-base's `MoneyOperationRunner` now owns admin capture, refund and void for the classic Payment tab and the
  admin API: policy (capturable / refundable come from payment-base, not from Mollie bounds), idempotency, the order
  lock, rights/step-up and the booking (`ProviderCaptureLedger` by Mollie's capture id, `ProviderRefundLedger` by the
  refund id). The gateways only talk to Mollie; Mollie saying no is a refusal in a translated sentence, never Mollie's
  text; a transport failure is not answered (the runner keeps the claim and retries with the same key). New sentences
  `MOLLIE_ADMIN_CAPTURE_FINAL`, `MOLLIE_ADMIN_CANCEL_FAILED`, `MOLLIE_ADMIN_NO_PAYMENT`.
- A second partial capture is refused with `capture_final` where the account has no multi-capture (Mollie captures once
  and releases the rest). MOL-15's character rules still apply to the capture reason and the refund description.
- **Merchant-visible:** an admin capture no longer fulfils the contract or stamps OXPAID itself: Mollie's `paid`
  webhook does (it books nothing again, the capture is on record). Refund works right after the capture (committed
  with a captured amount). A void books nothing; Mollie's `canceled` webhook cancels the contract and the order.
- The `paid` webhook books an automatically captured payment through `ProviderCaptureLedger` (capture id = the Mollie
  payment id) instead of writing the captured amount and a capture row itself; nothing is booked when an admin capture
  is on record.
- The Mollie-only release (`MollieReleaseAction`, `ReleaseActionInterface`) is run the same way by the classic tab and
  by `adminMolliePaymentRelease`, which no longer goes through payment-base's removed `PanelProviderActionRunner`
  (the operation takes the action and a logger).
- `MolliePaymentPanelProvider` keeps the view, the reasons (`actionOptions`), the read-model bounds and the release.

### Removed - VR-22 A6
- `Service\CaptureService`, `RefundService`, `CancelAuthorizationService` (+ interfaces), `CaptureCompletionPolicy`
  (+ interface, enum), `Service\Exception\MollieRefundNotYetAvailableException`.
- `Controller\Admin\OrderActionDispatcher`, `MollieExtraActionDispatcherInterface`, `EventSystem\Translator\
  MollieEventTranslator` (and Mollie's `EventBroker` binding), the Mollie capture / refund / cancel request events and
  handlers (the release event and handler stay).
- `Admin\AdminAmountValidator`, `AmountValidationResult`, `AdminAmountValidationMessageFormatter`,
  `AdminValidationFeedback::reject()`, `AdminActionRefusals::amount()`, the `MOLLIE_ADMIN_AMOUNT_*` sentences, the
  panel provider's capture/refund/cancel branches and its `validateAmount()`; the idempotency key test of the old
  event chain (the runner owns idempotency).

### Changed - Adopts payment-base VR-24 H8 (ACP ownership guard)
- `MollieAcpCheckoutService` is unchanged (it inherits the guard): its tests now open the contract as an ACP agent
  would (`channel=acp`, `acp_agent_id`), and another agent's checkout is pinned as "not found" with nothing committed.

### Changed - Adopts payment-base VR wave 2 (F3/L1/A1)
- Stock restoration is payment-base's now (`RefundSettledPolicy` after every ledger booking): removed
  `Service\OxidStockRestorationService`, its `services.yaml` binding and autowire exclude, its unit and integration
  tests, and `RefundService`'s restock (`refundsTheRest()`, `restoreStockIfOrderLinked()`, the stock and logger
  constructor arguments). The refund ledger gets `succeeded` = Mollie reports the refund `refunded`
  (`MollieRefundDto::hasSucceeded()`); a pending refund is booked as not yet succeeded and restocks when it settles.
- `ChargebackCreatedHandler` books Mollie's cumulative `amountChargedBack` through `ProviderChargebackLedgerInterface`
  (no chargeback id) instead of recording the whole total on every delivery (VR-21 S-M6); a replay answers skipped.
- `MollieWebhookProcessor` passes payment-base's `ThrowableSummaryInterface` to `AbstractWebhookProcessor` (VR-18 L1).
- VR-22 A1 (`MoneyInvariantViolation`): reviewed, nothing to change - captures are written with the absolute
  `setCapturedAmount()` (a webhook redelivery re-sets the same figure) and refunds go through the ledger, bounded by
  `RefundBound` before booking.

### Changed (VR-20 R6) - one commit path: `paid` commits through payment-base's ContractCommitService
- A `paid` webhook on a contract that is not committed yet commits it through `ContractCommitServiceInterface` (amount,
  currency and state checks in payment-base), like `authorized` already did, instead of climbing a hand-written
  transition ladder of its own. A refused or still-open commit answers Failed, so Mollie retries.
- `WebhookContractFulfillmentHandler` requires the commit service; the fallback that only moved the contract to
  AUTHORIZED when it was missing is gone, with `advanceToAuthorized()` / `advanceToCommitted()`.
### Changed (VR-19 W5) - the webhook runs on payment-base's shared guard chain
- The webhook guards are payment-base's `Webhook\Guard\*` classes, wired as `mollie.webhook.guard_chain` (payload size
  -> HTTPS -> rate limit 120/min). The module's copies (`WebhookGuardChain`, `WebhookGuardResult`, `WebhookRequest`,
  `WebhookRequestGuardInterface`, `WebhookHttpsGuard`, `WebhookPayloadSizeGuard`, `WebhookRateLimitGuard`,
  `WebhookIpAllowlistGuard`) are removed.
- The rate limit keys on the caller as payment-base's `TrustedProxyClientIp` resolves it behind the shop's reverse
  proxies (payment-base settings "Webhooks"): behind Cloudflare, forged ids no longer exhaust the budget Mollie's real
  deliveries share. Without the resolver the endpoint answers 503, like without a guard chain. Requires payment-base
  `b-7.4.x` from 2026-11-03 (`TrustedProxyClientIp` service).

### Changed (VR-21 F6) - refunds are booked once, by their Mollie refund id
- The admin refund (`RefundService`) and the `refunded` webhook (`PaymentRefundedHandler`) book through payment-base's
  `ProviderRefundLedgerInterface` by the Mollie refund id: whichever reports a refund second is a duplicate and changes
  nothing. The webhook lists the payment's refunds instead of booking "Mollie's total minus the contract's", so it no
  longer races the admin refund (a stale save after the money had moved) and answers `refund_recorded` only when it
  booked something. A refund Mollie refused to run (`failed` / `canceled`) is never booked. Requires payment-base with
  `ProviderRefundLedgerInterface` (b-7.4.x since 2026-11-03).
- Removed `Service\ContractRefundRecorder`; `RefundService` no longer takes a `TransactionAuditRecorder` (the ledger
  writes the refund row the admin's Payment tab lists).

### Fixed (VR-21 F1/F2) - one refund is one refund; a partial refund keeps the stock
- Captures and refunds send their idempotency key to Mollie (`MollieAdapter` called the API without it): a double
  click, a resubmit or a retry after a timeout no longer creates a second refund or capture. The key names the contract
  state the action starts from (`contract:action:amount:after:<refunded|captured so far>`), so a real second refund of
  the same amount is not mistaken for a repeat.
- Stock comes back only with the refund that leaves nothing refundable. Before, any refund - a 5 EUR goodwill refund
  on a 100 EUR order included - put every item back in stock and stornoed every order line. A refund Mollie refused to
  run (`failed` / `canceled`) restocks nothing.
### Removed (VR-17) - code nothing called
- `MollieRefundAdapterInterface::getRefund()` (and its two implementations): refund rows come from `listRefunds()`.
- `MollieCaptureRequestEvent::isFullCapture()`, `MollieConfigSnapshot::keySet()` (only a test called it).
- Translation key `MOLLIE_VALIDATION_INVALID_USER_DATA` (en, de), unused since MOL-15.

### Fixed (PSP-SWITCH) - after a PayPal cancel, "Pay" with Mollie opened PayPal again
- The MOL-18 replay now asks payment-base for a **Mollie** attempt only. A PayPal attempt that a cancel left open is
  not rejoined: the Mollie start continues that attempt (OPC-175), the order is written again with
  `OXPAYMENTTYPE = oe_payments_mollie`, and the browser goes to Mollie. Requires payment-base `>=v1.6`.

### Fixed
- **Manual capture and refund from the admin (CFG-1 S8, found by a Mollie test-mode run from the admin app).** An admin
  capture and an admin refund moved the money and the contract but wrote no row to the transaction table, so the Payment tab
  of the admin app did not list them (only a webhook that fulfils the contract wrote a capture row, without a reference).
  Both now write their row with Mollie's capture / refund id as reference (once per id; a webhook later finds it).
  Known and unchanged: with multi-capture on, a partial capture leaves the contract `committed` and a refund is refused
  until the remainder is captured; and Mollie itself refuses a second refund of the same amount moments after the first
  (409 "A duplicate refund has been detected"). After upgrading clear the container cache.

### Removed
- **CFG-1: no GraphQL path changes the module's settings any more** (they change in the classic admin only):
  `adminMollieConfigModify`, with `AdminMollieConfigInput`, `AdminMollieConfigPayload`, `AdminMollieConfig.secretsEditable`,
  the modifier, change validator, audit and result classes and the write side of the settings seam. Kept: `adminMollieConfig`,
  `adminMollieConnectionCheck`, `AdminPaymentContract.mollie` and `adminMolliePaymentRelease`. After upgrading:
  `bin/oe-console oe:graphql:cache-clear`.

### Added
- `AdminMollieConfig.classicSettingsUrl`: the classic admin's settings page of the module for the current shop (as Stripe and
  PayPal have since PAY-8), so the admin app can link to where the settings change. After upgrading:
  `bin/oe-console oe:graphql:cache-clear`.
- PAY-5, the Mollie admin API for `oxid-admin-graphql` (optional, see `docs/for_developer/06-admin-api.md`): `adminMollieConfig`,
  `adminMollieConnectionCheck` (read-only, see Removed),
  `AdminPaymentContract.mollie` (live details, fail closed) and `adminMolliePaymentRelease`. The panel provider implements payment-base's
  `AdminActionOptionsProviderInterface` and `AdminActionBoundsProviderInterface`, `MollieContractStateHelp` is a state-help provider, and
  validation refusals are thrown as `AdminActionRefusedException`. Requires payment-base with the PAY-1 provider API. Changed:
  `MollieExtraActionDispatcherInterface::release()` returns bool, `AdminValidationFeedbackInterface::reject()` returns the sentence,
  the release action refuses a hold with nothing captured (it used to cancel it), `MollieWebhookUrlProviderInterface::urlFor()`.
- Sprint 140b, guest checkout: the Mollie checkout mutations accept an anonymous token that carries `PAYMENT_CHECKOUT`
  (`#[Logged]` removed from `mollieCheckoutStart/Return/Cancel`, `#[Right]` kept). `mollieCheckoutStart` gains the optional
  `guest` argument (payment-base's shared `GuestCheckoutInput`, which builds its `GuestDetails`); the
  mutation names its own payment, so no `basketSetPayment` is needed. `guest_details_required` and `account_exists` arrive as
  `extensions.errorCode` (the first as payment-base's `GuestDetailsRefused`, raised while the arguments are resolved, the second as
  `MollieCheckoutError`). Return/cancel with the contract token work from another anonymous token. Requires payment-base 1.5.
  The input types are defined once, in payment-base, and shared with Stripe and PayPal (GraphQL type names are schema-wide); this
  module defines none (the earlier `MollieGuest*Input` copies are removed, `NamespaceMapper` maps no type namespace).
- `Service\MollieClientConfigProvider`: Mollie's provider for payment-base's sealed `paymentClientConfig` query (tag `payment_base.client_config_provider`). Publishes only `profileId` and `testMode` (empty without a profile id, so the storefront falls back to Mollie's hosted page); API keys are never part of it. Requires a payment-base release that ships `ClientConfigProviderInterface`.
- A "Help" group at the end of the module Settings tab, after "Logging" (MOL-10): payment-base's contract-state
  table (OXID Contract Status · Meaning) with Mollie's third column, the Mollie payment status each state corresponds
  to (`not_finished` → `open`, `pending` → `pending`, `authorized` → `authorized`, `ready_to_commit` → `paid`,
  `committed`/`fulfilled` → none, `cancelled` → `canceled`, `expired` → `expired`, `failed` → `failed`). Rows, meanings
  and markup come from payment-base (`@oe_payment_base/admin/help/*`); Mollie contributes `Admin\MollieContractStateHelp`.
- A "?" next to "OXID Contract Status" on the admin order Payment tab opens payment-base's Help layer with Mollie's
  description and the three-column table (MOL-10).

### Changed
- Sprint 139a: docs and CI run `oe:graphql:cache-clear` after module activation (new `docs/for_developer/04-headless-graphql-setup.md`, merchant setup, `bin/graph-ql-cli-test.sh` clears the schema cache before its first query).
- Sprint 138c: a headless contract's Mollie redirect URL is the client's `returnUrl` unchanged; `contract_id` is no longer
  appended (nothing identifying in browser history or access logs). The client already holds `contractId` and
  `contractToken` from `mollieCheckoutStart` and passes them to `mollieCheckoutReturn`. The Twig / OPC flow keeps its URL.
- `MollieClientConfigProvider` implements payment-base's `AllowListedClientConfigProviderInterface`
  (`publicValueNames()` = `profileId`, `testMode`).
- The admin order Payment tab labels the contract state "OXID Contract Status" ("OXID-Vertragsstatus") instead
  of "State" (MOL-10); the Help table uses the same label.
- The whole Mollie block on the standard order step — the payment-method selection and the inline card
  fields, not only "Order now" — stays locked (`inert`, dimmed) until the AGB checkbox is ticked and locks
  again when it is unticked (MOL-9); with `blConfirmAGB` off nothing is locked. The `mollie-agb-gate`
  controller now sits on the inline block with `region` / `button` targets and still gates a lone button
  in the classic redirect flow. The one-page checkout already keeps the whole footer inside OPC's inert
  payment-execution body until the consents validate; pinned by a spec.
- "Order now" on the standard order step is inactive until the shopper ticks the AGB checkbox (MOL-11):
  a `mollie-agb-gate` Stimulus controller on both Mollie order buttons (classic redirect and inline
  Components) mirrors the state of every agreement checkbox Apex renders (`#checkAgbTop`, downloadable /
  intangible product agreements) into the button; with `blConfirmAGB` off no checkbox renders and the
  button is active at once; a button the server rendered disabled (low order price) is left alone. The
  server-side guard in `MollieOrderController::execute()` stays the safety net. The one-page-checkout
  footer behaves the same: its submit no longer enables itself on connect (OPC's checkout-footer-manager
  owns that gate) and `processCheckout` now carries the real consent checkbox state (`confirmTerms` /
  `confirmPrivacy`; the old `#confirmTermsCheckout` id no longer exists) instead of a constant `true`.
- `mollie:reconcile-paid` console command (MOL-17): fulfils contracts stuck at `committed` whose Mollie
  payment is `paid`, running the webhook's own fulfilment; `--dry-run` lists, `--limit` bounds.
- User-data validation exactly as in the Stripe module (MOL-15), on payment-base's central
  validation system with Mollie's own rules file and module id: the billing **and** the selected
  delivery address are validated; the field set matches Stripe's (`postalCode`, `cellPhone`,
  `personalPhone`, `fax` added) plus Mollie's `email`; the order step (`MollieOrderController::execute`)
  refuses invalid data right before Mollie is called and sends the shopper back to the address step;
  the payment step and the order step show one translated message per field ("The street field is
  not valid. Allowed symbols are: …") plus a review notice; the one-page-checkout footer validates the
  live address fields against `cl=oepaymentvalidationapi` before it posts `processCheckout`, marking
  rejected fields inline; the admin capture reason and refund description run through the same rules.
  All `MOLLIE_VALIDATION_*` translations (EN/DE, storefront and admin) are shipped - the module used to
  render raw keys - and `MOLLIE_CHECKOUT_UNAVAILABLE` is translated as well. No code references the
  Stripe module (pinned by a unit test); both modules depend on payment-base only.

### Fixed
- The admin Payment tab offered a captured amount for capture again (and the Cancel action) right after a
  successful manual capture: the Capture section was gated on Mollie's live payment, which still reads
  `authorized` with the old remaining amount a moment after the capture. The capture bound is now capped by the
  contract's own uncaptured remainder (what the shop captured is never offered twice; Mollie's figure still caps
  it) and Cancel follows the bound, so the next render shows the updated captured and capturable amounts.
- A basket item that is not orderable (turned unbuyable, out of stock for the ordered quantity, removed) no
  longer surfaces as `MOLLIE_CHECKOUT_UNAVAILABLE` / "Mollie is not available right now" (MOL-22). The standard
  order step and the one-page-checkout footer both tell the shopper that the order cannot be completed because
  one or more items are currently not orderable, naming the item when it is known, translated (EN/DE); the
  standard step returns to the basket and shows core's own stock message inline as for any other payment
  method. Checked before the checkout-session event is dispatched (`BasketBuyabilityValidator`, no contract or
  draft order is created) and recognised when core refuses inside `finalizeOrder()` (payment-base's
  `article_not_buyable` code). The OPC handler used to pass core's raw translation key through to the footer.
- Mollie's retries of a webhook whose first delivery failed here are processed again instead of being
  dropped as already processed (payment-base `claimEvent()` re-claims a `failed` row; requires the matching
  payment-base version). Integration proof in `DoctrineIdempotencyClaimTest`.
- Successfully paid orders (reported for Pay by Bank and EPS, in fact any method) offered no Refund
  action in the admin Payment tab (MOL-17). The admin gate needs contract state `fulfilled`, which only
  the `paid` webhook sets; the shopper's return leg overwrote it with its stale `committed` copy when the
  webhook arrived first. With payment-base's versioned contract save, `WebhookContractFulfillmentHandler`
  now re-runs a step once on a fresh copy when its save was refused, and answers Failed (Mollie retries)
  on a second refusal. The paid path also records the captured amount, so the panel no longer shows
  "Captured 0.00" for automatically captured payments.
- "Order now" clicked several times on the standard order page created several orders (MOL-18).
  The clicks reach PHP one after the other; the second one used to start a second checkout
  attempt, which retired the first (order storno'd) and - with core's `sess_challenge` still
  naming that order - ended in a phantom order row without articles that the shopper then paid
  for. `MollieOrderController::execute()` now asks payment-base whether an attempt is already in
  flight (open contract, Mollie checkout URL, order still `NOT_FINISHED`, same basket total) and
  answers every further submission with the same redirect to Mollie. Requires payment-base with
  `InFlightCheckoutAttemptResolverInterface`; an older payment-base keeps the previous behaviour.
- The order button submits once per click burst: the inline-components button and the classic
  redirect button (new `mollie-place-order` Stimulus controller, apex's own markup and conditions)
  disable themselves and show a loading state after the first click, and unlock again when the
  page is restored from the back/forward cache or card tokenisation fails.
- One-page-checkout footer, inline mode: with exactly one enabled Mollie method the widget opened
  with "Choose your payment method" and a single radio. It now names the method read-only, like the
  standard order page; the hidden, checked field still carries the method into processCheckout.
- Manual capture (`sMollieCaptureMode=manual`) no longer narrows what the shopper can pay with. The
  inline method selector used to list only the methods Mollie can authorize first (card, Klarna,
  …), so a manual-capture shop silently lost iDEAL, PayPal, bank transfer and the rest. Every
  enabled method is offered again; manual capture is now decided per created payment — card and
  Buy-Now-Pay-Later are authorized first, instant methods are created with automatic capture and
  settle immediately (Mollie rejects `captureMode=manual` pinned to an instant method with 422).
  With no method pinned, Mollie's hosted page already behaves this way on its own.
- Standard order page, inline mode: with exactly one enabled Mollie method the block opened with
  "Choose your payment method" and a single radio. One method is not a choice: it is now named
  read-only (icon + name), while the hidden, checked form field still submits the method with the
  order and the card flow still detects it. Two or more methods render the selector as before.
- Admin *Payment* tab: the "Refunded" figure was the contract's running total and kept counting
  refunds Mollie had since canceled or failed. It is now the sum of the live refund list shown in
  the transaction table, minus the voided ones, so it always agrees with the refund bound
  (refunded + refundable = captured). The local record is only shown when Mollie cannot be read.
- Admin *Payment* tab: after a refund, capture or cancel the re-rendered tab still showed the
  pre-action refundable amount until reloaded. Two causes: the per-request Mollie payment memo
  was not dropped between the action and the re-render (`resetViewCache()` had been a no-op), and
  the refundable balance ignored refunds Mollie still lists as pending. The balance now follows
  Mollie's own `amountRemaining` when present, falling back to the captured-based arithmetic.
- Admin *Payment* tab and admin refunds: a partially captured payment (e.g. Klarna, authorized
  100.00 and captured 60.00) showed the full authorized amount as refundable and accepted a refund
  request for it. `MolliePaymentDto` now carries Mollie's `amountCaptured` and the refundable
  balance is derived from what actually settled; the field stays `null` for methods without
  captures, which settle in full.
- Admin *Payment* tab: the Mollie dashboard deep-link on the provider payment id carried a stale
  `test-mode` path segment for test-mode orders (`…/dashboard/test-mode/payments/tr_…`). Mollie's
  dashboard no longer encodes the mode in the URL, so the link was dead. `MollieUrlBuilder` now emits
  `https://my.mollie.com/dashboard/payments/{id}` regardless of mode and no longer depends on the
  module configuration.

## [v1.0.0-rc.1] - 2026-09-15

First published release candidate. The module was developed as `0.1.0` without tags; this is the
first version cut for distribution via composer.

### Added
- Mollie payment provider for OXID eShop 7.4 and 7.5, built on the Smart-Contract architecture of
  `oxid-esales/payment-base`: payment handler, webhook endpoint with logging, checkout return
  resolution, contract fulfilment, stock restoration and the shared admin payment panel.
- Module logo: `assets/img/logo.png` with the matching `thumbnail` entry in `metadata.php`, the
  same logo the other payment modules carry.

### Changed
- Packaging for composer publication: the local path repository pointing at `../payment-base` was
  removed — it belongs in the consuming project, not in a published package — and
  `oxid-esales/payment-base` is required as `>=v1.2`, the version that introduced the checkout
  and validation interfaces this module uses.
- Runtime dependencies corrected: `ext-json`, `psr/log` and `symfony/console` added, `doctrine/dbal`
  widened to `^2.13 || ^3.0`, and the unused `symfony/yaml` requirement dropped. `authors` and
  `support` added; `symfony/filesystem` and `mikey179/vfsstream` removed from require-dev, where
  neither was used.
- `oxid-esales/payment-base` was listed under `config.allow-plugins`; it is a module, not a
  composer plugin, so the entry is gone.
- `package.json` carries an author and `SEE LICENSE IN LICENSE`; it was already marked private.

### Changed (tests)
- The unit suite runs standalone: `tests/phpunit-unit.xml` with `tests/bootstrap-unit.php`, which
  boots composer's autoloader plus the few things only an activated module would otherwise
  provide (the `*_parent` classes, the shop's global functions, an in-memory `Config`). No shop,
  no database, 595 tests. The CI job now uses the module's own PHPUnit instead of borrowing the
  shop's binary, and the test namespace moved from `autoload` to `autoload-dev`.
- The workflows no longer pin payment-base with an explicit `as v1.0.0` alias. payment-base
  declares `extra.branch-alias`, so the branch under test satisfies `>=v1.2` by itself.

### Removed
- `test-results/` is no longer tracked — it holds Playwright run artefacts, not module source.
