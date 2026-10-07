# GRAPH-QL / MS4 — mollieCheckoutStart / Return / Cancel (DONE 2026-10-06)

**Sprint:** [../sprints/GRAPH-QL-mollie-provider-story.md](../sprints/GRAPH-QL-mollie-provider-story.md) · **Branch:** `b-7.4.x-GRAPH-QL`

## What changed

| Piece | Job |
|---|---|
| `GraphQL\Controller\MollieCheckout` (`#[Mutation] #[Logged] #[Right('PAYMENT_CHECKOUT')]`) | `mollieCheckoutStart(basketId, confirmTermsAndConditions, returnUrl, cancelUrl?, method?, uiMode = hosted)` → `CheckoutStartResult` (payment-base's type; `redirectUrl` to Mollie's hosted page, `renderMode: redirect`): the JWT user is the buyer, `paymentId: oe_payments_mollie` (a basket the client never ran through `basketSetPayment` still starts, one set to another payment is refused), `method` travels as `providerOptions.mollieMethod` (MS1). `mollieCheckoutReturn(contractId, contractToken)` → `CheckoutReturnResult` — no provider parameters: the resolver asks Mollie by the contract's payment id. `mollieCheckoutCancel(contractId, contractToken)` → `CheckoutCancelResult`. Refusals become `MollieCheckoutError` |
| `GraphQL\Exception\MollieCheckoutError extends graphql-base Error` | client-safe, `requesterror`, `extensions.errorCode` (stable, payment-base) + `extensions.providerCode` (e.g. `MOLLIE_UI_MODE_UNSUPPORTED`) |
| `GraphQL\Service\NamespaceMapper` | **mirrors** `NamespaceMapperInterface` without implementing it (module must activate on a shop without graphql-base — Stripe PS6 lesson); tagged `graphql_namespace_mapper` |
| `services.yaml` | the two services under `services:` right after `_defaults`, `autowire: false`, `$authentication: '@?…Authentication'` so the container compiles without graphql-base |
| `tests/bootstrap-unit.php`, `tests/PhpStan/phpstan-bootstrap.php` | GraphQLite / graphql-base stubs (copied from Stripe PS4) |

Mollie specifics the mutation documents: one redirect URL for every outcome (`cancelUrl` accepted, unused; a
cancelled payment returns to `returnUrl` and `mollieCheckoutReturn` answers `failed`); `method` is a hint, without it
Mollie's page offers every method; `uiMode` is `hosted` only.

## Red → green

- `Unit\GraphQL\Controller\MollieCheckoutTest` (7): start hands user, payment id, URLs and the method hint; no method ⇒
  no provider options; refusal ⇒ client-safe error with `errorCode` + `providerCode`; return with no provider params;
  cancel; invalid token ⇒ error; without graphql-base the mutation explains itself.
- `Unit\GraphQL\Service\NamespaceMapperTest` (1), `OptionalGraphQlDependencyTest` (2: method parity with the
  interface; no reflected service class touches graphql-base / GraphQLite).
- `Integration\GraphQL\SchemaContainsMollieMutationsTest` (2, shop PHPUnit): the controller resolves from the real
  container; GraphQLite builds a valid schema with the three mutations, their argument lists and payment-base's result
  types (anonymous class finder, skips without graphql-base).

## Live on the dev shop (GraphQL endpoint, headless user, Stripe's CLI `raw`)

```
mollieCheckoutStart(basketId, confirmTermsAndConditions: true, returnUrl: "https://daniil.oxiddev.de/index.php?cl=start&headless=mollie", method: "ideal")
  → contractId f557b041…, orderNumber 799, redirectUrl https://www.mollie.com/checkout/select-issuer/ideal/64iCop…, renderMode redirect
mollieCheckoutCancel(…, contractToken: "0000…dead") → error "The contract token does not authorise contract …" [invalid_token]
mollieCheckoutReturn(contractId, contractToken)  (before paying) → { status: pending, orderId: null, orderNumber: 799, contractState: pending }
mollieCheckoutCancel(contractId, contractToken) → { cancelled: true, contractState: cancelled }
basketSetPayment(…, "oe_payments_mollie") + placeOrder → error "Payment \"oe_payments_mollie\" is handled by the mollie checkout: call mollieCheckoutStart instead of placeOrder"
```

## Gates

- Unit (standalone) **740** green (730 + 10) · Integration 38 + 2 green · PHPStan No errors · phpcs CI form clean · phpmd clean
