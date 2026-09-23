# Sprint 01: Admin "Addresses" tab shows a shipping address when billing is used for shipping

**Date:** 2026-09-23
**Branches:** `b-7.4.x-order-shipping-address-from-billing` (off `b-7.4.x`) in **payment-base** (the fix)
and in **mollie-payment** (the e2e repro + docs)
**Status:** IN PROGRESS — Story 1 running. **Revised 2026-09-23:** the product owner decided the copy lives in
**payment-base** so invoice / Stripe / PayPal orders behave the same. Stories 2–4 below are the revised,
payment-base versions; the Mollie `OrderCreatedEvent` handler from the first draft is dropped.
payment-base twin of this file: `../../../../payment-base/docs/dev_log/20260923/sprints/sprint-10-order-shipping-address-from-billing.md`.

## Problem (ticket)

Customer has a valid billing address, enables *Use billing address for shipping*, pays with
Mollie, order is created. In admin → Orders → *Addresses* the Billing Address section is filled,
the Shipping Address section is empty.

**Expected:** the Shipping Address section shows the address actually used for shipping, i.e. a
copy of the billing address.

## What the investigation found (read before executing)

1. **This is core OXID behaviour, not a Mollie regression.** `Order::setUser()` fills the
   `OXDEL*` columns only when `getDelAddressInfo()` returns a selected `oxaddress` row
   (`deladrid` in request/session). With "use billing for shipping" there is no `deladrid`, so
   core leaves `OXDEL*` empty for *every* payment method. Local DB confirms it:

   | OXPAYMENTTYPE | orders | with `OXDELLNAME` |
   |---|---|---|
   | oxidinvoice (core) | 27 | 0 |
   | oxidcashondel (core) | 5 | 0 |
   | oe_payments_mollie | 245 | 0 |
   | oe_payments_stripe_wallet | 250 | 0 |
   | oxidpayadvance (core) | 16 | 1 (a real separate delivery address) |

2. **Mollie does not finalize its own orders anymore.** `services.yaml` (2026-09-01 note):
   payment-base's `OxidShopOrderService` is the single `ShopOrderServiceInterface`; Mollie's
   `Adapter/OxidShopOrderService` is registered but unbound (dead code). So the fix cannot go into
   the Mollie adapter's `setOrderFieldsAfterCreation()`.

3. **The seam is payment-base's `OxidShopOrderService::setOrderFieldsAfterCreation()`** — the one
   place every PSP's early order passes through right after `finalizeOrder()`, and it already
   performs the single `$order->save()` for the post-creation fields. The copy slots in before that
   save: no second write, no event, no provider guard.

4. **Decision taken (product owner, 2026-09-23): fix in payment-base.** Every payment-base provider
   (Mollie, Stripe, PayPal) gets the shipping address; core-only methods (invoice, cash on delivery)
   do not go through payment-base and stay as core leaves them. That remaining gap is a core/OXID
   ticket, noted under *Out of scope*.

## Approach

Copy billing → `OXDEL*` on the early order **only when no shipping address was written by core**,
inside payment-base's `OxidShopOrderService::setOrderFieldsAfterCreation()`, before its existing
`save()`. The field mapping is a pure class (`OrderShippingAddressCopier`) with no OXID bootstrap
dependency, injected into the order service.

**Definition of Done (sprint-level):** a Mollie order placed with "use billing address for
shipping" shows the billing address in the admin Addresses → Shipping Address section (Story 1 spec
green); an order with a separately selected delivery address is untouched; payment-base and Mollie
gates green.

| Principle | Application |
|---|---|
| TDD-first | Red e2e repro (Story 1) and red unit tests (Stories 2, 3) precede every production edit |
| SRP | `OrderShippingAddressCopier` = field mapping only; `OxidShopOrderService` keeps owning persistence (one `save()`) |
| DIP | `OxidShopOrderService` takes the copier by constructor (autowired); copier depends on the `Order` model only |
| ISP | No new interface. One implementation exists, one caller — CLAUDE.md "one impl before an interface" |
| DRY | One copy for all PSPs instead of one per module; reuses the existing post-creation `save()`. Mollie's dead `Adapter/OxidShopOrderService` is **not** touched |
| Clean Code | No `else`; methods ≤ 25 lines; explicit `use`; booleans `is*/has*` |
| No overengineering | No config switch, no interface for the copier, no event, no address-diff logic, no touching `OXBILL*` |
| DevOps-first | payment-base `./bin/pre-commit-check.sh` green before each commit; payment-base CHANGELOG entry; Mollie e2e green |

## Out of scope
- Core-only payment methods (invoice, cash on delivery, …) — they never enter payment-base and keep
  core's empty shipping columns. If parity is wanted there too it is an OXID core ticket.
- Changing what the **customer order e-mail** / thank-you page render. Core templates print the
  shipping block whenever `oxdellname` is set, so Mollie order mails will now show a shipping
  address block equal to billing. Called out as a visible side effect, not worked around.
- Deleting Mollie's unbound `Adapter/OxidShopOrderService` (separate cleanup ticket).
- Backfilling `OXDEL*` on already-existing orders.
- The OPC (one-page checkout) flow and Stripe/PayPal get the fix for free (same service); no separate stories.

## Risks & unknowns
- **Blast radius is every PSP** (Mollie, Stripe, PayPal share this service). Deliberate, per the
  decision — but it means Story 4 must run Stripe's and Mollie's e2e happy paths, not just the new spec.
- **Constructor change on `OxidShopOrderService`** touches the three existing `new OxidShopOrderService(...)`
  call sites in payment-base's unit tests. Autowiring covers the container; the tests pass the copier explicitly.
- **Order already has a shipping address** (customer selected a separate delivery address):
  overwriting it would be a data-loss bug worse than the one fixed. De-risk: copier guard
  `hasShippingAddress()` (`oxdellname` OR `oxdelfname` non-empty → skip), unit-tested both ways.
- **`OXBILLEMAIL`, `OXBILLUSTID`, `OXBILLNR`, `OXBILLDATE` have no `OXDEL*` twin** — they are
  deliberately not in the mapping; the test asserts the exact 13-field set so nobody "completes" it.
- **e2e needs a Mollie sandbox key + admin login** like every `MollieStandard` spec; if the shop
  under test has no working key the e2e story stays red for environmental reasons — record that,
  don't fake it green.

---

## Story 1 — Prove the defect end to end (red e2e repro)

**Why:** Anchors the sprint to the reporter's steps; also guards the "separate delivery address
still works" premise we rely on.
**Estimate:** S

**Tests first (TDD):**
- `tests/e2e/playwright/tests/MollieStandard/OrderShippingAddressFromBilling.spec.ts`
  - `customer with "use billing for shipping" pays with Mollie → admin Addresses tab shows shipping
    address equal to billing` (RED until Story 4)
  - Reuse `loginStorefront`, `addFirstFeaturedProductToBasket`, `goToCheckoutPayment`,
    `selectMolliePaymentMethod`, `acceptTermsAndConditions`, `pickRedirectMollieMethod`,
    `completeMollieTestPayment` from `fixtures/shop-helpers`; admin side via
    `pages/admin/AdminLoginPage`, `AdminOrdersPage`, `AdminMollieOrderPage` (add an `openAddressesTab()`
    + `shippingLastName()` reader to `AdminMollieOrderPage` only if it does not already expose the tab).

**Implementation steps:**
1. Write the spec: walk the checkout, capture the order number from the thank-you page, open the
   order in admin, switch to the *Addresses* tab, assert `editval[oxorder__oxdellname]` equals the
   billing last name.
2. Run against the local shop; record the red result (empty value) in `reports/01-…`.

**SOLID/Clean check:**
- DRY: no new helper unless a third spec needs the admin Addresses tab; a second use = leave inline.

**DevOps gate:**
- `npx playwright test --project=mollie-standard OrderShippingAddressFromBilling` → RED (expected).

**Definition of Done:**
- Spec exists, fails on `b-7.4.x` for exactly the ticket's reason, and the failure is logged.

---

## Story 2 — `OrderShippingAddressCopier` (payment-base): pure billing → shipping field mapping

**Repo:** payment-base · **Why:** The mapping is the only logic in the fix; isolating it makes it
testable with a bare `Order` mock (no bootstrap), the way Mollie's `OxidContractLinkedOrderUpdaterTest`
does it. · **Estimate:** S

**Tests first (TDD):**
- `tests/Unit/Adapter/OrderShippingAddressCopierTest.php`
  - `testCopyBillingWhenShippingEmpty_WhenShippingEmpty_CopiesAllThirteenFields`
    (`company, fname, lname, street, streetnr, addinfo, city, countryid, stateid, zip, fon, fax, sal`)
  - `testCopyBillingWhenShippingEmpty_WhenShippingEmpty_ReturnsTrue`
  - `testCopyBillingWhenShippingEmpty_WhenShippingLastNamePresent_LeavesOrderUntouchedAndReturnsFalse`
  - `testCopyBillingWhenShippingEmpty_WhenOnlyShippingFirstNamePresent_LeavesOrderUntouched`
  - `testCopyBillingWhenShippingEmpty_DoesNotTouchFieldsWithoutShippingTwin` (`oxbillemail`,
    `oxbillustid` have no `oxdel*` counterpart; no `oxdel*` field appears that isn't in the map)
  - `testCopyBillingWhenShippingEmpty_WritesRawFields` (`Field::T_RAW`, like the surrounding writers)
  - `testCopyBillingWhenShippingEmpty_NeverSaves` (`save` mocked with `expects(never())` — persistence is
    the caller's job)
  - `Order` mock: `getMockBuilder(Order::class)->disableOriginalConstructor()->onlyMethods(['save'])`.

**Implementation steps:**
1. `src/Adapter/OrderShippingAddressCopier.php` — `final class`, one public method
   `copyBillingWhenShippingEmpty(Order $order): bool`, private `hasShippingAddress(Order $order): bool`,
   private const `FIELD_SUFFIXES = ['company','fname','lname','street','streetnr','addinfo','city',
   'countryid','stateid','zip','fon','fax','sal']`.
2. Loop over the suffixes:
   `$order->{"oxorder__oxdel$suffix"} = new Field((string) $order->getFieldData("oxbill$suffix"), Field::T_RAW)`.
   Explicit `use OxidEsales\Eshop\Core\Field;` (the service next door inlines the FQCN — don't copy that).
3. `services.yaml`: one line `OxidEsales\PaymentBase\Adapter\OrderShippingAddressCopier: ~` next to the
   order service (autowire is on, but a service must still be declared to be injectable).

**SOLID/Clean check:**
- SRP: maps billing columns onto their shipping twins when the shipping side is empty. Nothing else.
- DIP: depends on the OXID `Order` model only; no Registry, no DB, no logger.
- LSP: n/a (no inheritance). ISP: no interface — one impl, one caller (CLAUDE.md rule).
- DRY: new — no equivalent in payment-base, Mollie, Stripe or PayPal.
- No-else: guard returns `false` early; method ≤ 15 lines.

**DevOps gate (payment-base):**
- `composer phpcs` ✓ · `composer phpstan` ✓ (no new baseline entry) · `composer phpmd` ✓
- `composer test-unit` ✓

**Definition of Done:**
- Seven tests green; class ≤ 60 lines; no `save()` inside; declared in `services.yaml`.

---

## Story 3 — Wire the copier into `OxidShopOrderService::setOrderFieldsAfterCreation()`

**Repo:** payment-base · **Why:** This is the single post-`finalizeOrder()` seam every PSP's early
order passes through, and it already owns the one `save()`. · **Estimate:** S

**Tests first (TDD):**
- `tests/Unit/Adapter/OxidShopOrderServiceTest.php` (existing file)
  - `testConstructorAcceptsTheShippingAddressCopier` — update the three existing constructions to
    `new OxidShopOrderService($repository, new OrderShippingAddressCopier())`; the existing three tests
    stay green (they cover cancellation only).
- `tests/Integration/Adapter/OxidShopOrderServiceShippingAddressTest.php` (`@group integration`,
  `phpunit-integration.xml`)
  - `testCreateOrder_WhenUserShipsToBillingAddress_PersistsBillingIntoShippingColumns` — session basket
    + user with no `deladrid`, call `createOrder()`, reload the `oxorder` row, assert the 13 `OXDEL*`
    columns equal their `OXBILL*` twins.
  - `testCreateOrder_WhenUserSelectedADeliveryAddress_KeepsThatAddress` — set `deladrid` in session to a
    fixture `oxaddress` row; assert `OXDELLNAME` is the address row's last name, not billing's.
  - Look at `tests/Integration/Checkout/` for how the suite currently builds a session basket + user
    before adding fixture code; reuse, don't duplicate.

**Implementation steps:**
1. Constructor: add `private readonly OrderShippingAddressCopier $shippingAddressCopier` after
   `$orderRepository`; update the constructor doc-comment (it currently says order creation needs no
   collaborators — it now needs one, say why in one line).
2. `setOrderFieldsAfterCreation()`: call `$this->shippingAddressCopier->copyBillingWhenShippingEmpty($order);`
   immediately before the existing `$order->save()`. Return value ignored here — the single save happens
   regardless. Method stays ≤ 25 lines.
3. `services.yaml`: the `ShopOrderServiceInterface` definition gains
   `$shippingAddressCopier: '@OxidEsales\PaymentBase\Adapter\OrderShippingAddressCopier'` (explicit, like
   `$orderRepository`, so the wiring is readable without relying on autowire).
4. Inside the PHP container: clear `var/cache` (compiled container must learn the new argument — see
   Mollie's 2026-09-16 ops note).

**SOLID/Clean check:**
- SRP: the order service still orchestrates order creation; the *what to copy* lives in the copier.
- DIP: constructor injection of the concrete copier (one impl, no interface).
- LSP: `ShopOrderServiceInterface` contract unchanged — `createOrder()` still returns the same
  `OrderResponse`; only a side effect on the persisted row is added.
- DRY: reuses the existing post-creation `save()`; no second write.
- OCP note: this is a modification, not an extension, of `OxidShopOrderService` — accepted because the
  class already owns "fields set after creation" and a decorator for one line would be overengineering.

**DevOps gate (payment-base):**
- `composer phpcs` ✓ · `composer phpstan` ✓ · `composer phpmd` ✓
- `./bin/pre-commit-check.sh --full` ✓ (Unit + Integration) before commit

**Definition of Done:**
- Integration test proves the 13 columns are persisted for a bill-to-ship order and untouched for a
  separate delivery address; all existing payment-base tests green.

---

## Story 4 — Prove it in the shop, log it, changelog it

**Repos:** mollie-payment (e2e, dev log) + payment-base (CHANGELOG, dev log) · **Why:** Unit and
integration tests prove the pieces; the sprint's DoD is the admin screen. · **Estimate:** S

**Tests first (TDD):**
- Story 1's Mollie spec `OrderShippingAddressFromBilling.spec.ts` turns GREEN.
- Regression: `mollie-standard` project still 10/10 + 1; Stripe's e2e happy path (if runnable locally)
  still green — Stripe shares the changed service.

**Implementation steps:**
1. Clear shop cache; run `npx playwright test --project=mollie-standard` in
   `mollie-payment/tests/e2e/playwright`.
2. payment-base `CHANGELOG.md` → new `[Unreleased] / Fixed` entry stating the behaviour **and** the side
   effect (order mails now print a shipping block equal to billing).
3. payment-base `docs/dev_log/20260923/sprints/sprint-10-order-shipping-address-from-billing.md`: mark
   DONE, record gates + LOC.
4. Mollie `docs/dev_day_log/20260923/reports/01-order-shipping-address-from-billing.md`: root cause
   (core behaviour), seam choice + the payment-base decision, DB evidence table, e2e before/after.
5. Mollie `done/01-…md` + `status.md` lines for Stories 2–4.

**SOLID/Clean check:**
- DRY: no test helper extracted for a single admin-tab read (second use stays inline).

**DevOps gate:**
- payment-base `./bin/pre-commit-check.sh --full` ✓
- Playwright `mollie-standard`: new spec green, existing specs still green.

**Definition of Done:**
- Ticket steps reproduced by machine: admin Addresses tab shows the billing address as shipping for a
  Mollie order; a separate delivery address survives; Stripe/PayPal orders gain the same behaviour;
  payment-base CHANGELOG updated.

---

## Suggested order
1. Story 1 — red repro first; also confirms the admin page object can reach the Addresses tab.
2. Story 2 — the only real logic, fully unit-testable in isolation (payment-base).
3. Story 3 — wiring into the shared order service + integration proof (payment-base).
4. Story 4 — Mollie e2e green, docs, changelog (both repos).

## Decision log
- 2026-09-23 — Product owner: **yes**, the copy lives in payment-base so every PSP behaves the same.
  Draft-1 Stories 2–4 (Mollie `OrderCreatedEvent` handler + provider guard) replaced by the versions above.
