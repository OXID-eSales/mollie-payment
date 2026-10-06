# GRAPH-QL / MS5 — Mollie ACP checkout service (DONE 2026-10-06)

**Sprint:** [../sprints/GRAPH-QL-mollie-provider-story.md](../sprints/GRAPH-QL-mollie-provider-story.md) · **Branch:** `b-7.4.x-GRAPH-QL`

## What changed

| Piece | Job |
|---|---|
| `Mcp\MollieAcpCheckoutService extends AbstractAcpCheckoutService` (payment-base) | `paymentId()` = `oe_payments_mollie`, `providerName()` = `mollie`; `create_checkout` is the base class's default (payment-base S7: buyer → shop user, items → user basket paying with Mollie, contract opened through the shared chain — early order, PENDING); `completePayment()` **refuses** with a reason the agent can act on: Mollie has no server-side charge of a delegated payment token (a card token is minted by Mollie Components in the shopper's browser, every other method needs Mollie's hosted page) — the buyer pays via `mollieCheckoutStart`'s `redirectUrl` and the webhook ends the order (MS3). The contract stays open, nothing is committed |
| `services.yaml` | `AcpResponseFormatterInterface` (payment providers `['mollie']`), the service (public) with payment-base's four headless collaborators, `AcpCheckoutServiceInterface` aliased to it — same shape as Stripe PS5. In a shop running several providers the last merged `services.yaml` decides these two shared ids (the dev shop now answers Mollie for the interface) |

## Red → green

`Unit\Mcp\MollieAcpCheckoutServiceTest` (3): is the ACP service; default `create_checkout` with the Mollie payment id
(buyer resolved, user basket created, contract opened `acp`); `complete_checkout` refused with the documented reason on
`payment_data.token`, no commit, contract still PENDING.

## Gates

- Unit (standalone) **743** green (740 + 3) · PHPStan No errors · phpcs CI form clean · phpmd clean
- Container rebuilt: `AcpCheckoutServiceInterface` and the service resolve

## Scope note

This is the provider half of the agentic-commerce layer, as in Stripe PS5: the MCP / UCP transport (server, tools,
auth guard, controllers) is a separate feature and not wired in Mollie. A future "agent hands the buyer a hosted
checkout URL" flow would open the contract through `HeadlessCheckoutServiceInterface::start()` instead of the base
default, so the response can carry Mollie's `redirectUrl`; that needs an ACP response field for it and is not part of
GRAPH-QL.
