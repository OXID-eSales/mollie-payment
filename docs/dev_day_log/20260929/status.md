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
  - CI: first push red only on the styles job (one 121-char line, warnings count in CI), fixed in `65fa43a`.
  - Env: OPC flag flipped on for the OPC runs and restored to off; the demodata article's stock restored by the specs.
- Sprint MOL-9 (the whole Mollie block — method selection and card fields — locked until the AGB checkbox is ticked)
  — **IMPLEMENTED**, branch `b-7.4.x-MOL-9-agb-gates-mollie-block`, pushed; CI green on `4c12e29`, merge on the product owner's
  word. Plan `sprints/MOL-9-agb-gates-mollie-block.md`; `done/MOL-9-agb-gates-mollie-block.md`;
  `reports/MOL-9-agb-gates-mollie-block.md`.
  - `mollie-agb-gate` moved from the button to the inline block: `region` target (`inert` + `.mollie-agb-locked`,
    dimmed, pointer fallback) and `button` target; a lone button (classic flow) keeps the MOL-11 shape. Template:
    one region around selector + card fields. No PHP, no OPC change (OPC's section lock already makes the
    payment-execution body inert; pinned by `MollieOpc/AgbGatesFooterBlock`).
  - Proof: `OrderPageAgbGateTemplateTest` red → green; e2e `MollieStandard/AgbGatesMollieBlock` red → green (2),
    classic flow keeps `AgbGatesOrderButton` green; `mollie-opc` 9 green + PayPal precondition failure;
    `mollie-standard`: 7 specs that picked a method before ticking AGB failed as the lock intends → adapted (tick
    first / DOM-level pick), all green on re-run; final full run 22 green + the AGB tick flake (forced click on the
    box, 1 in 5) hardened via a label click with state check in both gate specs (8/8 on repeat).
