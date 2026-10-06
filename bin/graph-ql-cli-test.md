# `bin/graph-ql-cli-test.sh` — the headless Mollie checkout from the command line

A curl + jq script that shows what the Mollie module can do through the **GraphQL Storefront**, with no Twig page
involved: open a contract (Mollie's hosted page, optional method hint), let Mollie's webhook end the order, read the
state, cancel an attempt, and see the two refusals that protect the flow. It is the CLI twin of the Playwright spec
`tests/e2e/playwright/tests/GraphQL/mollie-headless-checkout.spec.ts` (project `mollie-graphql`) and of the GRAPH-QL
MS1–MS6 reports in `docs/dev_day_log/20261006/`. Stripe has the same script with the same commands.

## What "GraphQL support" means here

The Mollie module follows **Option B** of the GRAPH-QL epic: the contract-first flow is kept as it is and exposed
through three provider mutations. The core storefront `placeOrder` is **not** used for Mollie baskets; payment-base
refuses it and names the mutation to call instead.

```
client                     GraphQL Storefront            payment-base (headless)         Mollie
------                     ------------------            -----------------------         ------
token ─────────────────▶   JWT
basketCreate/AddItem ──▶   oxuserbaskets row
mollieCheckoutStart ───▶   Mollie controller ─────────▶  HeadlessCheckoutService.start
                                                         ├ basket + user from the row (no PHP session)
                                                         ├ early order (NOT_FINISHED), contract PENDING
                                                         └ MolliePaymentHandler ───────▶ payment (redirectUrl = client URL)
                        ◀─ CheckoutStartResult { contractId, contractToken, orderNumber, redirectUrl, renderMode: redirect }
(shopper pays on Mollie's hosted page; in TEST mode the tester picks the outcome)
                                                                                   ◀─── webhook: paid / authorized / failed …
                                                         paid → committed + fulfilled (OXPAID); authorized → committed,
                                                         not paid (capture in the admin); failed/canceled/expired → closed
mollieCheckoutReturn ──▶   ……………………………………………………………▶  reports the state the webhook left (commits itself if none came)
                        ◀─ CheckoutReturnResult { status, orderId, orderNumber, contractState }
mollieCheckoutCancel ──▶   ……………………………………………………………▶  retire the unpaid attempt (order cancelled)
                        ◀─ CheckoutCancelResult { cancelled, contractId, contractState }
```

- **Mutations** (`#[Logged] #[Right('PAYMENT_CHECKOUT')]`, granted to `oxidcustomer` and `oxidnotyetordered`):

  | Mutation | Arguments | Returns |
  |---|---|---|
  | `mollieCheckoutStart` | `basketId: ID!`, `confirmTermsAndConditions: Boolean!`, `returnUrl: String!`, `cancelUrl: String` (accepted, unused — Mollie has one redirect URL), `method: String` (hint: ideal, creditcard, klarna, …), `uiMode: String` (`hosted` only) | `CheckoutStartResult!` |
  | `mollieCheckoutReturn` | `contractId: String!`, `contractToken: String!` | `CheckoutReturnResult!` |
  | `mollieCheckoutCancel` | `contractId: String!`, `contractToken: String!` | `CheckoutCancelResult!` |

- **Result types** are payment-base's, shared by every provider (`status` of a return is `committed`, `pending`
  while Mollie has not decided, or `failed` for a cancelled / failed attempt).
- **Refusals** are GraphQL errors with a stable `extensions.errorCode` (payment-base `HeadlessCheckoutException`):
  `basket_not_found`, `payment_not_supported`, `terms_not_confirmed`, `return_url_rejected` (+ `providerCode`
  such as `origin_not_allowed`), `user_not_found`, `provider_failed` (+ Mollie's code, e.g.
  `MOLLIE_UI_MODE_UNSUPPORTED`, `MOLLIE_ITEMS_NOT_ORDERABLE`), `contract_not_found`, `invalid_token`.
- The **contract token** is the client's proof of ownership for return and cancel; a wrong token changes nothing.
- Mollie sends the shopper back to `returnUrl` **+ `contract_id=<contractId>`** (we append it; Mollie appends nothing),
  for every outcome. The return URL need not exist in the shop; a real client owns it.
- `mollieCheckoutStart` names its payment itself (`oe_payments_mollie`): the client needs no `basketSetPayment` first.
  A basket explicitly set to another payment is refused with `payment_not_supported`.
- Inline card entry (Mollie Components) needs a browser and is not part of the headless flow; `uiMode` other than
  `hosted` is refused before anything is created.

## Prerequisites

- A shop with `oe_graphql_base` and `oe_graphql_storefront` active, payment-base `b-7.4.x-GRAPH-QL` (Sprint 15) and
  this module's `b-7.4.x-GRAPH-QL` active, a Mollie **test** API key and profile configured.
- Mollie's webhook must reach the shop: `https://<shop>/index.php?cl=MollieWebhookController` (or `sMollieWebhookUrl`).
  The dev shop's tunnel `https://daniil.oxiddev.de` works; `localhost.local` does not.
- A customer in a country with a delivery set. The dev shop has `headless.user@oxid-esales.dev` / `useruser`.
- `curl` and `jq` on the machine you run the script from (the host; the PHP container has no jq).

## Usage

```
bin/graph-ql-cli-test.sh <command> [args]

  schema                  the Mollie mutations and their result types, as the schema exposes them
  start [method]          login, create a basket with one product, mollieCheckoutStart (method hint optional)
  pay [method]            = start, then tells you how to pay on Mollie's test page and what ends the order
  return [contractId contractToken]   mollieCheckoutReturn (ids default to the last start)
  cancel [contractId contractToken]   mollieCheckoutCancel (ids default to the last start)
  wrong-token             mollieCheckoutCancel with a bogus token: refused, nothing changes
  guard                   core placeOrder on a basket paying with Mollie: refused, names mollieCheckoutStart
  demo                    everything that needs no browser
  raw '<query>'           any GraphQL document, logged in
```

Environment (all optional):

| Variable | Default | Meaning |
|---|---|---|
| `GRAPHQL_URL` | `http://localhost.local/graphql/` | the endpoint |
| `SHOP_URL` | origin of `GRAPHQL_URL` | base of `RETURN_URL`. **Must be the shop's configured URL** (or an origin listed in `sPaymentBaseHeadlessReturnOrigins`), otherwise `return_url_rejected / origin_not_allowed`. Dev shop: `SHOP_URL=https://daniil.oxiddev.de/` |
| `USER_EMAIL` / `USER_PASSWORD` | `headless.user@oxid-esales.dev` / `useruser` | the customer |
| `PRODUCT_ID` | `5e6a374e212258abbfd76b6adf911772` | "Panorama", 20.90 EUR |
| `MOLLIE_METHOD` | empty | default method hint for `start` / `pay` |
| `DELIVERY_METHOD_ID` | `oxidstandard` | used by `guard` only |
| `RETURN_URL` | `${SHOP_URL}index.php?cl=start&headless=return` | the shop's start page, so a hand test lands on the shop; we append `&contract_id=…` |
| `STATE_FILE` | `/tmp/graph-ql-cli-test-mollie.state` | the last start's contract id / token / basket id |
| `VERBOSE` | `0` | `1` prints every raw JSON response to stderr |

## Examples

### Everything without a browser

```bash
SHOP_URL=https://daniil.oxiddev.de/ bin/graph-ql-cli-test.sh demo
```

```
Mollie mutations in the schema (logged in …)
  mollieCheckoutStart(basketId: ID, confirmTermsAndConditions: Boolean, returnUrl: String, cancelUrl: String, method: String, uiMode: String): CheckoutStartResult
  mollieCheckoutReturn(contractId: String, contractToken: String): CheckoutReturnResult
  mollieCheckoutCancel(contractId: String, contractToken: String): CheckoutCancelResult

mollieCheckoutStart
  basket …, total 30.9
{ "contractId": "bf3b2e4d…", "contractToken": "…", "providerName": "mollie", "orderNumber": "800",
  "redirectUrl": "https://www.mollie.com/checkout/select-method/…", "clientSecret": null, "renderMode": "redirect" }

mollieCheckoutReturn            (before paying)
{ "status": "pending", "orderId": null, "orderNumber": "800", "contractState": "pending" }

mollieCheckoutCancel
{ "cancelled": true, "contractState": "cancelled" }

mollieCheckoutStart (method: ideal)
{ …, "orderNumber": "801", "redirectUrl": "https://www.mollie.com/checkout/select-issuer/ideal/…", … }

mollieCheckoutCancel with a wrong token (expected: refused)
  The contract token does not authorise contract 7f524408… [invalid_token]

core placeOrder on a basket that pays with Mollie (expected: refused, points at mollieCheckoutStart)
  Payment "oe_payments_mollie" is handled by the mollie checkout: call mollieCheckoutStart instead of placeOrder [-]
```

Every `start` opens a real contract, a `NOT_FINISHED` order and a Mollie test payment; `demo` cancels what it opened.

### A paid order, ended by the webhook

```bash
SHOP_URL=https://daniil.oxiddev.de/ bin/graph-ql-cli-test.sh pay paypal
# open the redirectUrl in a browser: Mollie's TEST page — pick "Paid", Continue
# Mollie's webhook commits and fulfils the order; the browser lands on the shop start page with &contract_id=…
SHOP_URL=https://daniil.oxiddev.de/ bin/graph-ql-cli-test.sh return
```

```
mollieCheckoutReturn
{ "status": "committed", "orderId": "<32 hex>", "orderNumber": "802", "contractState": "fulfilled" }
```

With manual capture (`sMollieCaptureMode = manual`) and a card / Klarna method, pick "Authorized": the webhook commits
the order **not paid** (MS3), the merchant captures on the admin Payment tab, and the following `paid` webhook fulfils it.
Pick "Failed" or "Canceled" to see the contract closed and the order cancelled; `return` then answers `failed`.

### Your own queries

```bash
bin/graph-ql-cli-test.sh raw '{ baskets(owner: "headless.user@oxid-esales.dev") { id title } }'
bin/graph-ql-cli-test.sh raw 'mutation { mollieCheckoutStart(basketId: "…", confirmTermsAndConditions: true, returnUrl: "…", uiMode: "embedded") { contractId } }'
#   → "Mollie supports uiMode \"hosted\" only, \"embedded\" requested" [provider_failed/MOLLIE_UI_MODE_UNSUPPORTED]
```

## Gotchas

- **User agent.** OXID disables the basket for user agents it takes for search engines; curl's default is one (the
  symptom is `total 0` and "no delivery"). The script sends a browser-like `User-Agent`.
- **Introspection.** The storefront's `basketSetDeliveryMethod`, `basketSetPayment` and the Mollie mutations are
  `#[Logged]`, so anonymous introspection does not list them. `schema` logs in first.
- **Return origin.** `returnUrl` must be under the shop's own URL or an origin in `sPaymentBaseHeadlessReturnOrigins`.
- **placeOrder guard** fires after the storefront's own checks: without a delivery method the core answers "Delivery
  set must be selected!" first. `guard` sets delivery method and payment, then calls `placeOrder`.
- **Pay-later methods** (Klarna, Riverty, Billie, in3) need a complete billing address on the customer; the headless
  path takes it from the persisted basket's user. Without one the method hint is dropped and Mollie's page offers the
  rest — as in the Twig checkout.
- A `services.yaml` change is live only after `var/cache/container/` is cleared (`oe:cache:clear`).

## Where the pieces live

- Mollie: `src/Mollie/GraphQL/Controller/MollieCheckout.php` (mutations), `GraphQL/Exception/MollieCheckoutError.php`,
  `GraphQL/Service/NamespaceMapper.php`, `PaymentHandler/MolliePaymentHandler.php` (headless path, `metadata.headless`),
  `EventSystem/Handler/MollieCheckoutSessionHandler.php` (client return URL), `Webhook/Handler/WebhookContractFulfillmentHandler.php`
  (authorized → commit with `requiresCapture`), `Mcp/MollieAcpCheckoutService.php`.
- payment-base: `src/Checkout/Headless/*`, `src/GraphQL/DataType/Checkout*Result.php`,
  `src/GraphQL/Subscriber/RefusePlaceOrderForContractFirstPayments.php`, `src/Service/Commit/*`.
- Reports: `docs/dev_day_log/20261006/` (MS1–MS6) and payment-base `docs/dev_log/20261006/` (Sprint 15).
