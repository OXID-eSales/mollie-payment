# Sprint — Order page: Mollie method list as a card, card-number placeholder, card-holder "(optional)"

**Date:** 2026-10-01 · **Ask:** "the rendered list of the available payment types shall be on the same layer as other
blocks on the classic checkout page — fix the layout and css for the rendered iframe checkout"; "the header
Zahlungsart wählen should be same like `<h2 class="h4 card-header card-title card-header-edit …">`"; "the field card
number shall contain the placeholder 0000 0000 0000 0000 and the numbers are grouped while the user enters"; "the
field Name on the card shall contain (optional)" · **Branch:** `b-7.4.x-order-page-method-card` (mollie-payment only)
**Status:** IMPLEMENTED — e2e green on the live shop; merge on the product owner's word.

## Finding (screenshot of the live order page, Mollie selected, Card picked)

The right column stacks the Summary card and the Agreements card (white `card mb-3`, `h2.h4.card-header.card-title`),
then the Mollie block: a bold paragraph "Zahlungsart wählen", the radio list and the four Components fields straight
on the grey page background, no card, no header — a different layer from everything around it. Mollie's hosted
inputs (iframes from js.mollie.com) ship with an empty `placeholder`; Mollie Components offer no option for the
placeholder *text*, only `::placeholder` styling and the `styles` object (font size, color, …). Whether digits are
grouped while typing is Mollie's input behaviour — measured in the e2e below, not something the shop controls.

## Change (Mollie)

- `page/checkout/order.html.twig` (Mollie branch): the method list + card fields are a `card mb-3` with the same
  header classes as the order sections (`h2.h4.card-header.card-title card-header-edit d-flex …`): "Zahlungsart
  wählen" with 2+ methods, "Zahlungsart" with a single read-only method. The AGB gate's `region` is the whole card.
  Selectors the e2e suite relies on (`.mollie-methods`, `[data-mollie-order-card="method"]`,
  `[data-mollie-agb-gate-target="region"]`, `input[name=mollieMethod]`) unchanged.
- Card number: the mount carries `data-placeholder="0000 0000 0000 0000"` (translation key, EN/DE), drawn by CSS
  (`::before`) and hidden while the field is focused or filled — the Stimulus controller toggles `is-focused` /
  `is-filled` from Mollie's `focus` / `blur` / `change` (`dirty`) events. Same in the OPC footer widget.
- Components `styles`: base font 16px / #212529 so the text inside the iframes matches apex's form controls.
- Card holder label: **kept required, not "(optional)"**. Measured on the live shop: card number, expiry and CVC
  valid, holder empty → Mollie's `createToken` answers "Ein oder mehrere Felder sind ungültig" and the shopper stays
  on the order page. Labelling a field Mollie insists on as optional would send shoppers into that error, so the
  label stays "Karteninhaber" / "Cardholder name"; the e2e asserts both the label and Mollie's refusal.
- Digit grouping: Mollie's hosted input groups the number itself (`4111 1111 1111 1111` as displayed) — nothing
  to add on the shop side; the e2e logs the displayed value and checks all digits arrived.

## Proof

1. e2e `MollieStandard/OrderPageMethodCard`: the Mollie block is a `card` with the card header, same x and width as
   the Summary card; placeholder drawn while empty, gone after typing; all digits arrive in Mollie's input (the value
   as displayed is logged, grouped by Mollie); card-holder label not marked optional; submit without a card holder
   is refused by Mollie (error shown, still on the order page).
2. Visual check: side-column screenshots before/after typing.
3. Unit suite unchanged (no PHP change); `npm run build` rebuilt the bundle.
