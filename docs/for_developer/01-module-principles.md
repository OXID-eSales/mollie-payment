# 01 — Module Principles

## Contract-first

"Continue" on the Mollie payment-selection step creates a **contract**, not an order. The OXID
order follows a fraction of a second later via payment-base's `EarlyOrderCreationHandler`
(priority 90), so an order number is available for Mollie's create-payment `description` field
before the API call happens.

## No signature — verification by re-fetch

Mollie's webhook carries no signature. `MollieWebhookProcessor` re-fetches the payment by id from
Mollie's API (authenticated with the shop's own secret key) and treats that authenticated
response as ground truth. See
[architecture/04-webhook-processing.md](../architecture/04-webhook-processing.md) and
`docs/security/f-matrix.md` (F18) for the full reasoning — this is not a weaker substitute for a
signature check, it's this provider's actual verification mechanism.

## Segregated adapter interfaces (ISP)

Five small Mollie adapter interfaces (`MolliePaymentsAdapterInterface`,
`MollieCaptureAdapterInterface`, `MollieRefundAdapterInterface`, `MollieWebhookAdapterInterface`,
`MollieMethodsAdapterInterface`) — never one monolith. A service imports only the slice it needs;
its unit test mocks only that slice.

## Amount bounds come from the API, not local arithmetic

`CaptureService`/`RefundService` derive their capture/refund ceilings from the **live Mollie
payment** (`getPayment()`), not from summing local transaction rows. This is a deliberate
divergence from payment-base's `AbstractPaymentCaptureService`/`AbstractPaymentRefundService`
templates — see the docblocks on those two classes for the full rationale (also documented in
`docs/dev_day_log/20260629/sprints/06-admin-actions.md`).

## Never suppress static analysis

PHPStan (level max) / PHPMD complaints are **fixed in code**, not suppressed. The only accepted
exceptions are framework-imposed: OXID virtual parent classes (`{Core}_parent`,
`@phpstan-ignore class.notFound` / `staticMethod.notFound`), and `Registry::getConfig()`'s virtual
methods (`@phpstan-ignore-next-line` with a comment naming the reason). Every such suppression in
this codebase carries an inline comment — grep for `@phpstan-ignore` to audit them all.

## Testability seams

Every class that touches OXID's `Registry`, `ContainerFactory`, or `ContainerFacade` exposes a
protected method a test subclass can override instead:

- `ModuleConfigurationService::readSetting()`
- `WebhookController::getGuard()` / `getFileLogger()` / `extractPaymentId()` / `sendResponse()`
- `MollieOrderController::resolveService()` / `readRequestParameter()`
- `PaymentController::resolveDispatcher()` / `redirect()` / `getSelectedPaymentId()`
- `ViewConfig::mollieMethodListService()` / `mollieConfigService()` / `mollieActiveCurrency()`

Pattern is consistent across the module — see any `Testable*.php` fixture under `tests/Unit/`.

## Idempotency everywhere

- Admin captures/refunds/cancels carry a deterministic idempotency key
  (`{contractId}:{action}[:amount]`, built by `MollieEventTranslator`) into the Mollie SDK's
  idempotency-key header — a duplicate admin click short-circuits at Mollie's API.
- Webhook events are de-duplicated via an atomic `claimEvent()` INSERT (payment-base
  `WebhookLogRepositoryInterface`) — never a check-then-save race.
- `WebhookContractFulfillmentHandler`'s multi-step ladder wraps every contract transition in a
  try/catch so an out-of-order or duplicate webhook delivery is a silent no-op, not a crash.

## Logging is merchant-controllable, not build-time

One setting (`sMollieLogLevel`: off/errors/normal/debug) gates both the backend file-audit-trail
channel (`MollieWebhookFileLoggerFactory` → `NullFileLogger` when off) and the frontend Stimulus
console wrapper (`resources/js/debug.js`, gated by `ViewConfig::isMollieDebugLoggingEnabled()`,
true only at `debug`). No redeploy or build-flag flip needed to turn diagnostics on/off in
production — see [architecture/00-overview.md](../architecture/00-overview.md).

## Known gaps — read before extending

- Vaulting/mandates and Mollie Connect (OAuth) are deliberately not built — see
  [architecture/00-overview.md](../architecture/00-overview.md#deferred--future-work).

## Memory rules

- Never hardcode payment id strings — always `MollieDefinitions::PAYMENT_ID` /
  `MollieDefinitions::MODULE_ID`.
- Never import `Mollie\Api\*` outside `src/Mollie/Adapter/` —
  `NoDirectSdkImportsRegressionTest` enforces this.
- Never call `PaymentContractInterface::setState()` — it doesn't exist; use the named transition
  methods only — `NoSetStateOnContractRegressionTest` enforces this.
- Never mock the OXID DAO chain in unit tests — use a protected seam (testable-subclass pattern).
- Always exclude DTOs / VOs / unbound-interface-deps from the `resource: 'src/Mollie/Service/*'`
  autowire sweep in `services.yaml` (see its inline comments for the current exclusion list and
  why each entry is there).
