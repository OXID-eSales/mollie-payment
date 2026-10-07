# GRAPH-QL / MS1 — Headless-ready Mollie handler (DONE 2026-10-06)

**Sprint:** [../sprints/GRAPH-QL-mollie-provider-story.md](../sprints/GRAPH-QL-mollie-provider-story.md) · **Branch:** `b-7.4.x-GRAPH-QL`

## What changed

| Piece | Job |
|---|---|
| `PaymentHandler\MolliePaymentHandler implements ContractFirstPaymentHandlerInterface` | payment-base's `PaymentHandlerRegistry` now finds Mollie (`placeOrder` guard, `<provider>CheckoutStart`). With `metadata.headless`: `prepareOxidBasket()` (session basket + `setVariable('paymentid')`) is skipped — basket and user come with the `PaymentContext`; `uiMode` other than `hosted` is refused with `MOLLIE_UI_MODE_UNSUPPORTED` before any event, contract or Mollie payment exists (Mollie's page refuses framing). `buildEventContext()` takes the session id from the context (`headless:<basketId>`) and adds `headless`, `basketId`, `returnUrl`; the Twig / OPC path reads `Registry::getSession()->getId()` through the new `sessionId()` seam, as before |
| `EventSystem\Handler\MollieCheckoutSessionHandler` | `redirectUrlFor()`: a headless contract returns to the client's `returnUrl` + `contract_id=…` (Mollie knows one redirect URL for every outcome, so `cancelUrl` is not used); Twig / OPC keep `cl=order&fnc=checkoutReturn&contract_id&contract_token`. The basket from the event context is handed to the payment request |
| `Service\CheckoutPaymentService(Interface)`, `Service\MollieOrderDataProvider(Interface)` | optional `?Basket $basket` through to `billingAddress()` / `lines()`: pay-later methods (Klarna, …) get address and lines from the basket being paid; null keeps the session basket (Twig / OPC) |
| payment-base `HeadlessStartRequest::$providerOptions` (`56dbb6c` on `b-7.4.x-GRAPH-QL`) | provider hints (`mollieMethod`) reach the handler as `PaymentContext` metadata; the headless keys cannot be overridden |

`EarlyOrderCreationHandler` (payment-base, S3) already builds the order from `basketId` and stamps `basket_id` on the
contract; `MollieContractCreationHandler` reads `userId` / `basket` / `conditionTypes` from the context as before.

## Red → green

- `Unit\PaymentHandler\MolliePaymentHandlerHeadlessTest` (5): contract-first marker; headless start never touches the
  two session seams and names `sessionId`, `basketId`, `headless`, `returnUrl`, the method hint; Twig / OPC path still
  prepares the basket and reads the session id; `embedded` refused before anything is created; `hosted` is the default.
- `Unit\EventSystem\Handler\MollieCheckoutSessionHandlerHeadlessTest` (2): headless → client URL + `contract_id`, no
  contract token generated, context basket in the request; Twig path unchanged.
- `Unit\Service\MollieOrderDataProviderExplicitBasketTest` (2): the given basket is used, the session never asked.
- `Unit\Service\CheckoutPaymentServiceBasketPassThroughTest` (2): basket reaches the provider; null falls back.
- payment-base `HeadlessCheckoutServiceTest` + 1 (provider options, headless keys win).

## Gates

- Mollie Unit (standalone) **725** green (714 + 11) · Integration (shop PHPUnit) **38** green, 3 pre-existing skips
- PHPStan (level 6) No errors · phpcs **CI form, warnings counting** clean · phpmd clean
- payment-base Unit 1558 (headless 23) · PHPStan level max No errors

## Notes

- `MollieContractCreationHandler::afterContractCreated()` reads `mollieMethod` from the context while both entry points
  set `selectedMethod`, so contract metadata `mollie_method` is never written — pre-existing, untouched here.
- The in-flight replay (MOL-18) and the user-data gate (MOL-15) live in `MollieOrderController`, not in the handler;
  on the headless path payment-base's attempt guard and basket provider take those roles.
