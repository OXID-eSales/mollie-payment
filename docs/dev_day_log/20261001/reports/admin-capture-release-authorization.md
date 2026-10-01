# Report — Payment tab: capture for authorized-only holds, cancel authorization full or partial (Mollie)

**Date:** 2026-10-01 · **Branch:** `b-7.4.x-admin-capture-release-authorization` (mollie-payment) · Plan:
`../sprints/admin-capture-release-authorization.md` · Done: `../done/admin-capture-release-authorization.md`

## What the tab does now

| Hold | Capture | Cancel authorization |
|---|---|---|
| Authorized, nothing captured | full or partial (as before) | releases the whole hold → contract `cancelled`, order `OXTRANSSTATUS = CANCELLED` |
| Partially captured, remainder still authorized (multi-capture methods) | **further captures up to the remainder** (was refused: contract `fulfilled`) | **releases only the remainder**; contract stays `fulfilled`, captured amount stays booked, order stays live; release recorded on the contract + audit log |
| Partially captured, remainder released by the method itself (cards without multi-capture) | none offered once Mollie reports `paid` | none offered; a click inside Mollie's propagation window is refused and **the operator is told why** |

Cancel is Mollie's `release-authorization` call now (was the open-payment cancel). The Cancel section names the
amount it releases; after a partial capture it says the captured part stays booked. Capture carries a hint on what a
partial capture does per method. A refused capture or release (state, amount, Mollie 4xx) is shown on the next
render — before, the panel re-rendered unchanged and silent.

## Proof

| Proof | Result |
|---|---|
| Unit suite | red → **green**, **736** tests (was 714) |
| e2e `MollieAdmin/CancelReleasesAuthorization` (1) fresh authorized card hold → Cancel | **green**: Mollie payment `canceled`, no Capture/Cancel offered, contract `cancelled`, order `CANCELLED` (orders 770, 772) |
| e2e (2) partial capture (total − 1.00) | **green**: contract `fulfilled`, captured 98.50 booked, order `OK`; the card released the remainder itself, the Cancel click inside the propagation window was refused by Mollie and the operator saw "The authorization was not released: …"; after reload neither Capture nor Cancel, Refund offered (order 771) |
| Gates | phpcs clean · PHPMD clean · PHPStan: the 3 known environmental findings (`MollieCheckoutFooter` ×2, `MollieOrderController`), none new |

## Limits

- Mollie has no partial void: "cancel partially" is "capture what you want, release the rest". For cards without
  multi-capture Mollie does the release itself on the first partial capture (like Stripe); for Klarna / PayPal /
  multi-capture cards the operator releases it with Cancel.
- The test profile offers no multi-capture method, so the explicit partial release is proven by unit tests and
  the live API's behaviour, not by a browser run.
