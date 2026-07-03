# Mollie module — architecture analysis & build strategy

**Date:** 2026-06-29
**Scope:** How `stripe`, `paypal`, and `opalreturns` build on `payment-base`, and what a new
`mollie` provider must implement to reach Stripe/PayPal parity.
**Status today:** `mollie/` is a stub — only `README.md` ("A payment module for OXID 7.4+.
Dependent on the PaymentBase") and an empty `docs/`. Everything below is greenfield.

---

## TL;DR

`payment-base` is a provider-agnostic Smart-Contract payment core. A provider module supplies
roughly **5%** of the code — the PSP-specific adapter, services, events, webhook parsing,
admin panel, and frontend — and inherits the contract lifecycle, repositories, DB schema,
event dispatch, and shared handlers for free.

- **Reference to copy:** **PayPal**. It is the cleanest full-provider implementation
  (segregated adapter interfaces, lazy adapter, redirect-first UX) and Mollie's redirect-based
  flow maps onto it almost 1:1. Stripe is the richer/older sibling (one fat 26-method adapter
  interface — *do not copy that mistake*; PHPMD has it baselined as `TooManyMethods`).
- **Not a template:** **OpalReturns** — a returns/RMA module that only *optionally* couples to
  payment-base via `@?` DI + a `NullRefundIntentListener` factory fallback. It owns its own DB
  tables and has no PSP, no webhooks, no JS build. Useful only as the "optional consumer"
  contrast; Mollie is a full provider like PayPal.
- **Mollie owns NO migrations** — all tables come from payment-base.

---

## 1. The payment-base integration contract (what every provider plugs into)

### 1.1 Contract lifecycle (owned by payment-base)

```
DRAFT -> NOT_FINISHED -> PENDING -> AUTHORIZED -> READY_TO_COMMIT -> COMMITTED -> FULFILLED
                                       (manual capture stays at AUTHORIZED until admin captures)
Alternative endings: CANCELLED / EXPIRED / FAILED
```

Transitions happen **only** through named methods (`transitionToPending()`, `authorize()`,
`captureAuthorization()`, `commitToOrder()`, `fulfill()`, `cancel($reason)`, `fail($reason)`,
`expire()`). There is **no `setState()`** — PayPal guards this with
`NoSetStateOnContractRegressionTest`; Mollie should keep the same discipline.

Conditions (`payment_authorized`, `fraud_check`, `stock_reserved`) are added in DRAFT and
fulfilled asynchronously via `fulfillCondition($type, $data)`.

### 1.2 Tables payment-base owns (provider creates NONE)

`oe_payments_contract`, `oe_payments_transaction`, `oe_payments_customer`,
`oe_payments_idempotency`, `oe_payments_sessions`, `oe_payments_webhooklogs`.

Provider stores PSP-specific data in `oe_payments_contract.OXMETADATA` (JSON) via
`setMetadata()/getMetadata()` and links the PSP id through
`setProvider($name, $providerOrderId, $redirectUrl)`.

### 1.3 Interfaces a provider MUST satisfy

| payment-base interface | Role | Stripe impl | PayPal impl |
|---|---|---|---|
| `Adapter\SessionAdapterInterface` | session/basket read-write | `OxidSessionAdapter` | `OxidSessionAdapter` |
| `Adapter\ShopAdapterInterface` | shop config, language, currency, test-mode | `OxidShopAdapter` | `OxidShopAdapter` |
| `Adapter\ShopOrderServiceInterface` | order create/finalize | `OxidShopOrderService` | `OxidShopOrderService` |
| `EventSystem\Handler\*HandlerInterface` | event handlers (tagged) | 11 handlers | 9 handlers |
| `Return\ReturnResolverInterface` | resolve customer return-from-PSP | `StripeReturnResolver` | (return flow) |

> The three `Oxid*Adapter` implementations are near-identical across Stripe and PayPal — Mollie
> should start by **copying PayPal's** and adjusting only PSP touch-points.

### 1.4 DI tags that wire a provider into the core

| Tag | Purpose | Required for Mollie? |
|---|---|---|
| `payment.event_handler` | handler collected by the EventBroker | **Yes** (~8 handlers) |
| `oe.payment.event_translator` | maps abstract `*RequestedEvent` -> provider concrete event | **Yes** (admin capture/refund/cancel) |
| `oe.payment.admin_panel` | renders into the shared admin "Payment" tab | **Yes** |
| `oe.payment.handler` | one-page-checkout (OPC) integration | If OPC support wanted |

### 1.5 Abstract bases a provider extends (Template Method)

- `Service\AbstractPaymentCaptureService` / `AbstractPaymentRefundService` — hooks
  `validateStateFor...()` / `after...()`.
- `Service\Factory\AbstractFileLoggerFactory` — provider sets log filename + prefix; gating
  closure `?\Closure $isEnabled` controls on/off (see Stripe's 2026-06-24 logging sprint).
- `Webhook\AbstractWebhookProcessor` — Template Method: `getProviderName()`,
  `parseAndValidateRequest()`, `processEvent()`, `getContractIdFromResult()`.
- `EventSystem\Handler\ContractCreationHandler` (abstract) — provider subclass stores PSP
  metadata then dispatches `ContractCreatedEvent`.

### 1.6 Shared handlers the provider gets for free (wired in services.yaml)

`EarlyOrderCreationHandler` (early NOT_FINISHED order, priority 100/90),
`PaymentAuthorizedEventHandler` (90), `ContractCommitmentHandler` (80),
`ContractPendingTransitioner` (100), `TransactionRecordingHandler` (10),
`OrderPaymentCompletedHandler` (OXPAID stamping). Mollie writes the PSP-touching handlers;
these orchestrators stay untouched.

---

## 2. How the three modules compare

| Concern | Stripe | PayPal | OpalReturns | Mollie target |
|---|---|---|---|---|
| Role | full provider | full provider | returns/RMA (optional PB) | **full provider** |
| payment-base coupling | hard dep | hard dep | optional (`@?` + Null fallback) | hard dep |
| SDK | `stripe/stripe-php ^19` | `paypal/paypal-server-sdk ^2` | none | `mollie/mollie-api-php ^2` |
| Adapter interface shape | **1 x 26-method** (PHPMD baselined) | **4 segregated** (<=4 methods each) | n/a | **copy PayPal: segregated** |
| Lazy SDK client | factory | `LazyPayPalAdapter` decorator | n/a | `LazyMollieAdapter` decorator |
| Auth | secret key (test/live) | OAuth client id/secret + webhook id | n/a | **API key `test_`/`live_`** (+ OAuth for Connect, later) |
| Webhook verification | HMAC signature `constructEvent()` | signature verifier | none | **fetch-by-id from API** (Mollie sends only `id`; no signature) |
| Webhook idempotency | `oe_payments_idempotency` claim | `oe_payments_idempotency` claim | n/a | same |
| Frontend build | esbuild | Rollup + `@paypal/paypal-js` | none | esbuild/Rollup + `@mollie/components` |
| Admin tab | `StripePaymentPanelProvider` | `PayPalPaymentPanelProvider` | own controllers | `MolliePaymentPanelProvider` |
| Migrations | none | none | 3 (own tables) | **none** |
| User-input validation (central) | **uses** (Sprint 119-124) | **does NOT** | n/a | **adopt like Stripe** |
| Tests | ~99 unit / 18 integ | 70 unit / 4 integ + E2E | 50 unit / 6 integ | grow to ~60+ unit |

### 2.1 The single biggest design decision: copy PayPal, not Stripe

PayPal's `03-provider-abstraction.md` is explicit: it split the SDK behind **four <=4-method
interfaces** (`PayPalOrdersAdapterInterface`, `...AuthorizationAdapterInterface`,
`...RefundAdapterInterface`, `...WebhookAdapterInterface`) specifically to avoid Stripe's one
26-method `StripeAdapterInterface`. Mollie follows PayPal:

```
MolliePaymentsAdapterInterface     createPayment / getPayment / cancelPayment
MollieCaptureAdapterInterface      createCapture            (two-step methods only)
MollieRefundAdapterInterface       createRefund / getRefund
MollieWebhookAdapterInterface      fetchByWebhookId         (verification = re-fetch)
```

All four alias the **same** `LazyMollieAdapter` instance (one SDK client / OAuth handshake per
request), exactly as PayPal wires its four interfaces to one `LazyPayPalAdapter`.

---

## 3. Mollie-specific facts that change the flow vs Stripe/PayPal

1. **No webhook signature.** Mollie's webhook is a bare `POST` with `id=tr_xxxx`. Security =
   *fetch that payment from the Mollie API and trust the API's status*. So Mollie's
   `parseAndValidateRequest()` is an **API round-trip**, not an HMAC check. The guard chain
   (HTTPS / payload-size / rate-limit) still applies; the IP-allowlist guard is weaker
   (Mollie does not publish fixed ranges) — gate it behind config, default off.
2. **Redirect-first UX.** Create Payment -> receive `_links.checkout` -> 302 the customer to
   Mollie -> they return to `redirectUrl`; the webhook (not the redirect) is the source of
   truth. This is exactly PayPal's *redirect fallback* path — start there, add JS
   (`@mollie/components` card embedding) later as the Smart-Buttons-equivalent enhancement.
3. **Capture is method-dependent.** Most Mollie methods auto-capture; two-step capture exists
   only for cards/Klarna ("authorize" + later capture). The `AUTHORIZED` ->
   `captureAuthorization()` branch is needed but only exercised for those methods — gate the
   admin Capture form on capture-mode + method capability.
4. **Refunds** map cleanly to `AbstractPaymentRefundService` (full/partial, accumulating).
5. **Payment methods** (iDEAL, Bancontact, cards, PayPal-via-Mollie, Klarna, SEPA...) are
   listed via the Methods API and surface as the storefront selector — a Mollie concern with
   no Stripe equivalent (Stripe Checkout hosts its own method picker).

---

---

## 3a. Central user-input validation system (Stripe uses it; PayPal does NOT)

A distinct, security-relevant payment-base subsystem (built in Stripe's Sprint 119-124 / STRP-129)
that **Stripe adopted and PayPal skipped** — Mollie should adopt it like Stripe.

**payment-base provides** (`src/Validation/**`, `src/Controller/ValidationApiController.php`):
- A **shared frontend endpoint** `index.php?cl=oepaymentvalidationapi&fnc=validate` for all PSP
  modules. Returns JSON `{valid, errors:[{field, code, char, message}]}`.
- A **7-guard chain** run in priority order before any work: `PostOnlyGuard`, `PayloadSizeGuard`,
  `ActiveSessionGuard`, `SameOriginGuard`, `CsrfTokenGuard`, `RateLimitGuard`,
  `PluginIdAllowlistGuard` (the last just checks *"is this module active"* via
  `ActiveModuleQueryInterface` — so **no central allowlist registration is needed**).
- The `ValidationBase` character-class engine + `ValidationRuleLoaderInterface`
  (`FilesystemValidationRuleLoader` loads a plugin's `validation-rules.php`).
- Extension tags: `oe.payment_base.validation_message_formatter` (message formatting) and
  `oe.payment_base.rate_limit_override` (optional per-module rate limit).

**What a provider supplies (Stripe's ~5%):**
- `src/Resources/validation-rules.php` — per-field allow/block character rules (unicode-letter
  aware: umlauts oeaeuess, Polish letters pass; injection chars blocked).
- `ValidationRulesProvider` + a module-id-bound `ValidationBaseInterface` in `services.yaml`.
- `UserDataValidator` (server-side, in the checkout controller before the PSP call) +
  `UserDataValidationMessageFormatter` (tagged).
- Frontend posts to the shared endpoint with its `pluginModuleId` for live field validation.

**Why it matters for Mollie:** Mollie's create-payment carries customer name/address and OXID
collects those user fields at checkout — that input needs the shared anti-injection validation.
Mollie should **reuse** this system (one rules file + bindings + a tagged formatter), not roll its
own and not skip it like PayPal. Planned in **Sprint 4 Story 6** (storefront/server-side), reused
by **Sprint 7 Story 3** (admin message formatting), and a parity line item in **Sprint 8 Story 1**.

## 4. Inventory Mollie must build (~45-55 files)

```
mollie/
  composer.json            require: payment-base + mollie/mollie-api-php
  metadata.php             id oe_payments_mollie; extend ViewConfig, PaymentController,
                           (CoreModuleConfiguration); controllers Webhook + Connect;
                           settings MOLLIE_GENERAL / _TEST / _LIVE / _WEBHOOKS / _LOGGING
  services.yaml            adapter aliases, factory, tagged handlers, translator, panel
  menu.xml                 (empty — admin tab is shared, owned by payment-base)
  src/Mollie/
    Adapter/               4 segregated ifaces + LazyMollieAdapter + MollieClientFactory
      Oxid{Session,Shop}Adapter, OxidShopOrderService   (copy from PayPal)
      Dto/                 MolliePaymentDto, MollieRefundDto, MollieAmountDto ...
      MollieStatusMapper   (Mollie status -> contract state)
      MollieWebhookEvent   (implements payment-base WebhookEvent)
    Service/               ModuleConfigurationService, ConfigurationValidator,
                           CheckoutPaymentService, CheckoutReturnService,
                           Capture/Refund/CancelAuthorization services,
                           OxpaidReconciliationService, TransactionHistoryService,
                           Factory/MollieAdapterFactory + file-logger factories
    EventSystem/
      Event/               MollieCheckoutSessionRequestEvent, MollieCheckoutReturnEvent,
                           Mollie{Capture,Refund,CancelAuthorization}RequestEvent ...
      Handler/             MollieContractCreationHandler, MollieCheckoutSessionHandler,
                           Mollie{Capture,Refund,CancelAuthorization}RequestHandler
      Translator/          MollieEventTranslator (oe.payment.event_translator)
    Webhook/               MollieWebhookProcessor + PaymentPaid/Failed/Expired/Refunded/
                           ChargebackCreated handlers + WebhookContractFulfillmentHandler
    Controller/
      Webhook/             WebhookController + guard chain (HTTPS/size/rate-limit)
      Admin/               MollieConnect, OrderActionDispatcher, view-data provider
      MolliePaymentController, MollieOrderController
    Admin/                 MolliePaymentPanelProvider + view-data builder
    Core/                  MollieDefinitions, MollieViewConfig, Events (activate/deactivate)
    Model/                 Order, Payment extensions
  resources/ + assets/     JS build (method selector, redirect, optional card components)
  views/twig/              storefront method selector + admin panel templates
  tests/                   Unit / Integration / e2e + phpunit.xml, phpstan.neon, phpcs.xml,
                           phpmd.xml, bin/pre-commit-check.sh
```

### Free from payment-base (no Mollie code)
Contract state machine + repository, conditions, `BasketSnapshot`, event dispatcher +
`EventListenerProvider`, all 6 DB tables, idempotency claim, shared orchestrator handlers,
token/HMAC return-security primitives, admin-panel registry + shared tab shell.

---

## 5. Quality bar (inherited project rules)

Every commit: **TDD-first** (failing test precedes impl), **PSR-12** (`composer phpcs`),
**PHPStan level max** no new baseline (`composer phpstan`), **PHPMD** clean (`composer phpmd`),
green `phpunit` Unit suite, `./bin/pre-commit-check.sh` before staging. SOLID + no-else +
15-25-line methods + DI-only (no `Registry::getConfig()` reach-ins in services) + **no
overengineering** (no abstraction without a present caller; one impl before an interface, two
before a base class). Commit messages end with the `Co-Authored-By` trailer.

See the sprint roadmap in `../sprints/00-roadmap.md`.
