# 2026-09-29

- Sprint MOL-22 (a clear message when an item in the basket is not orderable, instead of `MOLLIE_CHECKOUT_UNAVAILABLE`)
  — **IMPLEMENTED**, branch `b-7.4.x-MOL-22-not-orderable-item-message`, pushed; CI pending / merge on the product
  owner's word. Plan `sprints/MOL-22-not-orderable-item-message.md`; `done/MOL-22-not-orderable-item-message.md`;
  `reports/MOL-22-not-orderable-item-message.md`.
  - Finding: the standard step showed the *generic* "Mollie is not available right now" (translated since MOL-15),
    the OPC footer still the raw core key ("Mollie payment processing failed: ERROR_MESSAGE_ARTICLE_ARTICLE_NOT_BUYABLE").
    payment-base already tags core's refusal as `article_not_buyable` (previous = core exception); Mollie ignored it
    and had no pre-dispatch buyability check (Stripe has one).
  - Fix: `BasketBuyabilityValidator` / `BuyabilityFailure` / `NotOrderableCheckoutFailure` / `NotOrderableItemsMessages`;
    guard + detection in `MollieOrderController` (sentences in the default slot, core's cause inline in the basket,
    return to basket) and in `MolliePaymentHandler` (OPC footer gets the sentences as `errorMessage`, code
    `MOLLIE_ITEMS_NOT_ORDERABLE`). Translations EN/DE.
  - Proof: unit red → green (Unit 702); e2e `MollieStandard/NotOrderableItemShowsClearMessage` (2, red → green) and
    `MollieOpc/NotOrderableItemShowsClearMessage` (1, green); `mollie-standard` 21 green + 2 Klarna skips;
    `mollie-opc` 8 green + 1 precondition failure (PayPal inactive in `oxpayments`). Gates clean except the 3
    environmental PHPStan findings of 2026-09-28.
  - Env: OPC flag flipped on for the OPC runs and restored to off; the demodata article's stock restored by the specs.
