# Story 1 — Red e2e repro: admin Addresses tab shows no shipping address

**Date:** 2026-09-23
**Branch:** `b-7.4.x-order-shipping-address-from-billing`
**Status:** DONE (red repro landed, as intended for this story)

## Command run

```bash
cd source/extensions/mollie-payment/tests/e2e/playwright
npx playwright test --project=mollie-standard OrderShippingAddressFromBilling
```

Against `SHOP_URL=https://daniil.oxiddev.de` (this repo's `.env`, tunnelled to the local
`strpwt7-nov26` docker stack), Mollie test-mode key already configured in the shop
(`sMollieTestKey` in `var/configuration/shops/1/modules/oe_payments_mollie.yaml`, sandbox
key `test_G2HCRKHM7Qw3uJsng9zdP97fWT3C69`), admin login `noreply@oxid-esales.com` / `admin`
(README's `ADMIN_USER_EMAIL` / `ADMIN_USER_PASSWORD`, already set in `.env`).

## Red assertion output (final run)

```
1) [mollie-standard] › tests/MollieStandard/OrderShippingAddressFromBilling.spec.ts:41:9 ›
   Mollie order — shipping address copied from billing › customer with "use billing for
   shipping" pays with Mollie -> admin Addresses tab shows shipping address equal to billing

    Error: expect(received).toBe(expected) // Object.is equality

    Expected: "Muster"
    Received: ""

      101 |         // billingLastName is the customer's real last name. Expected (after Story 4):
      102 |         // shippingLastName equals billingLastName.
    > 103 |         expect(shippingLastName).toBe(billingLastName);
          |                                  ^
```

Runtime: 36.1s. This is the **only** failure — the checkout (login → basket → Mollie
redirect → sandbox pay → thank-you), the admin login, and the admin order lookup + tab
switch all passed.

Confirmed independently at the DB level (same order):

```sql
SELECT OXORDERNR, OXBILLLNAME, OXDELLNAME, OXPAYMENTTYPE FROM oxorder ORDER BY OXORDERDATE DESC LIMIT 1;
-- 605 | Muster | (empty) | oe_payments_mollie
```

## Order number used

**605** (customer `playwright.user@oxid-esales.dev`, "Marc Muster", billing address from
demodata — `Hugo-Junkers Str 3, Frankfurt am Main`; no `deladrid` selected, i.e. "use
billing address for shipping" — the OXID default whenever `blshowshipaddress` is unset).

## Deliverables

- `tests/e2e/playwright/tests/MollieStandard/OrderShippingAddressFromBilling.spec.ts` — new
  spec, red as designed.
- `tests/e2e/playwright/tests/pages/admin/AdminMollieOrderPage.ts` — added
  `openAddressesTab()`, `billingLastName()`, `shippingLastName()` (the order-address fields
  were not previously exposed by this page object).
- `tests/e2e/playwright/tests/pages/admin/AdminOrdersPage.ts` — `navigateToOrders()` fixed
  (see "Environment/infra findings" below); this was broken for this shop's admin theme
  before Story 1, for reasons unrelated to the ticket.

## Environment / infra findings (not ticket-related, recorded so Story 2-4's executor and
whoever re-runs this suite doesn't re-discover them)

1. **This shop was previously unreachable.** `strpwt7-nov26-php-1` / `-apache-1` containers
   were `Exited`; the Cloudflare tunnel (`cf-tunnel-template-cloudflared-1`) was up but
   proxying to a dead origin (502). Fixed with `docker compose up -d` at the SDK root
   (`/home/dtkachev/osc/strpwt7-nov26`). No code change — just the containers were stopped.

2. **OPC blocked the classic checkout the MollieStandard suite needs.** The
   `one-page-checkout` module is active on this shop, and (per
   `OxidEsales\OnePageCheckout\Core\ViewConfig::shouldReplaceMinibasket()`) minibasket
   replacement is *hard-wired on* whenever the module itself is enabled — there is no
   separate toggle any more. Its `UserController::render()` override therefore redirects
   every `?cl=user` request to the shop start page, which is exactly what happened: both
   this new spec and the pre-existing (never-before-run) `CheckoutPaysAndFinalizes.spec.ts`
   timed out identically waiting for the "Continue" button on a page that was actually the
   homepage.
   **Action taken:** flipped `oeOnePageCheckoutEnabled` to `false` in
   `var/configuration/shops/1/modules/oe_onepage_checkout.yaml`, ran `bin/oe-console
   oe:cache:clear`, ran the spec, then **flipped it back to `true`** and cleared the cache
   again — diffed identical to the pre-change backup afterwards. This is a shop
   *configuration* setting, not module code; no file under `src/`, `services.yaml`, or
   `CHANGELOG.md` in any module was touched.
   **Consequence for later stories:** anyone re-running `MollieStandard/*` specs against
   this shop needs OPC's `oeOnePageCheckoutEnabled` off for the duration of the run (and
   back on afterwards for the `MollieOpc/*` suite, which needs it on). This is a suite-level
   environment precondition, not something to fix in this sprint.

3. **`AdminOrdersPage.navigateToOrders()` didn't work against this shop's admin theme.**
   This shop runs the current twig-admin-theme (OXID 7.4 Enterprise) with an accordion
   sidebar, and the admin UI language flips between English and German across sessions.
   Two things were wrong for this environment, both fixed in place (test infra, not
   production code):
   - The old selector only recognised the English category label `"Administer Orders"`.
     This theme's sidebar top-level entry actually shows the *submenu's* label
     (`mxdisplayorders`: "Orders" / "Bestellungen"), not the category's
     (`mxorders`: "Administer Orders" / "Bestellungen verwalten").
   - The accordion needs one click to expand (revealing the identically-labelled submenu
     link) and a second click on that submenu link to actually navigate — except when the
     sidebar was already expanded from a prior session, in which case the first click
     navigates directly. `navigateToOrders()` now handles both cases and falls back to the
     original two-frame (`administerOrdersLink` / `ordersLink`) logic for older admin
     skins.
   - `AdminMollieOrderPage.openAddressesTab()` / `billingLastName()` / `shippingLastName()`
     needed no such fix — the existing bilingual tab-bar pattern (`table.tabs a, .tabs a,
     [id^="tbcl"]` filtered by `/^(Addresses|Adressen)$/`) worked first try, and the
     `editval[oxorder__oxbilllname]` / `editval[oxorder__oxdellname]` field names matched
     `order_address.html.twig` exactly.

4. **README's "never executed" caveat was accurate and has now changed.** Before this
   story, no e2e spec in this suite had ever actually run against a live shop; the fixture
   helpers and admin page objects had never been exercised for real. This story is the
   first confirmed real execution — both of the new spec and (as a side effect of
   debugging) of the pre-existing happy-path spec. `CheckoutPaysAndFinalizes.spec.ts` was
   not otherwise touched.

## Not done in this story (per scope)

No production code was touched (`src/`, `services.yaml`, `CHANGELOG.md` in the Mollie
module are unchanged). Stories 2-4 implement `OrderShippingAddressCopier`, the
`MollieOrderShippingAddressHandler` on `OrderCreatedEvent`, and the integration/e2e-green
pass that turns this spec green.

**Revised 2026-09-23:** the product owner decided the fix lives in **payment-base**, not a
Mollie `OrderCreatedEvent` handler — see the sprint doc's decision log. Stories 2-4 below
describe what actually landed.

---

## Fix and proof (Stories 2-4, 2026-09-23)

### Seam

Every payment-base provider's early order passes through
`OxidShopOrderService::setOrderFieldsAfterCreation()` right before its one `$order->save()`.
Story 2 added a pure field-mapping class, `payment-base`'s
`src/Adapter/OrderShippingAddressCopier.php`
(`copyBillingWhenShippingEmpty(Order $order): bool`), mapping the 13 `oxbill*` → `oxdel*`
column pairs (`company fname lname street streetnr addinfo city countryid stateid zip fon
fax sal`) whenever `oxdellname`/`oxdelfname` are both empty; it never saves. Story 3 wired it
into `OxidShopOrderService`'s constructor (`$shippingAddressCopier`, 2nd argument) and called
it immediately before the existing `save()`; `services.yaml`'s `ShopOrderServiceInterface`
definition got the explicit argument. No Mollie file changed — the fix is entirely in
payment-base and applies to every provider using its order service (Mollie, Stripe, PayPal).

### Before / after e2e

Before (Story 1, order 605, 2026-09-23):
```
Expected: "Muster"
Received: ""
```

After (Story 4, same spec, same shop):
```
✓ [mollie-standard] › OrderShippingAddressFromBilling.spec.ts:41:9 › Mollie order — shipping
  address copied from billing › customer with "use billing for shipping" pays with Mollie ->
  admin Addresses tab shows shipping address equal to billing (33.6s)
```

Full `mollie-standard` regression run (same environment prep: OPC's `oeOnePageCheckoutEnabled`
off for the run, cache cleared, restored to `true` and cache cleared again afterwards — diffed
identical to the pre-change backup):

```
11 passed (4.4m)
2 skipped (KlarnaOrderData.spec.ts, KlarnaEndToEnd.spec.ts — pre-existing environment skip,
           unrelated to this change; no failures)
```

No new failures relative to what was runnable before this change.

### DB evidence

```
SELECT OXORDERNR, OXPAYMENTTYPE, OXBILLLNAME, OXDELLNAME, OXDELSTREET FROM oxorder
ORDER BY OXORDERDATE DESC LIMIT 3;

OXORDERNR  OXPAYMENTTYPE       OXBILLLNAME  OXDELLNAME  OXDELSTREET
612        oe_payments_mollie  Muster       Muster      Hugo-Junkers Str
611        oe_payments_mollie  Muster       Muster      Hugo-Junkers Str
610        oe_payments_mollie  Muster       Muster      Hugo-Junkers Str
```

Compare to Story 1's order 605 (`OXDELLNAME` empty) — every order placed after the fix carries
the billing address in `OXDEL*`.

### Gates (payment-base)

`composer phpcs` ✓ · `composer phpstan` (full `src/`) ✓ no new baseline entries ·
`composer phpmd` (full `src/`) ✓ baseline unchanged · unit suite ✓ (1345 tests, 0 failures) ·
integration suite ✓ (125 tests, 1 pre-existing skip, including the 2 new
`OxidShopOrderServiceShippingAddressTest` tests — confirmed red before the fix, green after) ·
`./bin/pre-commit-check.sh --full` (run from the SDK root) ✓ except a pre-existing, unrelated
gap in the script's single-file PHPStan mode (documented in payment-base's sprint doc).
