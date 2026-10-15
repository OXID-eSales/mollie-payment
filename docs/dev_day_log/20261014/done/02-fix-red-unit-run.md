# Done 02 — Fix the red Unit run (review finding C-H1) — 2026-10-14

Branch: `b-7.4.x-REVIEW-01-fixes`.

## Cause

`tests/Unit/GraphQL/Controller/MollieCheckoutTest.php:46` did `new User('user-1')` — a signature
that only exists in the standalone stub (`tests/bootstrap-unit.php`). Under the shop's vendor dir
the real graphql-base `User` is final and wraps the shop user model, so all 7 tests errored with a
`TypeError` in the canonical in-container run.

Digging into "the gate must be trustworthy" surfaced two more drifts in `bin/pre-commit-check.sh`
itself (both reproduced on the clean branch, unrelated to any code change):

- its PHPUnit call overrode the config's bootstrap with the bare shop bootstrap
  (`--bootstrap=/var/www/source/bootstrap.php`), skipping `tests/bootstrap.php`'s registration of
  the module's `Tests\` autoloader → fatal "Trait PreloadsModuleClassChain not found";
- its PHPStan call forced `--level=max` while the neon (and `composer phpstan`, and CI) pin
  level 6 → 6 errors the canonical gate never reports.

## Fix

- Test builds the user the way the shop does: `createMock(EshopUserModel)` with `getId() → 'user-1'`
  passed to `new User($model)`. The shop model autoloads from the module's own vendor, so this
  works in both runs.
- `tests/bootstrap-unit.php` + `tests/PhpStan/phpstan-bootstrap.php`: the `User` stub now mirrors
  the real final-class signature (model-wrapping ctor, `id()` answers `getId()`); the stub
  `Authentication::getUser()` throws instead of silently constructing — mock it in tests.
- `bin/pre-commit-check.sh`: dropped the `--bootstrap` override and the `--level=max` override,
  with comments explaining why they must not come back.

## Proof

- Shop config: `OK (756 tests, 2060 assertions)` (was: 756 / 2022 with 7 errors — the suite now
  also *executes* the 38 assertions those tests carry).
- Standalone config: `OK (756 tests, 2060 assertions)` — identical counts in both runs for the
  first time.
- `./bin/pre-commit-check.sh`: ALL CHECKS PASSED (phpcs, phpunit 756, phpstan level 6, phpmd).
