# 02 — payment-base Dependency

## Interface mappings

| payment-base interface | Mollie implementation | Registered in |
|-------------------------|------------------------|---------------|
| `SessionAdapterInterface` | `OxidSessionAdapter` | `services.yaml` |
| `ShopAdapterInterface` | `OxidShopAdapter` | `services.yaml` |
| `ShopOrderServiceInterface` | `OxidShopOrderService` | `services.yaml` |
| `TokenServiceInterface` | `ContractTokenService` | `services.yaml` |
| `ReturnResolverInterface` | `MollieReturnResolver` | `services.yaml` |
| `HandlerInterface` | `MollieContractCreationHandler`, `MollieCheckoutSessionHandler`, `MollieRefundRequestHandler`, `MollieCaptureRequestHandler`, `MollieCancelAuthorizationRequestHandler` (all tagged `payment.event_handler`) | `services.yaml` |
| `ContractCreationHandler` (abstract) | `MollieContractCreationHandler` extends it | — |
| `ProviderEventTranslatorInterface` | `MollieEventTranslator` (tagged `oe.payment.event_translator`) | `services.yaml` |
| `PaymentPanelProviderInterface` | `MolliePaymentPanelProvider` (tagged `oe.payment.admin_panel`) | `services.yaml` |
| `Service\Factory\AbstractFileLoggerFactory` (abstract) | `MollieWebhookFileLoggerFactory` extends it | `services.yaml` |

## payment-base handlers Mollie re-tags itself

payment-base does not self-tag its own shared handlers — each consuming module decides which ones
apply. Mollie explicitly tags, in its own `services.yaml`:

- `EarlyOrderCreationHandler` (priority 90) — creates the `NOT_FINISHED` order.
- `OrderPaymentCompletedHandler` (priority 0) — stamps OXPAID when `ContractFulfilledEvent` fires.

Mollie deliberately does **not** tag `PaymentAuthorizedEventHandler` or
`ContractCommitmentHandler` — commit/fulfillment for Mollie is left entirely to the webhook
pipeline (see `services.yaml`'s comment above the webhook section). The `AUTHORIZED` state itself
is reached by the webhook pipeline too: `PaymentAuthorizedHandler` (tagged `mollie.webhook_handler`)
handles Mollie's `authorized` status and calls `WebhookContractFulfillmentHandler::handlePaymentAuthorized()`,
which is what makes `CaptureService`/`CancelAuthorizationService` reachable for a real two-step
payment — see [architecture/00-overview.md](../architecture/00-overview.md).

## Consumed interfaces (we never implement these)

- `ContractRepositoryInterface` — provided by payment-base; consumed by nearly every service and
  handler in this module.
- `TransactionRepositoryInterface` — bound in `services.yaml` (see its comment on `public: false`
  to match Stripe's binding of the same shared interface in this dev shop's merged container).
- `WebhookLogRepositoryInterface` — consumed by `MollieWebhookProcessor` for idempotency claims.
- `EventDispatcherInterface` / `EventBrokerInterface` — consumed by `PaymentController` (direct
  dispatch) and `Controller/Admin/OrderActionDispatcher` (via the broker), respectively.
- `PaymentContractInterface` — consumed ubiquitously; mutated only via named transition methods,
  never `setState()` (there is no such method — `NoSetStateOnContractRegressionTest` guards this).
- `ContractFulfillmentServiceInterface` — consumed by `WebhookContractFulfillmentHandler` for the
  final `COMMITTED → FULFILLED` step.
- The central validation subsystem: `ValidationBaseInterface`, `ValidationRuleLoaderInterface`,
  the seven `ValidationGuardInterface` implementations, `MessageFormatterInterface` — see
  [../../src/Resources/validation-rules.php](../../src/Resources/validation-rules.php) and
  `UserDataValidator`/`UserDataValidationMessageFormatter` for Mollie's wiring into it.

## Database schema

**All payment tables are created by `payment-base`.** This module has **no migrations of its
own**: `oe_payments_contract`, `oe_payments_transaction`, `oe_payments_webhooklogs` (and whatever
else payment-base defines). If payment-base's schema evolves, this module benefits automatically.

## Contract state vocabulary

`draft · not_finished · pending · authorized · ready_to_commit · committed · fulfilled ·
cancelled · expired · failed`.

Reached only via named transition methods: `transitionToNotFinished()`, `transitionToPending()`,
`authorize()`, `captureAuthorization()`, `fulfillCondition()`, `commitToOrder()`, `fulfill()`,
`cancel($reason)`, `fail($reason)`, `expire()`. There is **no** `setState()` — regression-guarded
by `tests/Unit/Architecture/NoSetStateOnContractRegressionTest.php`.

## Abstract templates Mollie deliberately does NOT extend

- `Service\AbstractPaymentCaptureService` / `AbstractPaymentRefundService` — Mollie's
  `CaptureService`/`RefundService` implement their own focused interfaces instead, because the
  templates' default bound-calculation hooks use local ledger arithmetic and the generic
  (non-segregated) `PaymentAdapterInterface`; Mollie's Sprint 6 risk analysis requires bounds
  sourced from the live Mollie payment via the ISP-segregated adapters. See the docblocks on
  those two classes for the full reasoning (mirrors Stripe's identical decision).
