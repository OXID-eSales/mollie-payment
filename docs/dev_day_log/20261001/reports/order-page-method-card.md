# Report — Order page: Mollie method list as a card, card-number placeholder, card holder stays required

**Date:** 2026-10-01 · **Branch:** `b-7.4.x-order-page-method-card` (mollie-payment) · Plan:
`../sprints/order-page-method-card.md` · Done: `../done/order-page-method-card.md`

## Before / after (live shop, Mollie selected, Card picked)

Before: the Summary and Agreements were white cards, the Mollie block ("Zahlungsart wählen" as a bold paragraph, the
radios, the four Components fields) sat straight on the grey page. After: the block is a `card mb-3` with
`h2.h4.card-header.card-title card-header-edit …` like every order section — same x and width as the Summary card
(asserted to the pixel). The card-number field shows `0000 0000 0000 0000` until the shopper types; the text inside
Mollie's iframes uses the theme's 16px / #212529.

## Measured, not assumed

| Question | Measurement | Consequence |
|---|---|---|
| Placeholder text on the card number | Mollie's hosted input ships `placeholder=""`; Components expose only `::placeholder` *styling* | shop-side placeholder on the mount (`data-placeholder` + CSS), hidden on `is-focused` / `is-filled` from Mollie's focus / blur / change(dirty) events |
| Digits grouped while typing | Mollie's input shows `4111 1111 1111 1111` | nothing to add; e2e checks all digits arrived |
| "Name on the card (optional)" | number, expiry, CVC valid, holder empty → `createToken`: "Ein oder mehrere Felder sind ungültig", Mollie marks the holder component `is-invalid`, shopper stays on the order page | **label stays "Karteninhaber" / "Cardholder name"** — Mollie requires the holder; labelling it optional would send shoppers into that error. Not done, on purpose. |

## Proof

| Proof | Result |
|---|---|
| e2e `MollieStandard/OrderPageMethodCard` | **green**: card + header classes, same edge/width as Summary, placeholder drawn then gone after typing, label not "optional", Mollie refuses without holder |
| Regression `AgbGatesMollieBlock` (2), `InlineCardComponents`, `OrderPageSingleMethodReadOnly` | **green** on the new markup (gate `region` is now the whole card) |
| Unit suite | unchanged (no PHP change) |

## Lesson

Compiled Twig lives in `var/cache/template_cache`, not `source/tmp`: the first rerun served the old label after
`rm -rf source/tmp/*`; `oe:cache:clear` is what drops it.
