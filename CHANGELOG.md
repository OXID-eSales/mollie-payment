# Changelog

All notable changes to this module are documented here. Format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions adhere to [SemVer](https://semver.org/).

## [Unreleased]

### Added
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
