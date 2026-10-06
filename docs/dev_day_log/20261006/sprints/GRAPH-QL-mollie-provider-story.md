# GRAPH-QL — Mollie provider story (P-Mollie) — 2026-10-06

**Branch:** `b-7.4.x-GRAPH-QL` (mollie-payment, cut from `b-7.4.x` = `3ca028e`). **Requires** payment-base
`b-7.4.x-GRAPH-QL` (Sprint 15, S1–S8 + follow-ups; merged into `b-7.4.x` before this lands — Mollie's CI installs
payment-base from `PAYMENT_BASE_BRANCH`, pinned TEMPORARILY to the GraphQL branch with the `as 1.2.x-dev` alias Stripe
uses). Template: Stripe's story `stripe/docs/oe_payments_docs/daniil_dev_log/20261006/sprints/GRAPH-QL-stripe-provider-story.md`
(PS1–PS7) and payment-base's hand-over `docs/dev_log/20261006/done/sprint-15-S8-gates-consumers-handover.md`.
**Requirements:** payment-base `docs/dev_log/20260903/sprints/_engeneering_requirements.md` (TDD-first, DevOps-first,
proven through the client, additive, agnostic where it can be).

## Ask

Mollie becomes usable from the GraphQL Storefront and from agents through payment-base's headless checkout: the
mutations `mollieCheckoutStart / mollieCheckoutReturn / mollieCheckoutCancel`, a handler that works without a PHP
session, webhooks that end the order (paid **and** authorized), and the Twig / OPC checkouts byte-identical.

## Where Mollie stands (module map, 2026-10-06)

- One OXID payment `oe_payments_mollie` (EUR, no country restriction); Mollie methods are a `selectedMethod` hint in
  the event context, the hosted page offers all methods otherwise. Inline card entry needs Mollie Components in a
  browser (`cardToken`); `renderMode` is always `redirect` (Mollie's page refuses framing).
- `MolliePaymentHandler::processPayment()` reads the **session** three times: `prepareOxidBasket()`
  (`Registry::getSession()->getBasket()`, `setVariable('paymentid')`), `buildEventContext()` (`sessionId` from the
  session, no `basketId`), and `MollieOrderDataProvider::basket()` (pay-later order lines / address).
- `MollieCheckoutSessionHandler::buildRedirectUrl()` is fixed to `cl=order&fnc=checkoutReturn&contract_id&contract_token`;
  the client's `returnUrl` / `cancelUrl` are never used. Mollie has one redirect URL for every outcome.
- `MollieReturnResolver` exists but carries **no** `oe.payment.return_resolver` tag; Mollie's `EarlyOrderCreationHandler`
  definition passes no `$openAttemptFinder`.
- Webhooks: `paid` commits a PENDING contract through a hand-written ladder and fulfils (OXPAID). `authorized` only
  moves the contract to AUTHORIZED: the order stays `NOT_FINISHED` until the merchant captures and `paid` arrives.
  `CaptureService` accepts AUTHORIZED **and** COMMITTED, so committing an authorization is safe for the admin capture.
- Mollie's test mode has a hosted page where the tester picks the outcome (paid / failed / …) and webhooks reach the
  tunnel (`https://daniil.oxiddev.de`) — unlike Stripe in this dev shop, the webhook proof can be live.

## Stories

| # | Story | Red first | Green | Proof |
|---|---|---|---|---|
| MS1 | **Headless-ready handler.** `MolliePaymentHandler implements ContractFirstPaymentHandlerInterface`; with `metadata.headless` it takes basket and user from the `PaymentContext` (no session read or write), puts `basketId`, `sessionId` (`headless:<basketId>`), `headless`, `returnUrl` and the optional method hint into the event context; `MollieCheckoutSessionHandler` uses the client's `returnUrl` (+ `contract_id`) as Mollie's `redirectUrl` for a headless contract; `MollieOrderDataProvider` takes the basket from the event context when present; `uiMode` other than `hosted` refused (`MOLLIE_UI_MODE_UNSUPPORTED`) before anything is created. payment-base (additive): `HeadlessStartRequest::$providerOptions` merged into the context metadata, so a provider mutation can carry its own hints (`mollieMethod`) | handler tests: headless path never touches the session seams, OPC/Twig path still does; redirect URL from the context; order data from the context basket; unsupported uiMode | handler, session handler, order data provider; payment-base request | unit (Mollie 714 + new; payment-base) |
| MS2 | **Wiring.** `- { name: oe.payment.return_resolver, provider: mollie }` on `MollieReturnResolver`; `$openAttemptFinder` on `EarlyOrderCreationHandler`; CI pin (TEMPORARY) to payment-base `b-7.4.x-GRAPH-QL` with the version alias | services.yaml smoke (integration) | services.yaml, workflows | container compiles; CI green |
| MS3 | **Webhooks end the order.** `authorized` for an open (NOT_FINISHED / PENDING) contract → `ContractCommitServiceInterface::commit(PaymentConfirmation{mollie, tr_id, tr_id, amount, currency, requiresCapture: true, source: webhook})` — order created and committed, not paid, capture later fulfils (same semantics as Stripe PS7); `paid` keeps Mollie's ladder (already commits + fulfils); refused commit ⇒ webhook failure so Mollie retries | fulfilment-handler tests with a scripted commit service | handler + yaml | unit; live webhook on the dev shop (MS6) |
| MS4 | **GraphQL mutations.** `src/Mollie/GraphQL/Controller/MollieCheckout.php` (`#[Logged] #[Right('PAYMENT_CHECKOUT')]`): `mollieCheckoutStart(basketId, confirmTermsAndConditions, returnUrl, cancelUrl?, method?)` (passes `paymentId: oe_payments_mollie`, `providerOptions: ['mollieMethod' => method]`), `mollieCheckoutReturn(contractId, contractToken)` (the resolver asks Mollie by the contract's payment id — no session id needed), `mollieCheckoutCancel(contractId, contractToken)`; `GraphQL\Service\NamespaceMapper` **mirroring** graphql-base's interface without implementing it (module must activate without graphql-base — Stripe PS6 lesson); `OptionalGraphQlDependencyTest`; stubs in `tests/bootstrap-unit.php` + PhpStan bootstrap | controller test over a mocked headless service; mapper test; optional-dependency test | controller, mapper, exception, yaml | unit; integration: schema contains the mutations (anonymous class finder, skips without graphql-base) |
| MS5 | **ACP.** `Mcp\MollieAcpCheckoutService extends AbstractAcpCheckoutService` — `paymentId()`, `providerName()`; `completePayment()` **refuses** with a clear ACP error: Mollie has no server-side charge of a delegated token (a card token is minted by Mollie Components in a browser); `create_checkout` works through the base class | unit | service, yaml | unit |
| MS6 | **Proof.** `bin/graph-ql-cli-test.sh` + `.md` for Mollie (same shape as Stripe's); Playwright spec: token → basketCreate → `mollieCheckoutStart` → Mollie's test-mode page (pick "Paid" / "Authorized") → **webhook** commits → `mollieCheckoutReturn` only reports; cancel + wrong token; `placeOrder` refused for a Mollie basket | — | — | the headless payment end-to-end, ended by the webhook |

## Decisions

- No method pinning is required: without `method` Mollie's hosted page offers every method; `method` is a hint
  (`selectedMethod`) and pay-later methods still need the billing address Mollie demands.
- `uiMode` is `hosted` only. Inline Components (card token) are phase 4: the token must be minted in a browser.
- Mollie's single redirect URL: the client's `returnUrl` serves success and failure; `cancelUrl` is accepted and
  ignored (documented in the mutation's description). `mollieCheckoutReturn` reports `failed` for a cancelled payment.
- The module-local `SessionAdapterInterface` / `TokenServiceInterface` / dispatcher bindings stay (payment-base defines
  the same ids; clean-up when both branches are merged — same as Stripe).
- Mollie's `paid` ladder is kept rather than routed through `ContractCommitService`: it already commits and fulfils, and
  changing it would touch every Twig order for no headless gain.

## Done (2026-10-06, mollie-payment `b-7.4.x-GRAPH-QL`)

MS1–MS6 delivered; reports in `../done/GRAPH-QL-MS*.md`, status in `../status.md`. The proof is the Playwright spec
`tests/e2e/playwright/tests/GraphQL/mollie-headless-checkout.spec.ts` (project `mollie-graphql`): 4/4 against the dev
shop, the order ended by Mollie's webhook (paid and failed), plus `bin/graph-ql-cli-test.sh` for the CLI. Found on the
way: nothing broke; a failed webhook leaves the order `FAILED` (not stornoed) as in Twig. payment-base gained
`HeadlessStartRequest::$providerOptions` (`56dbb6c`). Deferred: inline Components (card token) on the headless path, an
ACP flow that hands the buyer Mollie's hosted URL, the module-local session bindings clean-up after both branches merge,
and the CI pin revert.
