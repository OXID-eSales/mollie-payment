# GRAPH-QL / MS6 — Proof: the headless Mollie checkout end-to-end, ended by the webhook (DONE 2026-10-06)

**Sprint:** [../sprints/GRAPH-QL-mollie-provider-story.md](../sprints/GRAPH-QL-mollie-provider-story.md) · **Branch:** `b-7.4.x-GRAPH-QL`

## What was proven

A real shopper flow with **no Twig page involved**, against the dev shop (GraphQL over `https://daniil.oxiddev.de/graphql/`,
Mollie test mode, Mollie's webhook reaching the tunnel):

| Playwright test (`tests/e2e/playwright/tests/GraphQL/mollie-headless-checkout.spec.ts`, project `mollie-graphql`) | Proves |
|---|---|
| start → pay on Mollie → the webhook ends the order; return only reports | `token` → `basketCreate` → `basketAddItem` → `mollieCheckoutStart(method: paypal)` answers contract id/token, order number and Mollie's `redirectUrl`; contract `pending`, order `NOT_FINISHED`; the browser picks "Paid" on Mollie's test page; Mollie sends it to the client's `returnUrl&contract_id=…`; **the webhook** moves the contract to `fulfilled` and the order to `OK` / paid — polled in the DB, no return leg; `mollieCheckoutReturn` then answers `committed` + the order number |
| a failed payment closes the contract and the order through the webhook; return says failed | "Failed" on Mollie's page → webhook → contract `failed`, order `FAILED`; `mollieCheckoutReturn` → `failed` |
| cancel retires an unpaid attempt; a wrong token is refused; method hint reaches Mollie | `method: ideal` → Mollie's issuer page; wrong token → `invalid_token`, nothing changes; cancel → `cancelled`, order stornoed; return of the cancelled attempt is refused / `failed` |
| core placeOrder is refused for a Mollie basket and points at the mutation | payment-base's `BeforePlaceOrder` guard names `mollieCheckoutStart` |

Result: **4 passed (40.5 s)**. Run: `cd tests/e2e/playwright && SHOP_URL=https://daniil.oxiddev.de/
npx playwright test --project=mollie-graphql --reporter=line`. The spec waits for the webhook by polling
`oe_payments_contract.OXSTATE` through `fixtures/shop-db.ts`.

Also delivered: `bin/graph-ql-cli-test.sh` + `bin/graph-ql-cli-test.md` (same commands as Stripe's: `schema`, `start
[method]`, `pay`, `return`, `cancel`, `wrong-token`, `guard`, `demo`, `raw`), `demo` run clean against the dev shop
(orders 800 / 801 opened and cancelled); README links the docs.

## What the proof found

| Found | Fix |
|---|---|
| A failed Mollie payment leaves the order `OXTRANSSTATUS = FAILED`, not stornoed (`OxidContractLinkedOrderUpdater::markFailed`); only a cancel stornoes. | Existing Twig semantics, kept; the spec asserts `FAILED`. |
| Everything else worked first time: the client return URL with `contract_id`, the method hint, the webhook commit, the guard. | — |

## Not in this story

- Manual-capture "Authorized" through Mollie's test page is not scripted (which test-page options Mollie offers
  depends on the method and the capture mode); MS3's unit tests cover the commit, and the admin capture is the
  existing flow. A hand test: `pay creditcard`, pick "Authorized", capture on the admin Payment tab.
- CI runs the module's unit and integration suites; the Playwright proof needs the e2e environment (tunnel + Mollie
  test key) and is not part of CI.

## Gates

- Mollie Unit (standalone) **743** green · Integration 38 + 2 green · PHPStan / phpcs (CI form) / phpmd clean
- Mollie GitHub Actions on `b-7.4.x-GRAPH-QL`: green for MS1+MS2 and MS3 (CI pinned to payment-base `b-7.4.x-GRAPH-QL`,
  TEMPORARY — revert `PAYMENT_BASE_BRANCH` / `PAYMENT_BASE_VERSION_ALIAS` after the payment-base merge); MS4–MS6: see status
