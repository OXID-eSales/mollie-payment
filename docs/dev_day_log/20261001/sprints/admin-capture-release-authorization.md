# Sprint — Payment tab: capture for authorized-only holds, cancel authorization full or partial (Mollie admin)

**Date:** 2026-10-01 · **Ask:** "the Payments tab needs: 1. Capture functionality for the orders which are
authorised only. 2. Cancel authorisation full or partially (do same like it is did for Stripe)" ·
**Branch:** `b-7.4.x-admin-capture-release-authorization` (mollie-payment only)
**Status:** IN PROGRESS — TDD, e2e-proven; merge on the product owner's word.

## Finding

The tab already had a Capture and a Cancel section, gated on Mollie's live `authorized` status (parity review
2026-07-10). Measured against Mollie's API and the code, three things kept it from behaving like the Stripe tab:

1. **Cancel used the wrong call and the wrong model.** `CancelAuthorizationService` called `payments->cancel`
   (the open-payment cancel) and always moved the contract to CANCELLED. Mollie's documented way to void a hold is
   the `release-authorization` endpoint, which releases *the remaining uncaptured amount* — the whole hold before any
   capture, only the remainder after partial captures (cards with multi-capture, Klarna, PayPal, Billie keep the rest
   authorized). After a partial capture the service refused (`fulfilled` is not AUTHORIZED/COMMITTED) and, had it
   not, would have cancelled a contract that already carries captured money and marked the order cancelled.
2. **A second capture after a partial one was refused** for the same reason: the first partial capture fulfils the
   contract (COMMITTED → FULFILLED stamps OXPAID), and `CaptureService::assertCapturable()` did not know FULFILLED.
3. **A refused action was silent.** The broker returns the agnostic event; the error Mollie's handler put on *its*
   event never reached the panel, so the operator saw an unchanged tab.

Live check (test profile, order 761, `tr_udXtmY42…`): a card hold of 1075.00 partially captured for 5.01 ended as
`paid` with `amountRemaining 5.01` — the card method released the remainder by itself, exactly like Stripe's
partial capture. The explicit release matters for the multi-capture methods.

## Change (Mollie)

- Adapter: `MolliePaymentsAdapterInterface::releaseAuthorization()` → SDK `payments->releaseAuthorization()` (v2.79
  has it), exceptions converted like every other call. Interface stays at four methods (ISP test).
- `CancelAuthorizationService`: calls `releaseAuthorization`; nothing captured → CANCELLED + order cancelled (as
  before); partially captured → contract stays FULFILLED, the release is written to the contract's metadata
  (`AuthorizationReleaseMarker`, `mollie_authorization_released`) and to the transaction audit log
  (`authorization_release`). Idempotent on CANCELLED and on an already released hold.
- `CaptureService`: FULFILLED with a captured amount is capturable again (follow-up capture, no transition); a
  released remainder is refused.
- `AdminActionBounds::captureBound()`: 0 for a cancelled contract or a released remainder — Mollie's release is
  asynchronous and the resource still reads `authorized` for a moment, the same stale window as after a capture.
- Panel: Cancel names the amount it releases (= the capture bound), shows a note after a partial capture that the
  captured part stays booked; Capture carries a hint on what a partial capture does per method. EN/DE.
- `AdminActionFailureReporter`: the capture / cancel handlers report a refusal (state error, invalid amount,
  Mollie 4xx) through the consume-once feedback channel the amount validation already uses.

## Proof

1. Unit (red → green): `MollieAdapterTest` (+2), `CancelAuthorizationServiceTest` (rewritten, 9),
   `AuthorizationReleaseMarkerTest` (2), `CaptureServiceTest` (+3), `AdminActionBoundsTest` (+2),
   `MolliePanelViewDataBuilderTest` (+2), handler tests (+5), `AdminActionFailureReporterTest` (4).
2. e2e `MollieAdmin/CancelReleasesAuthorization`: (a) fresh authorized card hold → Cancel → no Capture/Cancel,
   contract `cancelled`, order storno; (b) partial capture → `fulfilled`, captured amount booked, order live;
   remainder released (by the card method itself or by Cancel), then neither Capture nor Cancel, Refund offered.
3. Gates: phpcs clean, PHPMD clean, PHPStan: the 3 known environmental findings, none new.
