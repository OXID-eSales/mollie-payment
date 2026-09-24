# MOL-18 — One order per checkout attempt: report

**Date:** 2026-09-24
**Ticket:** MOL-18 — "Order now" clicked several times creates several orders
**Branches:** mollie-payment `b-7.4.x-mol-10-18-single-click-and-order-state`,
payment-base `b-7.4.x-MOL-18-single-order-per-checkout-attempt`
**Plan:** `../../20260923/sprints/MOL-18-single-order-per-checkout-attempt.md` (approved with its
defaults: no replay time limit, basket mismatch = retire and recreate, OPC parity as follow-up)

## Root cause chain (confirmed by machine)

1. Apex's "Order now" is `<button onclick="form.submit()">`; Mollie's inline-components button calls
   `form.requestSubmit()`. Neither guards against a second click.
2. PHP's file session lock serialises the two POSTs: click 2 runs after click 1 has created contract A,
   order S (`OXID = sess_challenge`), the Mollie payment, and answered 302 to Mollie.
3. Click 2 creates contract B. `EarlyOrderCreationHandler::retirePreviousAttempt()` finds A in the
   session registry and retires it: A cancelled, S `CANCELLED` / `OXSTORNO = 1` (row kept for a gap-free
   number sequence).
4. `finalizeOrder()` runs with the same `sess_challenge`; core's `checkOrderExist(S)` sees the row and
   answers `ORDER_STATE_ORDEREXISTS`. `OxidShopOrderService::validateOrderState()` accepted that state
   and `setOrderFieldsAfterCreation()` saved the never-loaded `Order`: a **phantom row** — fresh id, no
   user, no articles, no payment type, total 0, `OXORDERNR 0`. Contract B was linked to it and the
   shopper paid for it.
5. The same path fired on every legitimate retry, because `sess_challenge` was never rotated when an
   attempt was retired: the retry feature (STRP-171) only ever "worked" through the phantom.

### DB evidence (this shop, `example`)

Before the sprint: 798 `oxorder` rows, **53 phantoms** (`OXPAYMENTTYPE = ''`), 8 of them with
committed/fulfilled contracts (see plan).

Story 1 red run (10:20 / 10:22, before any fix):

```
#616 payment='oe_payments_mollie' storno=1 status=CANCELLED total=329.5 articles=1
#0   payment=''                   storno=0 status=OK        total=0     articles=0   <- paid phantom, OXPAID 10:20:42
#617 payment='oe_payments_mollie' storno=1 status=CANCELLED total=116.5 articles=1
#0   payment=''                   storno=0 status=OK        total=0     articles=0
```

After Story 2 (10:32, phantom gone but still two orders because the replay had no URL to replay — see
deviations): `#618 CANCELLED storno=1` + `#619 OK` both real.

After Stories 3–4 complete (10:36 onwards): every double-click run leaves exactly one order
(`#620`, `#621`, `#622`, …), both POSTs answered with the **same** Mollie checkout URL.

Sanity at the end of the sprint (11:01):

```
SELECT COUNT(*), SUM(OXORDERDATE >= '2026-09-24 10:30:00') FROM oxorder WHERE OXPAYMENTTYPE='';
-- 55 | 0      (the two extra phantoms are the Story 1 red proofs; none since Story 2)
```

## Red → green

| Proof | Red (Story 1) | Green |
|---|---|---|
| payment-base `OxidShopOrderServiceSecondSubmissionTest` | `Failed asserting that 800 is identical to 799` (second `createOrder()` for the same challenge adds a row) | Story 2: second call throws `order_exists`, 3 tests / 21 assertions |
| Mollie e2e `OrderNowDoubleClickCreatesOneOrder` "two rapid submissions" | `Expected length: 1 / Received length: 2` (cancelled real order + paid phantom) | Story 4 + redirect persistence: 1 order, same redirect, thank-you shows its number (22 s) |
| Mollie e2e "button locks itself" | n/a (Story 5 test) | first click disables + `is-loading`; 3 clicks = 1 order POST (20 s) |
| Mollie e2e `OrderRetryAfterUnpaidReturnCreatesNewOrder` | n/a (Story 6 test) | failed payment → return → order again → 2 real orders (1 storno, 1 paid), no phantom (28 s) |

Classic redirect flow (iframe flag flipped off for one run, restored afterwards, file identical to
backup): double-click test green; button-lock assertions green (its best-effort tidy-up could not get
back from Mollie's classic page, which keeps a shopper there after a failed payment — documented in the
spec).

## Gates

payment-base (after the last change): Unit 1367 tests green (4 skipped, as before); Integration:
`DoctrineContractRepositoryTest` 22 (1 skipped), `DoctrineNotFinishedOrderRepositoryTest` +
`OxidShopOrderServiceSecondSubmissionTest` 14, shipping-address tests 2; PHPCS clean; PHPMD exit 0;
PHPStan: the same 20 findings as on `b-7.4.x` HEAD (dynamic `oxorder__*` property access in the copier,
the order service and the order controller when analysed with the shop's vendor) — none new.

mollie-payment: Unit 649 tests green (6 PHPUnit deprecations, identical before the change); PHPCS
clean; PHPStan "No errors"; PHPMD exit 0 after the controller refactor (see deviations).
`mollie-standard` regression: see `../status.md`.

## Deviations from the plan (and why)

- **The provider redirect URL was never persisted.** `DoctrineContractRepository` wrote
  `OXPROVIDERDATA` from a `providerData` key `PaymentContract::toArray()` never produced, and hydrated
  nothing back — every reloaded contract answered `null` to `getProviderRedirectUrl()`. The resolver
  had nothing to replay (Story 4's first green run still made two orders). Fixed in payment-base
  (`{"redirectUrl": ...}` in `OXPROVIDERDATA`, round-trip integration test). Not in the plan.
- **`disableContinueButton()` is not dead code.** The plan's finding 1 said the selector never matches;
  apex's *payment*-step "Next" button is `onclick="...requestSubmit()"` inside `.col-lg-5`, so it does
  match there. Left untouched.
- **PHPMD forced a shape change in `MollieOrderController`.** Adding `inFlightCheckoutUrl()` +
  `liveBasketTotal()` pushed the class to 26 methods / complexity 55 (limits 25 / 50). The replay
  lookup and basket reading live in `Service\InFlightCheckoutReplay` (resolver + Mollie's session
  adapter, wired in `services.yaml`); the controller calls it in one line, `startCheckoutSession()` is
  extracted, and two `?? false` / `&&` branches became casts. Complexity 49, 24 methods.
- **The e2e double click is two `page.request.post()` calls, not two UI clicks.** Two same-tick
  `requestSubmit()` calls collapse into one navigation per the HTML spec, so a synthetic dblclick never
  reaches the server twice. The two sequential POSTs of the order form (same browser context, same
  session cookie) are exactly what PHP sees. The button-lock test clicks in-page via `page.evaluate`
  and counts order POSTs across delayed extra clicks.
- **Orders are counted in the DB** (`fixtures/shop-db.ts`, `docker compose exec mysql`), not in the
  admin list: storno'd and payment-less rows render there like real orders.
- **Mollie CI pinned** to payment-base `b-7.4.x-MOL-18-single-order-per-checkout-attempt`
  (`PAYMENT_BASE_BRANCH` in both workflow files). Set back to `b-7.4.x` once payment-base merges.
- **Cleaner rotates the challenge only when it names the retired order** — a newer challenge belongs
  to whatever attempt the shopper is on now (not spelled out in the plan).

## Environment notes

- OPC's `oeOnePageCheckoutEnabled` was already `false`; no toggle needed for `MollieStandard/*`.
- The e2e user `playwright.user@oxid-esales.dev` had an edited address that Mollie's
  `UserDataValidator` rejected at the payment step (`MOLLIE_VALIDATION_INVALID_USER_DATA`): first name
  `Marc3`, street `Hugo-Junkers Str23434212341`, city `Frankfrurt am Mein`. Reset to `Marc` /
  `Hugo-Junkers Str` / `Frankfurt am Main` (the demo-data values sprint 01 ran with); original row
  backed up outside the repo.
- Mollie's classic method page labels tiles "<icon alt> <name>" ("PayPal PayPal"); the shared helper
  now matches that too.
- `oe:cache:clear` after every `services.yaml` / twig change; module assets are symlinked into
  `source/out/modules`, so a rebuilt bundle is live immediately (cache-buster = bundle mtime).

## Out of scope, still open

OPC server-side parity (its footer blocks repeat clicks client-side); cleaning up the 55 phantom rows
(merchant decision); Stripe / PayPal controllers asking the resolver; the theme's own button.
