# Done 07 — Hygiene batch (review A-M2/A-M3/A-M5, A-L2/A-L3, D-L3) — 2026-10-14

Branch: `b-7.4.x-REVIEW-01-fixes`.

## What changed

- **A-M5** — the three silent `catch (Throwable)` in `Core/ViewConfig.php` (method list, billing
  country ISO, iframe settings) now log a warning with the error before falling back — the module's
  own "safe or loud" rule, applied to its last three silent sites.
- **A-M3** — `MoneyComparison::exceeds()` (named `EPSILON`, documented: representation noise only,
  well below half a cent) replaces the `0.00001` literal in `CaptureService`, `RefundService` and
  `AdminAmountValidator`; `ProviderOrderId::requireFrom($contract, $action)` replaces the verbatim
  `requireProviderOrderId()` duplicated across Capture/Refund. New boundary test pins "0.1+0.2
  passes, a real cent does not".
- **A-M2** — `FulfillmentResult` (outcome + contractId) replaces the bare `FulfillmentOutcome`
  return of the fulfillment interface: the handler loads the contract once and the result carries
  its id, so `AbstractMollieWebhookHandler` no longer re-queries `findByProviderOrderId()` per
  delivery (and the stale-retry give-up now names the contract from the exception). The now-unused
  `ContractRepositoryInterface` left the ladder handlers' constructor. `ReconcilePaidContractsCommand`
  reads `->outcome`.
- **A-L2** — `WebhookController::render()` builds the guard request ONCE (the second build re-read
  `php://input` and re-emitted the proxy-header debug log just to recover an IP).
- **A-L3** — the orphaned `advanceToAuthorized()` docblock stranded above `recordCapturedAmount()`
  is re-attached to its method.
- **D-L3** — `.gitleaks.toml` Mollie rule matches `{25,}` instead of exactly 30 characters (Mollie
  does not guarantee the length; an exactly-30 match let differently sized real keys commit clean).

## Deliberately deferred (not silently dropped)

- **D-L2 token expiry / replay refusal** — changes the return-URL contract (in-flight checkouts at
  deploy time hold old tokens; GraphQL clients hold tokens) → its own sprint with a compat window.
- **A-M1/A-L1 webhook-handler template method**, **A-M4 payment-base re-bindings**, **A-M6/M7** —
  larger refactors / upstream decisions; still on the report's list.

## Proof

Unit **773** green in BOTH configs (3 new tests); Integration **42** green; `pre-commit-check.sh`
ALL CHECKS PASSED. (Changing a ctor signature of container-wired services again required
`rm -rf var/cache/container/*` — same stale-cache symptom as S03/S04.)
