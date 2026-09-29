# Done — MOL-22: a clear message when an item in the basket is not orderable

**Branch:** `b-7.4.x-MOL-22-not-orderable-item-message` (mollie-payment). Plan: `../sprints/MOL-22-not-orderable-item-message.md`.

## Story 1 — red proofs (TDD)
- Unit (red → green): `BasketBuyabilityValidatorTest` (3), `NotOrderableCheckoutFailureTest` (5),
  `NotOrderableItemsMessagesTest` (2), `MollieOrderControllerTest` +4 (guard, guard order, no replay, dispatch
  failure named instead of "unavailable"), `MolliePaymentHandlerTest` +2 (pre-dispatch refusal with the shopper's
  sentences, dispatch failure named), translation-key test +2 keys. 15 errors + 2 failures before, Unit suite
  702 green after.
- e2e `MollieStandard/NotOrderableItemShowsClearMessage` (2): item turned unbuyable (stock 0 / flag 3) after the
  order step rendered → pre-dispatch guard; two ordered, one left → core refuses inside the dispatch. Red on the
  pre-change sources (shopper dumped on the start / address step, no basket message), green after.
- e2e `MollieOpc/NotOrderableItemShowsClearMessage` (1): the footer's error box carries the translated sentence
  naming the item. Green; the red run was inconclusive by machine (stashing the sources left a stale compiled
  container, OPC did not render) — the old path is unambiguous in code: the handler passed
  `'Mollie payment processing failed: ' . $e->getMessage()` through, and that message is core's raw key.
- `fixtures/shop-db.ts`: `articleStockOf()` / `setArticleStock()`; specs restore stock in `finally`.

## Story 2 — implementation (`src/Mollie/Service/`, controller, handler)
- `BuyabilityFailure`, `BasketBuyabilityValidator` (Article::isBuyable() over the basket contents),
  `NotOrderableCheckoutFailure` (titles + optional core ArticleException cause; `fromThrowable()` walks the
  `previous` chain for an ArticleException or payment-base's `article_not_buyable`), `NotOrderableItemsMessages`
  (lead sentence + one per title, via `LanguageTranslatorInterface`).
- `MollieOrderController`: guard after the basket-hash guard (`basketNotOrderableFailure()`), detection in
  `startCheckoutSession()`'s catch, `showNotOrderable()` → sentences in the default error slot (the layout
  renders it on every page; Apex renders the `basket` slot only for its own exception types), core's cause in
  the `basket` slot (inline at the item, with the remaining stock), returns `'basket'`.
- `MolliePaymentHandler` (OPC): same guard after `prepareOxidBasket()`, same detection in the catch,
  `PaymentHandlerResult::error(<sentences>, 'MOLLIE_ITEMS_NOT_ORDERABLE')`; collaborators injected via
  services.yaml with in-class defaults. Value objects excluded from autowiring like the existing ones.
- Translations `MOLLIE_CHECKOUT_ITEMS_NOT_ORDERABLE`, `MOLLIE_CHECKOUT_ITEM_NOT_ORDERABLE` (EN/DE).

## Story 3 — regression, gates, docs
- Suites: see `../reports/MOL-22-not-orderable-item-message.md`.
- Gates: phpcs (CI form, warnings counted) clean, PHPMD clean, Unit 702 green; PHPStan: the same 3 environmental
  findings in untouched files as on 2026-09-28 (CI green on those sources), none in the new code.
- CHANGELOG entry under Unreleased / Fixed.
