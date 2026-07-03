# Sprint 1 — Scaffold & DevOps

**Goal:** An installable, activatable, empty Mollie module with the same DevOps spine as Stripe/PayPal.
**Definition of Done (sprint-level):** `oe:module:install` + `oe:module:activate oe_payments_mollie`
succeed on a clean shop; `./bin/pre-commit-check.sh` runs green on an empty `src/`; CI matrix passes.

## Out of scope
- Any payment logic, adapter, or Mollie SDK calls (Sprint 2+).
- Storefront/admin UI (Sprint 7).
- Webhook endpoint behavior (Sprint 5) — controller may be registered but returns 501/empty.

## Risks & unknowns
- **payment-base version constraint.** Confirm the exact constraint Stripe/PayPal pin
  (`v1.0.0 || dev-b-7.4.x`) and reuse it. De-risk: copy from `paypal/composer.json`.
- **Module id collision / activation hooks.** Wrong id breaks DI tag discovery. De-risk:
  integration test asserts metadata loads and module activates.

---

## Story 1 — Create composer.json + autoload + Mollie SDK dependency

**Why:** Nothing builds without the dependency graph and PSR-4 root. Honors "PSP SDK confined
to Adapter" by declaring the dep once, centrally.
**Estimate:** S

**Tests first (TDD):**
- `tests/Integration/Module/ComposerStructureTest.php`
  - `testComposer_RequiresPaymentBase`
  - `testComposer_RequiresMollieApiPhpSdk`
  - `testAutoload_MollieNamespaceMapsToSrcMollie`

**Implementation steps:**
1. `composer.json`: `name oxid-esales/mollie-payment`, type `oxideshop-module`, require `php ^8.2`,
   `oxid-esales/payment-base` (copy PayPal's constraint), `mollie/mollie-api-php ^2`, `doctrine/dbal ^2.13`.
2. `require-dev`: phpstan ^2, squizlabs/php_codesniffer ^3.10, phpmd/phpmd ^2.15, phpunit ^11.4,
   vfsstream, oxid-esales/oxideshop-ce dev-b-7.4.x, developer-tools.
3. PSR-4: `OxidEsales\Payments\Mollie\` -> `./src/Mollie`; tests namespace -> `./tests`.
4. Copy `composer` scripts block (`phpcs`, `phpstan`, `phpmd`, `style`) from Stripe.

**SOLID/Clean check:**
- SRP: composer.json declares dependencies only. DIP: depends on payment-base abstraction package.
- DRY: reuse PayPal/Stripe script block verbatim — no new tooling.

**DevOps gate:** `composer validate` ✓ · `composer install` resolves ✓.

**Definition of Done:** `composer install` resolves payment-base + Mollie SDK; autoloader maps the namespace.

---

## Story 2 — metadata.php (id, namespace, extends, controllers, settings, lifecycle)

**Why:** OXID discovers the module, its DI tags, settings groups, and controllers from metadata.
**Estimate:** M

**Tests first (TDD):**
- `tests/Integration/Module/MetadataTest.php`
  - `testMetadata_IdIsOePaymentsMollie`
  - `testMetadata_ExtendsViewConfigAndPaymentController`
  - `testMetadata_RegistersWebhookController`
  - `testMetadata_DeclaresFiveSettingGroups`
- `tests/Unit/Core/MollieDefinitionsTest.php`
  - `testPaymentId_IsStableConstant` (no hardcoded id strings elsewhere — PayPal rule)

**Implementation steps:**
1. `src/Mollie/Core/MollieDefinitions.php` — `MODULE_ID = 'oe_payments_mollie'`, payment id const.
2. `metadata.php`: id, title, version `0.1.0`, author; `extend` => ViewConfig, PaymentController
   (mirror PayPal's minimal 3-item extend; avoid extending OrderController if the view-controller
   service-tag pattern is available).
3. `controllers` => `MollieWebhookController`, `MollieConnect` (placeholder), footer widget key.
4. `settings` groups: `MOLLIE_GENERAL` (mode test|live, capture auto|manual, country/currency filters),
   `MOLLIE_TEST_CONFIG` (test API key), `MOLLIE_LIVE_CONFIG` (live API key),
   `MOLLIE_WEBHOOKS` (endpoint), `MOLLIE_LOGGING` (log level off|errors|normal|debug).
5. `events` => `Core\Events::onActivate / onDeactivate` (no-op stubs this sprint).

**SOLID/Clean check:**
- SRP: `MollieDefinitions` is the single source of id/constant truth (no hardcoded strings — PayPal rule).
- No overengineering: only the settings the happy path needs; no vaulting/Connect keys yet.

**DevOps gate:** `phpcs` ✓ · `phpstan` ✓ · Integration `MetadataTest` ✓.

**Definition of Done:** Module activates; `bin/oe-console oe:module:activate oe_payments_mollie` succeeds; settings render in admin.

---

## Story 3 — services.yaml skeleton + tag scaffolding

**Why:** The DI container is where a provider plugs into payment-base; an empty-but-valid
services.yaml that compiles is the foundation every later sprint appends to.
**Estimate:** S

**Tests first (TDD):**
- `tests/Integration/Module/ServicesContainerTest.php`
  - `testContainer_CompilesWithMollieServices`
  - `testContainer_AutowireExcludesDtoAndEnumAndInterfaces` (PayPal memory rule)

**Implementation steps:**
1. `services.yaml`: `_defaults` autowire/autoconfigure private; `resource: 'src/Mollie/**'`
   with `exclude` for `Dto/`, `Core/*Definitions`, unbound interfaces, `Model/`.
2. Declare the (currently empty) tag conventions in a comment block: `payment.event_handler`,
   `oe.payment.event_translator`, `oe.payment.admin_panel`.
3. `Core\Events` stub registered public if activation needs it.

**SOLID/Clean check:**
- DIP: container wires abstractions; DRY: exclusion list copied from PayPal's services.yaml.
- No overengineering: no service defined until a story needs it.

**DevOps gate:** Integration container-compile test ✓ · `phpstan` ✓.

**Definition of Done:** Container compiles with the module active; no autowire errors.

---

## Story 4 — Quality tooling: phpstan.neon, phpcs.xml, phpmd.xml, pre-commit-check.sh

**Why:** DevOps-first — the gate must exist before code does, so every later story can run it.
**Estimate:** M

**Tests first (TDD):**
- N/A (tooling config). Verification is "the commands run clean on the empty tree".

**Implementation steps:**
1. Copy `tests/phpcs.xml` (PSR-12, warning severity 0), `tests/PhpStan/phpstan.neon` (level max,
   bootstrap, empty baseline), `tests/PhpMd/phpmd.xml` (+ empty baseline) from Stripe; repath to Mollie.
2. Copy `bin/pre-commit-check.sh` (flags `--full`, `--no-phpunit`, `--changed-only`); repath.
3. `tests/phpunit.xml`: suites `Unit`, `Integration`; bootstrap; coverage path `src/`.
4. `tests/bootstrap.php`.

**SOLID/Clean check:**
- DRY: configs copied, not reinvented. No overengineering: no infection/mutation config yet.

**DevOps gate:** `composer phpcs` ✓ · `composer phpstan` ✓ · `composer phpmd` ✓ ·
`./bin/pre-commit-check.sh --no-phpunit` ✓.

**Definition of Done:** All four quality commands exit 0 on the scaffolded tree.

---

## Story 5 — CI workflow + README + activation smoke test

**Why:** Parity with PayPal's "CI runs the same matrix on every push"; proves install/activate end-to-end.
**Estimate:** M

**Tests first (TDD):**
- `tests/Integration/Module/ModuleLifecycleTest.php`
  - `testModule_InstallsAndActivatesCleanly`
  - `testModule_DeactivatesWithoutError`

**Implementation steps:**
1. `.github/workflows/*.yml`: jobs phpcs, phpstan, phpmd, phpunit-unit, phpunit-integration
   (mirror PayPal's 6-job matrix), trigger on push to `b-8.0.x`.
2. Flesh `README.md`: install/activate commands, quality commands, docs links, payment-base note.
3. `Core/Events::onActivate/onDeactivate` as verified no-ops (idempotent).

**SOLID/Clean check:**
- LSP: `Events` honors OXID activation hook contract (idempotent, no throw on re-activate).

**DevOps gate:** `./bin/pre-commit-check.sh --full` ✓ · CI green.

**Definition of Done:** Fresh shop: install + activate + deactivate cycle is green in CI and locally.

---

## Suggested order
1. Story 1 (composer) — unblocks everything; nothing autoloads without it.
2. Story 4 (quality tooling) — DevOps-first; needed to gate Stories 2-3-5.
3. Story 2 (metadata) — makes the module discoverable.
4. Story 3 (services.yaml) — container must compile before any service lands.
5. Story 5 (CI + smoke) — proves the spine end-to-end.
