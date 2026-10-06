# GRAPH-QL / MS3 — Webhooks end the order: an authorization commits (DONE 2026-10-06)

**Sprint:** [../sprints/GRAPH-QL-mollie-provider-story.md](../sprints/GRAPH-QL-mollie-provider-story.md) · **Branch:** `b-7.4.x-GRAPH-QL`

## Why

Mollie's `paid` webhook already commits a PENDING contract (hand-written ladder → COMMITTED → fulfil → OXPAID). With
**manual capture** (this shop: `sMollieCaptureMode = manual`, cards / Klarna / Riverty / Billie / in3) the payment
comes back `authorized`, and that only moved the contract to AUTHORIZED: the early order stayed `NOT_FINISHED` until
the merchant captured and `paid` arrived. A headless shopper who never returns to the shop would leave the money
reserved on an unfinished order — the same gap Stripe closed in PS7.

## What changed

| Piece | Job |
|---|---|
| `Webhook\Handler\WebhookContractFulfillmentHandler` | optional 6th ctor arg `?ContractCommitServiceInterface` (autowired from payment-base; a consumer whose wiring predates it keeps the AUTHORIZED-only behaviour). `handlePaymentAuthorized()` for a NOT_FINISHED / PENDING contract → `commit(PaymentConfirmation{mollie, tr_id, tr_id, contract amount + currency, requiresCapture: true, source: webhook, molliePaymentId})`: settled ⇒ audit `authorization` + `Acted` (webhook 200 `contract_authorized`); pending ⇒ `NoOp`; refused ⇒ `Failed` (5xx, Mollie retries, visible in the webhook log). The loaded contract is **not** saved afterwards — the commit service saved its own copy; saving the stale PENDING one would undo the commit |
| Order afterwards | committed, `OXTRANSSTATUS OK`, **not** paid (`ContractCommitmentHandler` reads `requiresCapture`). `CaptureService` accepts COMMITTED, the capture and the following `paid` webhook fulfil as before (`doPaymentPaid` → `advanceToCommitted` is a no-op on a committed contract, then fulfil) |
| Twig / OPC | same change applies: an authorized Twig order is committed too, instead of waiting in NOT_FINISHED for the capture. That matches the semantics of a shop where the return-leg chain commits authorizations (Stripe co-active) and what the admin Payment tab shows for an authorized order |

## Red → green

`Unit\Webhook\Handler\WebhookContractFulfillmentHandlerAuthorizedCommitTest` (5): the authorization commits with
`requiresCapture`, amount / currency from the contract, source `webhook`, no stale save, one audit row; refused ⇒
`Failed`, nothing recorded; pending ⇒ `NoOp`; already committed ⇒ `NoOp` without a commit; without a commit service
the old AUTHORIZED ladder runs. Existing `WebhookContractFulfillmentHandlerTest` (5-arg constructor) unchanged.

## Gates

- Unit (standalone) **730** green (725 + 5) · Integration (shop PHPUnit) 38 green, 3 skips
- Container rebuilt: `WebhookContractFulfillmentHandler::$contractCommit` resolves to payment-base's `ContractCommitService`
- PHPStan No errors · phpcs CI form clean · phpmd clean

## Not changed (decisions)

- The `paid` ladder is kept (it already commits and fulfils; routing it through the commit service would touch every
  Twig order for no headless gain). `expired` / `canceled` / `failed` already cancel or fail the contract and the order.
- The confirmed amount is the contract's: the handler only knows the Mollie payment id, and Mollie created the payment
  from the contract's amount. Live proof of the full chain (webhook from Mollie's test page) is MS6.
