# Sprint: Refund option available right after a successful manual capture (no reload)

**Date:** 2026-10-09 · **Ticket:** MOL-30 · **Report:** `../reports/MOL-30-refund-after-capture-trace.md`
**Repo:** mollie-payment only (payment-base untouched). **Branch (when approved):** `b-7.4.x-MOL-30-refund-after-capture`
cut from `b-7.4.x`. **Requirements:** payment-base `docs/dev_log/20260903/sprints/_engeneering_requirements.md`.
**Status:** DONE 2026-10-09 on `b-7.4.x-MOL-30-refund-after-capture` — see `../done/MOL-30-refund-after-capture-without-reload.md`; merge on the product owner's word.

**Definition of Done (sprint-level):** after "Execute capture" succeeds on the Payment tab, the very next render shows
the Refund section with the captured amount as refundable, without a reload; a refund submitted in that window either
succeeds or tells the admin in plain words that Mollie is still settling the capture (never "exceeds refundable" or a
generic error); Stripe/PayPal tabs unchanged; proven by Playwright on the dev shop.

## Root cause (from the report)

`isRefundable = contract fulfilled && refundBound > 0`. After a capture the contract is already `fulfilled` with the
captured amount recorded, but `AdminActionBounds::refundBound()` reads only Mollie's live payment, which still says
`authorized` / captured 0.00 for a few seconds. Same lag that hid the stale Capture section on 2026-10-01; that fix
taught `captureBound()` to trust the contract, `refundBound()` was left behind. `RefundService` caps refunds by the
same live figure.

## Approach

| Principle | Application |
|---|---|
| Fix the cause | the shop's own record of what it captured is the source of truth for *whether* a refund is offered; Mollie's live figure only ever lowers it |
| TDD-first | Story 1 lands a red unit test for the bound and a red e2e assertion on the existing capture spec before any change |
| SRP | bound arithmetic stays in `AdminActionBounds`; the "still settling" message lives in `RefundService` / the panel's error mapping; the DTO keeps describing Mollie |
| DRY | one bound rule shared by the panel (what is offered) and `RefundService` (what is accepted) — no second copy of the arithmetic |
| OCP | no new state, no new service; one method's rule changes, one error code is added |
| No overengineering | no polling of Mollie inside the capture request, no background job, no "capture pending" state machine; a bound rule and one message |
| Proven through the UI | the Refund form on the very next render after a capture, clicked and asserted by Playwright |
| DevOps-first | phpcs in CI form (warnings count), phpstan, phpmd, unit (standalone 7xx), `mollie-admin` e2e |

**Alternative considered — wait for Mollie inside the capture request** (poll `GET /payments/{id}` up to N seconds until
`paid`): truthful live figures, but it slows every capture, is non-deterministic, and still fails on a slow day.
Rejected unless Story 1's measurement shows the flip takes longer than a form round-trip (then reconsider as a bounded,
optional wait).

## Out of scope
- The AUTHORIZED → READY_TO_COMMIT path (contract not yet committed when captured): the `paid` webhook finishes it;
  the gate keeps requiring `fulfilled`. Noted, not changed.
- The partial-card-capture warning (Mollie releases the remainder after one capture) — open follow-up from 2026-10-01.
- `admin/mollie-admin-refund.spec.ts`'s 3-second retry: left as is in this sprint (it will simply stop needing it).

## Risks
- **Offering a refund Mollie cannot yet book.** Mollie refuses a refund until the capture has settled. Story 3 turns
  that refusal into a clear message and keeps the form; Story 1 measures how long the window actually is on the dev shop.
- **Over-offering.** The bound must never exceed what Mollie will allow once settled: `contract captured − contract
  refunded`, and never more than Mollie's figure once Mollie reports a settled amount.
- **Unit fixtures that assume an `authorized` payment with `amountRemaining 100`** (`AdminActionBoundsTest:84-95`): real
  Mollie sends 0.00 for a hold; the new tests use the real shape, the old ones are left untouched.

---

## Story 1 — Prove it: red unit test, red e2e assertion, measured lag

**Estimate:** S

**Tests first:**
- `tests/Unit/Admin/AdminActionBoundsTest.php::testRefundBound_AfterALocalCaptureWhileMollieStillShowsTheHold_IsTheCapturedAmount`
  — contract fulfilled, captured 20.90, refunded 0; live payment `authorized`, `amountCaptured 0.00`, no remainder
  → expect 20.90. RED today (0.00). Second test: partial refund recorded locally → `captured − refunded`.
- `tests/Unit/Admin/MolliePanelViewDataBuilderTest.php::testBuild_FulfilledAndCapturedLocally_IsRefundableOnTheNextRender`
  — the gate with the real post-capture inputs → `isRefundable` true, `refundBound` = captured.
- e2e `tests/MollieAdmin/CaptureSectionAfterCapture.spec.ts`: after the full capture, on the **same** render, assert the
  Refund form (`[data-testid="mollie-refund-form"]`) is visible and its bound equals the captured amount. RED today.
  Add a measurement step (no assertion): poll `fetchMolliePayment()` every 500 ms for up to 15 s after the capture and
  log when `status` becomes `paid` — the width of the window Story 3 has to cover.

**Definition of Done:** three red tests for the stated reason; the lag number in the done report.

## Story 2 — The refund bound trusts the contract

**Estimate:** S

**Tests first:** the Story 1 unit tests plus: live payment `paid` with `amountRemaining` smaller than the contract's
figure (a Dashboard refund the shop does not know) → Mollie's smaller figure wins; no provider order id → 0; lookup
failure → contract figure (the shop still knows what it captured).

**Change:** `AdminActionBounds::refundBound()` = `max(0, contract captured − contract refunded)`, lowered to Mollie's
`refundableAmount()` whenever the live payment reports a settled amount (`paid` / `amountCaptured > 0`); while the
live payment is still an unsettled hold, the contract figure stands. Pure function of the two inputs already available;
no new dependency.

**Definition of Done:** unit green; `MolliePanelViewDataBuilder` unchanged (its gate already reads the bound).

## Story 3 — A refund inside the settling window is answered in plain words

**Estimate:** S–M

**Tests first:**
- `tests/Unit/Service/RefundServiceTest.php`: amount within the contract bound while the live payment is still an
  unsettled hold → the service does **not** refuse with "exceeds refundable"; when Mollie's API rejects the refund
  because the payment is not yet refundable → a `MollieRefundNotYetAvailableException` (new, carries the Mollie
  message) instead of the generic failure.
- `tests/Unit/Admin/MolliePaymentPanelProviderTest.php`: that exception maps to the panel error
  `MOLLIE_REFUND_CAPTURE_SETTLING` ("Mollie is still settling the capture — try again in a moment"), the form stays.
- EN/DE lang keys present (lang test if one exists for panel keys).

**Change:** `RefundService::refund()` uses the Story 2 bound (same rule, one place — extract the arithmetic into
`AdminActionBounds` or a tiny `RefundBound` value object both callers use); Mollie's "not refundable yet" API error is
recognised and surfaced; `resetViewCache()` still runs after the attempt.

**Definition of Done:** unit green; a refund attempted within the window on the dev shop shows the message, a refund
after it succeeds (e2e in Story 4).

## Story 4 — Proof through the UI, gates, docs

**Estimate:** S

- `MollieAdmin/CaptureSectionAfterCapture.spec.ts` green on the same render (Story 1's assertion); extend with a refund
  click after the measured lag → "Refunded" shows the amount (reuse `AdminRefundFlow` steps).
- Gates in CI form; `done/MOL-30-….md`; `status.md`; memory note; sound.

**Definition of Done:** CI green on the branch; merge on the product owner's word.

## Done (2026-10-09)

Stories 1–4 delivered as planned; details, measurement and follow-ups in `../done/MOL-30-refund-after-capture-without-reload.md`.
Two plan details moved during implementation: the lookup-failure case stays fail-closed (0, as the capture bound), and the
refund rule lives in a small pure class `Service\RefundBound` used by both the panel and `RefundService` rather than on
`AdminActionBounds`, so the service does not depend on the admin snapshot provider. The measurement came from Mollie's own
timestamps instead of the spec's poll (placeholder API key in the e2e env).
