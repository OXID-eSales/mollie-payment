# Sprint MOL-22: a clear message when an item in the basket is not orderable (instead of MOLLIE_CHECKOUT_UNAVAILABLE)

**Date:** 2026-09-29 · **Ticket:** MOL-22 · **Branch:** `b-7.4.x-MOL-22-not-orderable-item-message` (mollie-payment only)
**Status:** IN PROGRESS — TDD; merge only on the product owner's word.
**Definition of Done:** when "Order now" (standard order step) or the OPC footer submit runs into an item that is
not orderable (turned unbuyable, out of stock for the ordered quantity, removed), the shopper reads a translated
sentence saying the order cannot be completed because one or more items are not orderable — naming the item when
it is known — and lands on the basket; never the raw key, never the misleading "Mollie is not available right now".
All other failures keep the generic message. Proven by e2e in both checkouts and by unit tests.

## Findings (read-only, 2026-09-29)

- Translation for `MOLLIE_CHECKOUT_UNAVAILABLE` exists since MOL-15 (EN/DE), so the standard step shows the *generic*
  sentence today — wrong for a stock problem, and `ExceptionToDisplay::getOxMessage()` translates keys at render.
- OPC still shows a raw key: `MolliePaymentHandler` answers `PaymentHandlerResult::error('Mollie payment processing
  failed: ' . $e->getMessage())`, and the message of core's exception IS the key
  (`ERROR_MESSAGE_ARTICLE_ARTICLE_NOT_BUYABLE` / `ERROR_MESSAGE_OUTOFSTOCK_OUTOFSTOCK`); the footer prints
  `result.errorMessage` verbatim.
- payment-base already classifies the cause: `OxidShopOrderService::wrapOrderCreationError()` maps core's
  `ArticleException` family to `ShopOrderException` code `article_not_buyable` with `previous` = the core exception,
  and its docblock calls this "the race-window safety net behind the controller's pre-dispatch buyability check".
  Stripe has that pre-dispatch check (`BasketBuyabilityValidator`, `BuyabilityFailure`, `BasketNotBuyableException`).
  Mollie has neither the check nor the mapping: `startCheckoutSession()` catches `Throwable` → `onCheckoutUnavailable()`.
- Core's own `OrderController::execute()` shows the core exception and returns to the **basket** step.
- OPC's `CheckoutService` forwards a handler *error result* message + code unchanged to the footer JSON.

## Design (Mollie-local, DRY across both checkouts, no Stripe reference)

| Class (`src/Mollie/Service/`) | One job |
|---|---|
| `BuyabilityFailure` | value: `articleId`, `productTitle` |
| `BasketBuyabilityValidator::validate(Basket): list<BuyabilityFailure>` | which basket items are not buyable right now (`Article::isBuyable()`) |
| `NotOrderableCheckoutFailure` | value: product titles + optional core `ArticleException` cause; `fromBuyabilityFailures()`, `fromThrowable()` (walks the `previous` chain for an `ArticleException` or a `ShopOrderException` with code `article_not_buyable`) |
| `NotOrderableItemsMessages::messagesFor(failure): list<string>` | translated sentences: lead `MOLLIE_CHECKOUT_ITEMS_NOT_ORDERABLE` + one `MOLLIE_CHECKOUT_ITEM_NOT_ORDERABLE` per known title (via `LanguageTranslatorInterface`) |

- `MollieOrderController::execute()`: a buyability guard right after the basket-hash guard (basket problems before
  address problems — the shopper has to fix the basket anyway); `startCheckoutSession()`'s catch asks
  `NotOrderableCheckoutFailure::fromThrowable()` first. Both show the sentences with destination `basket` (plus the
  core cause exception, exactly as core does) and return `'basket'`. Everything else → `onCheckoutUnavailable()`.
- `MolliePaymentHandler::processPayment()` (OPC): same guard after `prepareOxidBasket()`, same detection in the catch,
  answering `PaymentHandlerResult::error(<sentences>, 'MOLLIE_ITEMS_NOT_ORDERABLE')` so the footer prints the
  translated text.
- Translations EN/DE for the two keys; the key-existence unit test grows by two keys.

## Stories

1. Red: unit tests (validator, failure detector, messages, controller × 4, handler × 2, translation keys); e2e
   `MollieStandard/NotOrderableItemShowsClearMessage` (item turned not orderable → pre-dispatch guard; ordered
   quantity above stock → finalize path) and `MollieOpc/NotOrderableItemShowsClearMessage`.
2. Green: the four classes, controller + handler wiring, services.yaml, translations.
3. Regression (`mollie-standard`, `mollie-opc`), gates, CHANGELOG, done/report/status, push, CI.

## Decisions

- Wording follows the ticket: "cannot be completed because one or more items are currently not orderable", plus the
  item's title when known. Core's own message (e.g. "Not enough items in stock! Available: 1") is shown alongside on
  the standard step — it is the shop's wording for every other payment method.
- No payment-base change (the mapping already exists); no OPC change (it forwards the handler's message as is).
