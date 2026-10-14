# Done 04 — One checkout entry ladder for both flows + basket-hash pin (review A-H1/A-H2) — 2026-10-14

Branch: `b-7.4.x-REVIEW-01-fixes`.

## Problem

The checkout entry flow lived twice: `MollieOrderController::execute()` (standard `cl=order`) and
`MolliePaymentHandler::processPayment()` (OPC + headless) each built the same `EventContext`, ran
the same buyability validation and the same `NotOrderableCheckoutFailure` recovery — and they had
already diverged: the MOL-15 user-data gate and the MOL-18 double-click replay guard existed only
on the controller path. A second OPC "Pay" click could start a second contract/payment; address
data gone bad since the payment step went to Mollie unchecked. Separately (A-H2), the module's
byte-for-byte mirror of core's private `getBasketSummaryHash()` had no pinning test.

## Fix

- **`CheckoutSessionStarter`** — THE shared ladder: MOL-22 buyability → MOL-15 user-data gate →
  MOL-18 in-flight replay → dispatch `MollieCheckoutSessionRequestEvent`; builds the
  `EventContext` in exactly one place (incl. the headless `basketId`/`returnUrl` shape); maps a
  failing dispatch to "not orderable" (named) or "unavailable". Session-bound rungs (MOL-15/18)
  are skipped headless — payment-base's HeadlessCheckoutService owns API-client validation, and
  there is no session attempt to rejoin. New VOs `CheckoutStartRequest` / `CheckoutStartOutcome`;
  new narrow seams `CheckoutUserDataGateInterface` / `InFlightCheckoutReplayInterface`
  (implemented by the existing final services).
- **Controller** keeps its core-parity guards (CSRF → AGB → basket hash) and presentation only;
  `buildCheckoutContext`/`startCheckoutSession`/`userDataProblems`/`basketNotOrderableFailure`
  deleted in favor of the starter.
- **OPC handler** keeps `prepareOxidBasket`/uiMode/presentation; now climbs the same ladder —
  **MOL-15 and MOL-18 guard the OPC path for the first time** (`MOLLIE_USER_DATA_INVALID` error
  with the translated sentences; a replay answers success with the in-flight redirect URL, which
  OPC's frontend follows like any redirect). Dispatch failures are absorbed by the ladder as
  "unavailable" (`MOLLIE_NO_CHECKOUT_URL`); `MOLLIE_PAYMENT_FAILED` remains for failures outside
  it (basket preparation).
- **Pin (A-H2)**: `Integration/Checkout/BasketSummaryHashParityTest` reflects core's private
  `getBasketSummaryHash()` and the Mollie mirror on the SAME chained controller and asserts
  byte-identical output — core recipe drift now fails a test instead of killing checkouts.

## TDD / tests

- Red first: `CheckoutSessionStarterTest` (9 tests — rung order incl. "buyability before gate",
  "gate before replay", headless skips, throwable mapping, contract-id carry).
- New OPC-path guards pinned: `testProcessPaymentRefusesBadUserDataBeforeDispatching`,
  `testProcessPaymentReplaysAnInFlightAttemptInsteadOfStartingASecondOne` (dispatch never fires).
- Entry-point tests now drive the REAL ladder (`ScriptedLadderStarter` scripts collaborators but
  keeps `start()` real; `TestableMolliePaymentHandler` composes a real starter) instead of
  overriding the guards away.

## Proof

- Unit 761 → **772** green (both configs); Integration **41** green (3 env skips) incl. the new
  parity pin; live container resolves handler + starter; `pre-commit-check.sh` ALL PASSED.
- **E2e OPEN**: the `mollie-standard` + `mollie-opc` Playwright run was killed by the host (low
  memory), not by a failing test — rerun
  `npx playwright test --project=mollie-standard --project=mollie-opc` before merging.
- Ops note: a services.yaml change invalidates the compiled DI container AND the integration
  suite's module activate/deactivate dance can leave the module deactivated when compile fails
  mid-run — symptom: TemplateNotInChain / `_parent not found` storms; cure: fix the yaml,
  `rm -rf var/cache/container/*`, `oe:module:activate oe_payments_mollie`.
