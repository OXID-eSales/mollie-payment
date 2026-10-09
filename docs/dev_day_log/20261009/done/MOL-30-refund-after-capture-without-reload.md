# Done — MOL-30: Refund option available right after a successful manual capture (no reload)

**Branch:** `b-7.4.x-MOL-30-refund-after-capture` (mollie-payment only). Plan: `../sprints/MOL-30-refund-after-capture-without-reload.md`,
trace: `../reports/MOL-30-refund-after-capture-trace.md`. **Merge on the product owner's word.**

## Cause (confirmed live)

`isRefundable = contract fulfilled && refundBound > 0`. After "Execute capture" the contract is fulfilled with the captured
amount in the same request, but `AdminActionBounds::refundBound()` read only Mollie's live payment, which the re-render
fetches milliseconds after `POST /captures` — still `authorized`, captured 0.00 → bound 0 → no Refund section. Mollie's
own timestamps for the e2e order: capture `createdAt 13:19:13Z`, payment `paidAt 13:19:14Z` — a window of about one
second, which the same-request render always falls into and a manual reload never does.

## Change

| Piece | Job |
|---|---|
| `Service\RefundBound::of(contract, payment)` (new, pure) | the one refund rule: no live payment → 0 (fail-closed); live payment still `authorized` → the shop's record (captured − refunded, null when the shop never recorded a capture); otherwise Mollie's `refundableAmount()`, lowered to the shop's record |
| `Admin\AdminActionBounds::refundBound()` | `RefundBound::of(...)` over the per-request snapshot (gate unchanged) |
| `Service\RefundService` | the same `RefundBound` as its ceiling; a `MollieAdapterException` from `POST /refunds` while the payment is still `authorized` becomes `MollieRefundNotYetAvailableException` (new) |
| `EventSystem\Handler\MollieRefundRequestHandler` | optional `AdminValidationFeedbackInterface` + `LanguageTranslatorInterface`: a failed refund is told to the admin on the next render — `MOLLIE_ADMIN_REFUND_CAPTURE_SETTLING` ("try again in a moment") or `MOLLIE_ADMIN_REFUND_FAILED`; before, the tab's dispatcher never read the event's result and a failed refund showed nothing at all |
| `services.yaml`, `views/admin_twig/{en,de}/mollie_lang.php` | wiring and the two messages |

## Red → green

- Unit (standalone, `tests/phpunit-unit.xml`): **743 → 756**. Red first: `AdminActionBoundsTest` (+4: local capture while
  Mollie shows the hold → captured amount; local refund → remainder; settled → Mollie's smaller figure wins; lookup fails →
  0), `MolliePanelViewDataBuilderTest` (+1, real bounds: fulfilled + captured locally + live `authorized` → refundable for
  the captured amount, not capturable), `RefundServiceTest` (+3: accepts the captured amount inside the window; Mollie's
  refusal inside the window is named; a settled payment's refusal propagates unchanged), `MollieRefundRequestHandlerTest`
  (+3: settling → admin message + `capture_settling`; other failure → generic message; without the channel the result stays
  on the event), `AdminRefundMessagesLangTest` (+2, EN/DE keys).
- e2e `MollieAdmin/CaptureSectionAfterCapture` (fresh authorized inline-card order, full capture): **red** on the unchanged
  shop — "MOL-30: the Refund section is offered without a reload" timed out while every 2026-10-01 assertion still
  passed; **green** with the fix: Refund form on the very next render with `refund-bound` = captured amount, the
  contract `fulfilled`, and a full refund submitted from that panel booked without a message, "Refunded" = amount
  (Mollie: `amountRemaining 0.00`, refund pending; shop: `OXREFUNDEDAMOUNT 99.50`).
- Gates (CI form): phpcs clean, PHPStan level max No errors, PHPMD clean.

## Measurement

The spec's live poll of Mollie could not run: the e2e `.env` carries a placeholder `MOLLIE_TEST_API_KEY` (the fixture
reads `MOLLIE_API_KEY`). Read afterwards through the module's configured key: capture `succeeded` at 13:19:13Z, payment
`paid` at 13:19:14Z. The "still settling" message (`MOLLIE_ADMIN_REFUND_CAPTURE_SETTLING`) therefore guards a window of
about a second; it was not hit in the run (the refund followed a 17 s wait). Covered by unit tests, not seen live.

## Follow-ups (not in this story)

- Put a real test API key into the e2e `.env` (`MOLLIE_API_KEY`) so `fixtures/mollie-api.ts` can read payments; then the
  spec prints the lag on every run.
- `admin/mollie-admin-refund.spec.ts` lines 89–109 still wait 3 s for the Refund button after a capture; it no longer
  needs to.
- The capture and cancel handlers still swallow their failures the same way the refund handler did; the admin channel
  added here (optional constructor arguments) can be given to them in a follow-up.
- Pre-existing, unchanged: the stale-attempt sweep is provider-agnostic; partial card capture releases the remainder.
