# Dev log — 2026-07-03 (Mollie module)

## Built the Mollie payment module to Stripe/PayPal parity — all 8 sprints

Implemented the full 8-sprint roadmap from `../20260629/sprints/`. The module now installs,
activates, and runs the complete pay-by-redirect money path on top of `payment-base`, following
the PayPal reference architecture.

**Final state:** 118 `src/` PHP files, 82 test files, **375 tests (352 unit + 22 integration +
regression guards), 974 assertions**. All gates green: PHPCS (PSR-12), PHPStan level max,
PHPMD (strict), PHPUnit. `./bin/pre-commit-check.sh --full` → COMMITABLE. Module activates via
`oe:module:activate oe_payments_mollie` with the payment method installed.

### Sprints
1. **Scaffold & DevOps** — composer.json (payment-base path repo + `mollie/mollie-api-php ^2`),
   metadata.php (5 setting groups, extends ViewConfig/PaymentController), services.yaml, quality
   spine (phpcs/phpstan/phpmd/phpunit + pre-commit), Core (Module/Definitions/Events/ViewConfig)
   + PaymentMethodInstaller. Module installs + activates.
2. **Provider adapter layer** — 4 segregated ISP interfaces (Payments/Capture/Refund/Webhook,
   later +Methods) all aliasing ONE shared `LazyMollieAdapter` (built by `MollieAdapterFactory`,
   credentials read lazily); immutable DTOs; `MollieAdapter` = sole SDK translator; exception
   converter; `MollieStatusMapper`+`MollieOutcome`; `NoDirectSdkImports` + `NoSetStateOnContract`
   regression guards. Config service+validator pulled forward here to satisfy the DI container.
3. **Shop glue & config** — OxidSession/Shop/ShopOrderService adapters (payment-base contracts),
   `ModuleConfigurationService` (mode-aware key selection), `ConfigurationValidator`.
4. **Checkout flow** — `MolliePaymentController::execute()` dispatches one checkout-session event;
   contract-creation handler (prio 100) → shared EarlyOrderCreationHandler (prio 90) mints the
   NOT_FINISHED order → checkout-session handler (prio 10) creates the Mollie payment + stores the
   HMAC-token-secured redirect URL; token-checked, idempotent return via
   `MollieReturnResolver` (pure ReturnResolution); **central user-data validation** adopted from
   the shared `oepaymentvalidationapi` system (bound to module id, tagged formatter).
5. **Webhook pipeline** — `WebhookController` + guard chain (HTTPS/size/rate-limit; IP guard
   built but off by default); `MollieWebhookProcessor` verifies by **re-fetching the payment by
   id** (Mollie sends no signature); idempotency via `claimEvent`; paid/failed/expired/canceled +
   refunded/chargeback handlers; shared `ContractRefundRecorder` (delta-only) reused by admin;
   HTTP status contract (200/400/413/429/500) so Mollie retries correctly; DB-backed E2E
   integration test (only the outbound API call stubbed).
6. **Admin actions** — Refund/Capture/CancelAuthorization services (bounds from the live Mollie
   payment), admin request events+handlers, `MollieEventTranslator` (`oe.payment.event_translator`)
   mapping payment-base abstract requests → Mollie events with a deterministic idempotency key,
   `OxpaidReconciliationService`, `OrderActionDispatcher` (dispatches via EventBroker).
7. **Admin panel & frontend** — `MolliePaymentPanelProvider` (`oe.payment.admin_panel`, explicit
   deps), `TransactionHistoryService` (Mollie API = source of truth via new list methods +
   `MollieMethodsAdapterInterface`), admin amount validation reusing the formatter SPI, panel +
   storefront twig, and a real esbuild-built method-selector/redirect bundle (no stray console.log).
8. **Hardening & parity** — F1–F25 security matrix (`docs/security/f-matrix.md`): 17 covered / 8
   N/A-with-reason; closed a real **F14 open-redirect** gap (host-validate Mollie's checkoutUrl);
   level-gated webhook file-logger factory + frontend debug gating; Playwright E2E specs committed
   (not run — no sandbox key); architecture / for_developer / for_merchant docs. Vaulting + Mollie
   Connect explicitly deferred.

### Post-sprint gap fix
Wired `PaymentAuthorizedHandler`: an `authorized` webhook now advances the contract to AUTHORIZED
(idempotent, named transitions, no OXPAID/fulfill), making the two-step manual-capture admin flow
reachable end-to-end (was built + unit-tested in Sprint 6 but previously unreachable for a live order).

### Known limitations / deferred
- No live Mollie sandbox credentials in this environment: real redirect+webhook round-trip is
  covered by unit + DB-backed integration tests; the Playwright E2E is committed but must be run
  in CI/manually with a `test_` key.
- Vaulting/mandates + Mollie Connect (OAuth) deferred (no present requirement).
- Storefront method filtering: currency wired; country passed through but not yet resolved from
  the customer address.
- The 3 integration skips are the module-lifecycle/container tests that need public framework
  services (same pattern as PayPal/Stripe in this environment).

### Environment changes made
- Registered `oxid-esales/mollie-payment` as a path repo + require in the shop `composer.json`;
  `composer update` symlinked the module + pulled the Mollie SDK.
- Added `phpstan/phpstan ^2` + `phpmd/phpmd ^2` to the shop dev dependencies (the pre-commit gate
  expects them at `/var/www/vendor/bin`).
