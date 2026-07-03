# 01 — Architecture Layers

Layered view, no on-page SDK layer (Mollie has none — see [03-provider-abstraction.md](./03-provider-abstraction.md)).
Each layer talks only to the layer immediately below it (DIP). Events cross layers horizontally;
they never bypass a layer.

```
┌──────────────────────────────────────────────────────────────────┐
│ 1. UI layer                                                      │
│    Twig method-selector partial, Stimulus controller, admin panel│
│    Files: views/twig/**, resources/js/**                         │
├──────────────────────────────────────────────────────────────────┤
│ 2. Controller layer                                              │
│    PaymentController (intercepts selection+redirect),            │
│    MollieOrderController (return leg), Webhook/WebhookController,│
│    Admin/OrderActionDispatcher                                   │
├──────────────────────────────────────────────────────────────────┤
│ 3. Event system                                                  │
│    MollieCheckoutSessionRequestEvent, MollieRefundRequestEvent,  │
│    MollieCaptureRequestEvent, MollieCancelAuthorizationRequestEvent│
│    + Handlers, dispatched by payment-base's EventDispatcher      │
├──────────────────────────────────────────────────────────────────┤
│ 4. Domain services                                                │
│    CheckoutPaymentService, CaptureService, RefundService,        │
│    CancelAuthorizationService, TransactionHistoryService,        │
│    OxpaidReconciliationService, ContractTokenService,             │
│    MollieRedirectUrlValidator, ContractRefundRecorder            │
├──────────────────────────────────────────────────────────────────┤
│ 5. Adapter layer (segregated interfaces, DIP boundary)           │
│    MolliePaymentsAdapterInterface / MollieCaptureAdapterInterface│
│    MollieRefundAdapterInterface / MollieWebhookAdapterInterface  │
│    MollieMethodsAdapterInterface                                 │
│    Concrete: MollieAdapter (+ LazyMollieAdapter decorator)       │
├──────────────────────────────────────────────────────────────────┤
│ 6. External world                                                │
│    Mollie REST API via mollie/mollie-api-php ^2                 │
│    OXID shop via OxidShopAdapter / OxidSessionAdapter /          │
│    OxidShopOrderService                                          │
└──────────────────────────────────────────────────────────────────┘
```

Mollie collapses PayPal's separate "provider-specific services" layer (layer 5 in PayPal's
model) into the domain services themselves: `CaptureService`/`RefundService` call the segregated
adapters directly rather than through an intermediate `MolliePaymentService` wrapper, because
Mollie's API surface per operation is a single call, not a multi-step SDK choreography.

## Key boundary rules

- **No Mollie SDK imports outside `src/Mollie/Adapter/`** — enforced by
  `tests/Unit/Architecture/NoDirectSdkImportsRegressionTest.php`.
- **No `PaymentContractInterface::setState()`-style back-door mutation** — the contract only ever
  moves through its named transition methods — enforced by
  `tests/Unit/Architecture/NoSetStateOnContractRegressionTest.php`.
- **Services depend on interfaces** (`MolliePaymentsAdapterInterface` etc.) — never on the
  concrete `MollieAdapter`.
- **`CaptureService`/`RefundService` derive their bounds from the live Mollie payment** (API
  truth), not from local ledger arithmetic — see [00-overview.md](./00-overview.md) and
  `docs/security/f-matrix.md` (F15).
- **Handlers orchestrate services** — no SDK calls, no direct DB access (repositories only).
- **Controllers stay thin** — dispatch event / run guard chain, inspect context or result, render
  or redirect. `WebhookController` never touches a contract directly; it hands off entirely to
  `MollieWebhookProcessor`.

## Testability seams

| Seam | Purpose |
|------|---------|
| `PaymentContractInterface` | mock contract in unit tests without touching DB |
| Segregated adapter interfaces (Payments / Capture / Refund / Webhook / Methods) | mock one slice without pulling the whole SDK |
| `ModuleConfigurationService::readSetting()` (protected) | testable subclass pattern; no DAO mocks |
| `WebhookController::getGuard()` / `getFileLogger()` / `extractPaymentId()` (protected) | testable subclass (`TestableWebhookController`) bypasses `ContainerFactory`/`Registry` |
| `MollieOrderController::resolveService()` (protected) | swaps `ContainerFacade::get()` for a test double |
| `ViewConfig::mollieMethodListService()` / `mollieConfigService()` (protected) | frontend view-data tests skip the container |
