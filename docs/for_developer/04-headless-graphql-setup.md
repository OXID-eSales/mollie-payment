# 04 — Headless (GraphQL) setup

For storefronts that are not the OXID Twig theme. The module exposes the Mollie payment as three GraphQL
mutations on top of payment-base's headless checkout. The full guide (API, security model, errors, building a
frontend) is Stripe's `docs/for_developer/05-headless-graphql-checkout.md` in the Stripe module;
everything provider-neutral in it applies to Mollie. Hand-test script: [`bin/graph-ql-cli-test.md`](../../bin/graph-ql-cli-test.md).

## Install

Needs `oxid-esales/graphql-base`, `oxid-esales/graphql-storefront` and payment-base >= 1.5 (guest checkout) in the shop.

```bash
vendor/bin/oe-console oe:module:install extensions/mollie-payment   # path as in your shop
vendor/bin/oe-console oe:module:activate oe_payments_mollie
vendor/bin/oe-console oe:graphql:cache-clear
```

In an SDK checkout the console is `bin/oe-console`.

**Do the cache-clear after every activation and every module update.** GraphQLite caches the schema on the file system
and never clears it automatically; without the step a new mutation or argument is missing
(`Cannot query field "mollieCheckoutStart"` / `Unknown argument "guest"`) or the old schema stays live.

## The three mutations

| Mutation | Needs |
|---|---|
| `mollieCheckoutStart` | an (anonymous or logged-in) token with the `PAYMENT_CHECKOUT` right; optional `guest` for anonymous tokens |
| `mollieCheckoutReturn` | `contractId` + `contractToken` (the client keeps them from the start; the return URL carries nothing) |
| `mollieCheckoutCancel` | `contractId` + `contractToken` |

Mollie sends the shopper back to your `returnUrl` unchanged; Mollie's webhook finishes the order.
