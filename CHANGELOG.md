# Changelog

All notable changes to this module are documented here. Format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions adhere to [SemVer](https://semver.org/).

## [Unreleased]

### Fixed
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
