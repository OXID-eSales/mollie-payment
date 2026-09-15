# Changelog

All notable changes to this module are documented here. Format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions adhere to [SemVer](https://semver.org/).

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
- The test namespace moved out of the production autoloader into `autoload-dev`.
- `package.json` carries an author and `SEE LICENSE IN LICENSE`; it was already marked private.

### Removed
- `test-results/` is no longer tracked — it holds Playwright run artefacts, not module source.
