# Sprint MOL-32 — Multi-capture support (Mollie multi-capture now enabled on the account)

**Date drafted:** 2026-10-16 · **Repo:** mollie-payment · **Precondition met:** the merchant's Mollie
profile now has **multiple captures enabled** for the relevant methods (cards/PayPal; Klarna/Billie
have it by default). This unblocks MOL-31.

Grounding: `reports/../20261012/reports/MOL-31-partial-capture-remainder.md` is the read-only
investigation this sprint implements. MOL-31 established that with multi-capture **off** the first
partial capture is final and Mollie releases the remainder; it also catalogued five module defects
(1–5 below) that block the correct result *even once Mollie enables multi-capture*. MOL-31's
recommendation was "do option B now, option A when the account is ready." **The account is now
ready**, so this sprint delivers **A**, and keeps **B** as the safe behaviour when the flag is off.

## Goal

A manual-capture, authorized Mollie payment can be **captured in several partial captures** until
fully captured. While an authorized remainder exists it stays **capturable** and **releasable**; the
order is marked paid / the contract fulfilled **only when fully captured** (or when the merchant
releases the rest). The merchant always sees the true figures (captured so far, still-capturable
remainder, released) and never a silent success or a partially-captured order stamped "paid in full".

## Non-goals (out of scope)

- Changing Mollie's own rules (full-only methods like Riverty/Billink; the authorization-period
  limits). We model what the API permits, no more.
- Auto-detecting multi-capture from Mollie: **Mollie exposes no API flag for it** (MOL-31). The
  account capability is a module setting the merchant sets after Mollie confirms it (S2).
- Refund logic (RefundService) beyond making sure capture/refund figures stay consistent.

## Design constraints to honour (TDD · Clean Code · SOLID · DRY)

- **TDD, red first, both levels where money moves.** Every story starts with a failing test that
  encodes the behaviour; pure-domain stories are unit-only, money paths get an integration/e2e proof.
  The money paths (S1, S3, S4, S6) are not "done" without a green reproduction that was red on the
  unverified tree.
- **SRP.** Four distinct responsibilities, four seams, no mixing: (a) *modelling* the capturable
  remainder — pure, `MolliePaymentDto`; (b) *deciding* whether a capture completes the order —
  `CaptureCompletionPolicy`; (c) *orchestrating* the PSP call + state transition — `CaptureService`
  / new `ReleaseAuthorizationService`; (d) *presenting* — `MolliePanelViewDataBuilder`.
- **OCP / DIP, no scattered `if ($setting)`.** The single-vs-multi behaviour lives behind one
  injected policy object (`MultiCaptureMode` + `CaptureCompletionPolicy`), resolved from the setting
  once at the edge. Services depend on the interface, never read the setting themselves. Adding a
  future "auto" detection becomes a new policy implementation, not edits across services.
- **DRY.** Reuse what exists: `MolliePaymentDto::capturableAmount()` and `AdminActionBounds` for the
  bound (one formula), `MoneyComparison` for the epsilon, MOL-30's admin-message channel for capture
  feedback, `ProviderOrderId::requireFrom()` for the id guard, the `ReleaseAuthorizationService`
  mirrors `CaptureService`/`RefundService`'s shape rather than inventing a new one.
- **Clean Code.** Name the states ("partially captured, remainder authorized"), no boolean-trap
  arguments, no silent `catch`, no magic amounts; every deviation documented in place as the module
  already does.

## Stories

Sequenced so each builds on green predecessors; S1 and S2 are the backbone and can land together.

### S1 — Model the capturable remainder (pure domain, unit-only)
**Problem.** `MolliePaymentDto::capturableAmount()` returns `amountRemaining` for an authorized
payment, but `amountRemaining` is Mollie's *refund*-oriented field; under multi-capture the
still-capturable remainder of an authorized payment is `amount − amountCaptured`. Today the formula
is only ever exercised while `amountCaptured` is null (pre-capture), so it happens to be right; a
partial multi-capture makes it wrong.
**Red test.** `MolliePaymentDtoTest`: status `authorized`, amount 100.00, amountCaptured 40.00 →
`capturableAmount()` expected 60.00 (currently returns `amountRemaining`/100.00). Keep the existing
guards green: non-authorized → 0.00; authorized, amountCaptured null → full `amount`.
**Change.** `capturableAmount()` = `max(0, amount − (amountCaptured ?? 0))` while `authorized`, else
0. Pure, no I/O.
**SOLID/DRY.** SRP (modelling only); the one formula every bound/guard reuses.
**DoD.** Unit red→green; `AdminActionBounds::captureBound` and `CaptureService` inherit the fix for
free (DRY), confirmed by their existing tests staying green.

### S2 — Multi-capture mode object + account setting (backbone, unit-only)
**Problem.** The single-vs-multi decision must exist in exactly one place, injected, with the setting
read once — not sprinkled as `if ($config->isMultiCapture())` across services.
**Red test.** `CaptureCompletionPolicyTest`: given a capture result + the live capturable remainder,
decide `FullyCaptured` vs `PartialCaptureRemains`; in **single** mode the first partial capture is
always `FullyCaptured` (today's honest behaviour, MOL-31 B); in **multi** mode it is
`PartialCaptureRemains` while a remainder is authorized.
**Change.** New module setting `blMollieMultiCapture` (metadata.php, group MOLLIE_GENERAL, default
`false`, label "Multiple captures enabled on the Mollie account (confirm with Mollie first)").
`ModuleConfigurationService::isMultiCaptureEnabled()`. A small `CaptureCompletionPolicy`
(interface + impl) injected into `CaptureService`; the setting is resolved to the policy in
services.yaml, not inside the service.
**SOLID/DRY.** OCP/DIP backbone; default-off = zero behaviour change when unset (no regression).
**DoD.** Unit covers both modes; wiring compiles in the container (integration smoke).

### S3 — A partial capture no longer fulfils the order / stamps OXPAID (money path)
**Problem (MOL-31 defect 1).** `CaptureService::applyCapture()` on a COMMITTED contract calls
`fulfill()` → ContractFulfilledEvent → OXPAID, for *any* capture including a partial one. Order 823
read "paid in full" after 40.00 of 99.50.
**Red test.** `CaptureServiceTest` (unit): multi mode, capture 40 of 100 → contract **not**
fulfilled, `fulfill()` **not** called, captured-amount recorded 40, state stays capturable. Then
capture the remaining 60 → fulfilled, OXPAID stamped. Integration (`StockRestoration`-style real
contract/order): OXPAID empty after the partial, stamped after the final capture.
**Change.** `applyCapture()` consults `CaptureCompletionPolicy`: fulfil (COMMITTED→FULFILLED) only on
`FullyCaptured`; on `PartialCaptureRemains` record the amount + timestamp and leave the contract in a
still-capturable state. Single mode → unchanged (fulfils, MOL-31 B).
**SOLID/DRY.** SRP (the fulfil decision leaves the service and lives in the policy); reuses S1's bound.
**DoD.** Unit + integration red→green; `pre-commit-check.sh` green.

### S4 — Subsequent captures allowed while a remainder is authorized (state guard, money path)
**Problem (MOL-31 defect 2).** `CaptureService::assertCapturable()` accepts AUTHORIZED/COMMITTED
only; after the first capture a fulfilled contract is rejected with a `DomainException` — blocking a
second capture even on Klarna/Billie where Mollie allows it today.
**Red test.** `CaptureServiceTest`: capture 40 of 100 (multi), then capture 30 → **second capture is
accepted**, bound 60 then 30; a third capture exceeding the 30 remainder → `InvalidArgumentException`
(reuses `MoneyComparison`); over the authorization total → rejected. e2e on a multi-capture method
(Klarna/Billie, or the now-enabled card profile): two partial captures on one order succeed and the
Mollie payment shows cumulative `amountCaptured`.
**Change.** The partial-capture state from S3 remains capturable; `assertCapturable()` recognises it;
the post-capture transition keeps the contract capturable until `FullyCaptured`.
**SOLID/DRY.** Clean Code (named state, no silent reject); one money-comparison.
**DoD.** Unit + e2e red→green.

### S5 — Capture failures reach the admin (no silent failure, DRY with MOL-30)
**Problem (MOL-31 defect 3).** The capture handler swallows failures like the refund handler did
before MOL-30 — a refused capture (422 "capture marked as final", over-capture, authorization
lapsed) shows no admin message, so the re-render can offer a capture that fails silently.
**Red test.** Admin-flow test: a capture Mollie refuses surfaces a translated admin error, not a
silent success; the panel does not then claim the capture happened.
**Change.** Route the capture outcome through MOL-30's existing admin-message channel
(`AdminValidationFeedback` / the refund feedback seam) — one optional argument away per MOL-31.
**SOLID/DRY.** Reuses the MOL-30 channel verbatim; no new feedback mechanism.
**DoD.** e2e/integration red→green; no silent-catch (NoSilentCatchRegressionTest stays green).

### S6 — Release the remaining authorization (new capability, money path)
**Problem (MOL-31 defect 4).** No `release-authorization` support and no way for the merchant to
return the still-authorized remainder deliberately; the panel shows no "released" figure.
**Red test.** `ReleaseAuthorizationServiceTest` (unit): release on a partially-captured authorized
payment calls the adapter's `release-authorization`, moves the contract to a terminal
"captured-what-we-captured, remainder released" state, keeps the captured amount, does not touch
OXPAID beyond the captured value. Integration: the Mollie payment flips to `paid` with the captured
amount and the remainder gone; a release on a non-authorized payment is refused with an admin message
(422 "Finalized payments cannot be reversed" — MOL-31 confirmed this path).
**Change.** `ReleaseAuthorizationServiceInterface` + impl mirroring `CaptureService` (focused
interface, DIP, `ProviderOrderId::requireFrom`), a `MollieReleaseAuthorizationAdapter` method, a
`ReleaseRequestedEvent` wired through `OrderActionDispatcher` like capture/cancel, and the admin
action. Distinct from existing full "cancel" (which releases the *whole* hold before any capture).
**SOLID/DRY.** New service follows the established capture/refund shape; reuses the event/dispatch
and feedback plumbing.
**DoD.** Unit + integration red→green; gates green.

### S7 — Panel shows the true picture and gates the buttons (presentation)
**Problem (MOL-31 defects 1 & 4, display side).** The panel must show captured-so-far, the
still-capturable remainder, and (post-release/settlement) the released amount; Capture + Release
available while a remainder is authorized, Refund on the captured part.
**Red test.** `MolliePanelViewDataBuilderTest` (unit) + the Integration template render
(`PaymentMethodRowRender`-style): multi mode, 40 of 100 captured → `isCapturable` true with bound 60,
a Release control visible, captured 40 shown, refundable 40; after full capture → not capturable,
fulfilled, refundable 100; single mode → exactly today's rendering (no Release, capture final).
**Change.** Extend `MolliePanelViewDataBuilder` / `AdminActionBounds` flags; reuse the bound formulas
(DRY). Branch display on the injected mode, never on a raw setting read in the template.
**SOLID/DRY.** SRP (presentation only); one bounds source feeds display and the write services.
**DoD.** Unit + template integration red→green.

### S8 — Docs + end-to-end proof
**Change.** `docs/for_merchant/02-admin-panel.md`: multi-capture flow, that the remainder stays
capturable, Release vs Cancel, and — when the setting is off — that a partial capture is final and
Mollie releases the rest (MOL-31 B, kept honest). `docs/for_developer`: the policy seam and the
setting. One e2e that captures an order in two parts and finalises it on the full capture; one that
captures partially then releases the remainder.
**DoD.** Docs updated; both e2e green on a multi-capture-enabled method; `status.md` + a `done/`
report written; sound played per the ritual.

## Sequencing & dependencies

```
S1 (DTO) ─┬─> S3 (no fulfil on partial) ─> S4 (subsequent captures) ─┐
S2 (policy+setting) ─┘                                               ├─> S7 (panel) ─> S8 (docs+e2e)
S5 (admin feedback, independent, DRY MOL-30) ───────────────────────┤
S6 (release-authorization, needs S1/S3 state) ──────────────────────┘
```
S1+S2 first (backbone). S5 can proceed in parallel. S7/S8 last.

## Risks & how the sprint de-risks them

- **Mollie field semantics under multi-capture.** The still-capturable remainder's exact source
  (`amount − amountCaptured` while `authorized`) is asserted in S1 and **must be confirmed by a
  characterization test against the live multi-capture profile** (or Klarna/Billie, multi-capture by
  default) at the top of S1 before the formula is trusted — MOL-31 only observed the single-capture
  path live.
- **State model.** payment-base's states are AUTHORIZED / READY_TO_COMMIT / COMMITTED / FULFILLED. A
  "partially captured, remainder authorized" state may need to be expressed as "stays AUTHORIZED
  until fully captured" rather than a new state; S3 must verify the chosen transition is legal in
  payment-base (and whether this is a Mollie-side-only concern or needs a payment-base spin-off).
- **Shared container / co-active PSPs.** The manual-capture order reaches COMMITTED via the shared
  return chain (STRP-118); S3's "don't fulfil on partial" must not regress the single-capture path or
  the Stripe/PayPal flows — their existing tests are the guard.
- **No regression when the flag is off.** Default-off means today's behaviour is the fallback; every
  existing capture test must stay green with the setting unset.

## Definition of Done (sprint)

All stories' DoD met; `./bin/pre-commit-check.sh` ALL PASSED (phpcs, phpunit both configs, phpstan
level 6, phpmd); the two new e2e green on a multi-capture method; merchant + developer docs updated;
MOL-31's five defects closed or explicitly carried with reason; a `done/MOL-32-*.md` report and the
`status.md` bullet written. Nothing merged without the e2e proof against a multi-capture-enabled
profile — the money path is not provable on a single-capture account.
