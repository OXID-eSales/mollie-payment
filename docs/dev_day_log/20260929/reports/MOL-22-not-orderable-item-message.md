# Report — MOL-22: a clear message when an item in the basket is not orderable

**Date:** 2026-09-29 · **Branch:** `b-7.4.x-MOL-22-not-orderable-item-message` (mollie-payment) · Plan:
`../sprints/MOL-22-not-orderable-item-message.md` · Done: `../done/MOL-22-not-orderable-item-message.md`

## What the shopper saw, and why

| Checkout | Before | Cause |
|---|---|---|
| Standard order step | "Payment via Mollie is not available right now. Please try again or choose another payment method." (the raw key `MOLLIE_CHECKOUT_UNAVAILABLE` until MOL-15 added the translation), shopper left on the payment step | `MollieOrderController::startCheckoutSession()` caught every `Throwable` from the checkout-session dispatch and answered `onCheckoutUnavailable()` — including core's refusal inside `finalizeOrder()` for a not-orderable item, which payment-base already tags as `ShopOrderException` code `article_not_buyable` with the core exception as `previous` |
| OPC footer | "Mollie payment processing failed: ERROR_MESSAGE_ARTICLE_ARTICLE_NOT_BUYABLE" (raw core key) | `MolliePaymentHandler::processPayment()` returned `PaymentHandlerResult::error('Mollie payment processing failed: ' . $e->getMessage())`; core's exception message IS the translation key and the footer prints `errorMessage` verbatim |

Neither checkout asked whether the basket was still orderable before dispatching, so a contract and a draft
order were attempted for a basket core was bound to refuse. Stripe has that pre-dispatch check
(`BasketBuyabilityValidator`); payment-base's docblock calls its own mapping "the race-window safety net behind
the controller's pre-dispatch buyability check". Mollie now has both.

## What changed (mollie-payment only, no Stripe reference)

- `Service\BasketBuyabilityValidator` + `Service\BuyabilityFailure`: which basket items are not buyable now.
- `Service\NotOrderableCheckoutFailure`: one fact for both checkouts — item titles (pre-dispatch) and/or core's
  `ArticleException` cause (from the dispatch failure, found through the `previous` chain or payment-base's code).
- `Service\NotOrderableItemsMessages`: the translated sentences — lead `MOLLIE_CHECKOUT_ITEMS_NOT_ORDERABLE`
  ("Your order cannot be completed because one or more items in your basket are currently not orderable. Please
  check your basket and remove the affected items or adjust the quantity.") + `MOLLIE_CHECKOUT_ITEM_NOT_ORDERABLE`
  ("The item "%s" is currently not orderable.") per known title; DE shipped too.
- Standard step: guard right after the basket-hash guard (basket before address), detection in the catch; the
  sentences go to the default error slot, core's own exception to the `basket` slot (Apex shows it inline at the
  item with the remaining stock — the wording every other payment method gets), and the shopper lands on the
  basket, exactly where core's own `OrderController` sends an out-of-stock order.
- OPC: same guard and detection in `MolliePaymentHandler`; the footer receives the sentences as `errorMessage`
  with code `MOLLIE_ITEMS_NOT_ORDERABLE`. No OPC or payment-base change needed.
- Everything that is not about an item stays on the generic message.

## Proof

| Proof | Result |
|---|---|
| Unit: validator (3), failure (5), messages (2), controller +4, handler +2, translation keys +2 | red (15 errors, 2 failures) → **green**; Unit suite **702** |
| e2e `MollieStandard/NotOrderableItemShowsClearMessage` — item turned unbuyable → guard; quantity above stock → core refuses in the dispatch | red on the pre-change sources (start / address step, no basket message) → **green** (2) |
| e2e `MollieOpc/NotOrderableItemShowsClearMessage` — footer error box names the item, no redirect | **green** (1); red run inconclusive by machine (stash left a stale compiled container, OPC did not render) — old path unambiguous in code |
| `mollie-opc` suite | **8 passed, 1 failed** — `CheckoutViaOpcPaysAndFinalizes` expects PayPal in the OPC payment select; `oe_payments_paypal` is inactive in `oxpayments` on this shop (precondition, same as 2026-09-28) |
| `mollie-standard` suite | **21 passed, 2 Klarna skips**, fully green (incl. MOL-11 and MOL-18 specs) |
| Gates | phpcs (CI form) clean · PHPMD clean · PHPStan: the 3 environmental findings of 2026-09-28 in untouched files, none new |

## Notes for the reviewer

- "Not orderable" reaches the order step in two shapes and both are covered: `Article::isBuyable()` false (stock
  0 with flag 3, inactive, variant gone) → the pre-dispatch guard; ordered quantity above the stock → only core's
  `validateStock()` sees it, inside the dispatch.
- Apex answers the basket step under its SEO path (`/warenkorb/`); the spec accepts SEO path or `cl=basket`.
- The e2e specs change the demodata article's stock and restore it in `finally`; the paid-order specs of the
  regression suite lower that stock by one each run (271 → 227 over a week).
