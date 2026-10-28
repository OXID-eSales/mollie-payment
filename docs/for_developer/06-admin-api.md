# Mollie admin API (for `oxid-admin-graphql`)

The Mollie module adds its settings screen and its part of the Payment tab to the admin GraphQL API of
`oxid-admin-graphql`. It builds on payment-base's admin payment API (`payment-base/docs/admin-payment-api.md`): the
rights, the step-up service, `AdminPaymentContract`, the idempotency store and the provider interfaces. The pattern is
the PayPal module's (`paypal/docs/for_developer/06-admin-api.md`).

**Everything is optional.** The module installs, activates and passes its suites without `oxid-admin-graphql`: the admin
API's services are reached only through `@?`, nothing of it is autowired, the classes implement nothing of it, and
`GraphQL\Service\NamespaceMapper` maps the controllers, types and the contract extension only when `AdminOrder` (admin API)
**and** payment-base's `AdminPaymentContract` exist. `tests/Support/admin-api-stubs.php` are the stand-ins of the standalone
unit suite and PHPStan; `Integration\GraphQL\Admin\AdminModuleParityTest` proves them against the real classes;
`Unit\GraphQL\Admin\AdminApiIsOptionalTest` proves every service class loads in a PHP process where none of the optional
packages exist. After a change of the schema: `bin/oe-console oe:graphql:cache-clear` (and delete
`var/cache/container/container_cache_shop_*.php` after a change of `services.yaml`).

Requires payment-base with its PAY-1 provider API (`AdminActionOptionsProviderInterface`, `AdminActionBoundsProviderInterface`,
`ContractStateHelpProviderInterface`, `AdminActionRefusedException`; `@since 1.7.0`).

## Operations

| Operation | Right | Step-up | Does |
| --- | --- | --- | --- |
| `adminMollieConfig: AdminMollieConfig!` | `ADMIN_PAYMENT_CONFIG` | no | `mode` (`test`/`live`), `captureMode`, `multiCapture`, `profileId`, `webhookUrlOverride`, `effectiveWebhookUrl` (read-only), `logLevel`, `testKeySet`, `liveKeySet`, `version` |
| `adminMollieConfigModify(input: AdminMollieConfigInput!): AdminMollieConfigPayload!` | `ADMIN_PAYMENT_CONFIG` | switching to live, writing any key | changes settings; `{ config, errors }` |
| `adminMollieConnectionCheck: AdminMollieConnectionCheck!` | `ADMIN_PAYMENT_CONFIG` | no | `GET /v2/profiles/me` with the active key: `status` `ok` / `invalid_key` / `unreachable` / `not_configured`, `ok`, `mode` |
| `AdminPaymentContract.mollie: AdminMolliePaymentDetails` | `ADMIN_PAYMENT_VIEW` | no | `paymentId`, `dashboardUrl`, `methodLabel`, `methodDetail`, `mollieStatus`, `amountReleased`, `amountReleasable`, `multiCapture`, `apiError` (null for a contract of another provider) |
| `adminMolliePaymentRelease(orderId: ID!, reason: String, idempotencyKey: String): AdminPaymentOperationPayload!` | `ADMIN_PAYMENT_CAPTURE` | yes | releases the still-authorized remainder after partial captures (MOL-32) |

### API keys are write-only

No type has a field that could carry a key: the config says `testKeySet` / `liveKeySet` and nothing else
(`AdminMollieConfigTypesTest`, the schema test, and the integration test that greps a real answer for the shop's stored keys).
The input takes `testKey` / `liveKey`; **empty or absent means unchanged**. A key is a field name in the audit log, never a
value; `MollieConfigChange` marks its keys `#[SensitiveParameter]`; validation messages never quote a value; the
connection check's log carries only the outcome and the HTTP status.

### `adminMollieConfigModify`

1. **Step-up** (payment-base's `AdminStepUpInterface`) when the call switches to `live` or writes any key.
2. **Optimistic lock**: `expectedVersion` is the `version` of the config read (a hash of the settings, not of the derived
   webhook URL). A mismatch is a `ConflictError`, nothing is written.
3. **Validation** (`MollieConfigChangeValidator`): enums (`not_allowed`), key prefix `test_` / `live_` (`invalid_prefix`) and
   format (`invalid_format`, at least 30 characters), `pfl_` profile id (empty removes it), http(s) webhook override (empty
   removes it), switching to a mode without a stored key (`key_missing`). Nothing is saved when any check fails.
4. The write goes through OXID's module setting service, the path of the classic form. One audit row per call
   (`adminMollieConfigModify`, entity `paymentProvider` / `mollie`, field names only), success or failure.

### `AdminPaymentContract.mollie`

One live read of the Mollie payment per request (the classic panel's memoized `MolliePaymentSnapshotProvider`). Mollie
unreadable: `apiError: "unreachable"` and `methodLabel`, `methodDetail`, `mollieStatus`, `amountReleasable` are null (never a
guess); `paymentId`, `dashboardUrl`, `amountReleased` (the shop's record) stay. A contract without a Mollie payment id:
`apiError: "no_payment"`, no Mollie call. `Admin\ReleaseRule` and `Admin\PaymentMethodLabelResolver` are shared with the
classic panel.

### `adminMolliePaymentRelease`

The rules are the classic button's, not a copy: the order's panel provider runs its own `release` action through payment-base's
provider-action boundary (`ReleaseRule`, free-text validation, the Mollie release event). The operation adds step-up, the
request's shop, an idempotency record (`admin_mollie_release`, 24 h, payment-base's store), one audit row (`adminMolliePaymentRelease`,
entity `order`, field name `reason` only) and a read-back of the contract (`not_confirmed` when nothing moved). Errors in the
payload: `not_found`, `invalid_key`, `too_long`, `invalid_characters` (reason, 140), `not_releasable` (other provider),
`in_progress`, `provider_refused` (the sentence, e.g. nothing captured yet), `provider_failed`, `provider_unavailable`,
`not_confirmed`. A missing right, session or step-up is a GraphQL error with `extensions.errorCode`.

## PAY-1 provider API

- `MolliePaymentPanelProvider::actionOptions()`: refund reasons `requested_by_customer`, `duplicate`, `fraudulent`; void reasons
  and `abandoned`; refund description; 140 characters (constants in `MollieDefinitions`, pinned to the Twig form by
  `AdminActionOptionsTemplateParityTest`).
- `boundsFor()`: the live capture remainder (multi-capture) and `RefundBound`; `unavailable()` when Mollie cannot be read; null
  while there is no Mollie payment yet.
- `MollieContractStateHelp`: tagged `oe.payment.admin_state_help`, column "Mollie".
- Typed refusals: amount, free-text and release refusals are recorded for the classic tab and thrown as
  `AdminActionRefusedException` with `feedbackRecorded: true` (`Admin\AdminActionRefusals`). Mollie API errors inside the capture,
  refund and cancel handlers are not typed (payment-base's dispatcher interface returns void): they appear as `not_confirmed`.

## Tests

- Unit (standalone, `tests/phpunit-unit.xml`): `tests/Unit/GraphQL/Admin/**`, `tests/Unit/Admin/**`.
- Integration (the shop): `tests/Integration/GraphQL/Admin/**`: schema, wiring, parity, no stored key in a real answer, and the
  Mollie panel provider behind payment-base's real operations with the real database (only Mollie faked).
- The not-authorized matrix (Playwright API) lives in the admin SPA's e2e suite (PAY-6); the money flow there needs a real Mollie
  test key.
