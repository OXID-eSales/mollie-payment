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

## Guest checkout (no account)

An anonymous token (`query { token }`) that carries `PAYMENT_CHECKOUT` can pay without registering. Anonymous tokens
cannot call `basketSetPayment`, so `mollieCheckoutStart` names Mollie itself (no `paymentId` argument is needed) and the
optional `guest` argument says who the shopper is. `guest` is read only for anonymous tokens; a logged-in user ignores it.

```graphql
mutation {
  mollieCheckoutStart(
    basketId: "<basket id>"
    confirmTermsAndConditions: true
    returnUrl: "https://shop.example.com/checkout/return"
    method: "ideal"
    guest: {
      email: "ada@example.com"
      invoiceAddress: {
        firstName: "Ada", lastName: "Lovelace", street: "Main St", streetNumber: "1",
        zipCode: "79098", city: "Freiburg", countryId: "<countries { id }>", salutation: "MRS"
      }
      deliveryAddress: { firstName: "Ada", lastName: "Lovelace", street: "Side St", streetNumber: "2",
        zipCode: "79098", city: "Freiburg", countryId: "<countries { id }>" }   # optional
    }
  ) { contractId contractToken redirectUrl renderMode }
}
```

Flow: `token` → `basketCreate` → `basketAddItem` → `mollieCheckoutStart` (with `guest`) → Mollie's page →
`mollieCheckoutReturn(contractId, contractToken)`. Keep `contractId` and `contractToken` on the client: the return URL
carries nothing. Return and cancel never look at who is calling; the contract token is the only credential, so another
anonymous token holding the right contract token can finish the contract, and a wrong token is refused (`invalid_token`).

Errors (`extensions.errorCode`): `guest_details_required` (anonymous token without details, or a missing or invalid
field; the message names the field, never the value) and `account_exists` (the e-mail belongs to a registered account:
the shopper has to log in). Optional address fields: `salutation`, `company`, `additionalInfo`, `stateId`, `phone`.

Known gap: payment-base's contract-first flow does not yet apply a basket's delivery address to the order; the guest's
`deliveryAddress` is stored for the guest, but the order ships to the invoice address.
