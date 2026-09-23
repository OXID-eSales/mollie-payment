# Sprint plan: Admin "Addresses" tab shows a shipping address when billing is used for shipping

**Definition of Done (sprint-level):** a Mollie order placed with "use billing address for
shipping" shows the billing address in the admin Addresses → Shipping Address section; an order
with a separately selected delivery address is untouched; payment-base and Mollie gates green. ✅ DONE

## Root cause

Core's `Order::setUser()` (`OxidEsales\Eshop\Application\Model\Order`) writes the `OXDEL*`
columns only when `getDelAddressInfo()` finds a selected `oxaddress` row (`deladrid` in
request/session). A shopper who ships to their billing address never selects one, so core leaves
`OXDEL*` empty for **every** payment method — confirmed in the local DB for `oxidinvoice`,
`oxidcashondel`, Mollie and Stripe orders alike. Not a Mollie regression.

## Decision

Product owner, 2026-09-23: fix in **payment-base**, not a Mollie `OrderCreatedEvent` handler.
`payment-base`'s `OxidShopOrderService` is the single `ShopOrderServiceInterface` implementation
finalizing orders for Mollie, Stripe and PayPal (Mollie's own `Adapter/OxidShopOrderService` is
dead code, unbound since 2026-09-01), so fixing the seam once covers every provider instead of
duplicating an event handler per module.

## What changed (payment-base)

- New `src/Adapter/OrderShippingAddressCopier.php` — pure field-mapping class,
  `copyBillingWhenShippingEmpty(Order): bool`, copies the 13 billing→shipping column pairs when
  the shipping side is empty, never saves. No interface (one implementation, one caller).
- `OxidShopOrderService::setOrderFieldsAfterCreation()` calls the copier immediately before its
  existing `$order->save()`; the copier is now the 2nd constructor argument.
- `services.yaml`: explicit `$shippingAddressCopier` argument on the `ShopOrderServiceInterface`
  definition.
- `CHANGELOG.md`: `[Unreleased] / Fixed` entry, including the visible side effect (order
  confirmation e-mails and the thank-you page now print a shipping block equal to billing
  wherever the core template keys on `oxdellname`).

No Mollie module file changed — the fix lives entirely in payment-base and Mollie inherits it for
free through the shared order service.

## How it was proven

- **Unit** (payment-base): 7 tests for the copier (`OrderShippingAddressCopierTest.php`) plus the
  3 existing `OxidShopOrderServiceTest.php` construction sites updated for the new constructor
  argument.
- **Integration** (payment-base): new `OxidShopOrderServiceShippingAddressTest.php`, 2 tests
  against the real shop DB — a bill-to-ship order persists all 13 `OXDEL*` columns equal to
  `OXBILL*` (confirmed red before the fix, green after); a selected delivery address is left
  untouched (was already green — the regression guard).
- **E2E** (Mollie, this module): Story 1's red repro,
  `tests/e2e/playwright/tests/MollieStandard/OrderShippingAddressFromBilling.spec.ts`, now green.
  Full `mollie-standard` project re-run for regression: 11 passed, 2 pre-existing skips
  (Klarna specs, environment-gated, unrelated), 0 failures.
- **DB spot-check**: orders 610-612 (`oe_payments_mollie`) all carry `OXDELLNAME`/`OXDELSTREET`
  equal to their billing twins, vs. order 605 (Story 1, pre-fix) with empty `OXDELLNAME`.

## Gates

payment-base: `composer phpcs` ✓ · `composer phpstan` (full `src/`, level max) ✓ no new baseline
entries · `composer phpmd` (full `src/`) ✓ baseline unchanged · unit suite ✓ (1345 tests, 0
failures) · integration suite ✓ (125 tests, 1 pre-existing skip) · `./bin/pre-commit-check.sh
--full` ✓ (its single-file PHPStan step has a pre-existing, documented config gap unrelated to
this change — verified manually via `composer phpstan` instead).

## Out of scope / not done
- Core-only payment methods (invoice, cash on delivery) — never enter payment-base; an OXID core
  ticket if parity is wanted there.
- Backfilling `OXDEL*` on already-existing orders.
- Deleting Mollie's unbound `Adapter/OxidShopOrderService` (separate cleanup ticket).
- Nothing committed or pushed in either repo — left for the user to review and commit.
