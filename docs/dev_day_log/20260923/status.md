# 2026-09-23

- Sprint 01 (admin Addresses tab: shipping address empty when "use billing address for shipping" is enabled) — **DONE** (all 4 stories landed; **not committed, not pushed** in either repo). See `sprints/01-order-shipping-address-from-billing.md`, `done/01-order-shipping-address-from-billing.md`.
  - Finding: core OXID never fills `OXDEL*` without a selected `oxaddress` row — every payment type in the local DB (incl. `oxidinvoice`) has empty shipping columns. Not a Mollie regression.
  - Seam: Mollie's `Adapter/OxidShopOrderService` is unbound dead code since 2026-09-01; payment-base's `OxidShopOrderService` finalizes every provider's orders.
  - Branch `b-7.4.x-order-shipping-address-from-billing` created off `b-7.4.x`.
  - Decision (product owner, 2026-09-23): fix lives in **payment-base** `OxidShopOrderService::setOrderFieldsAfterCreation()` so Mollie/Stripe/PayPal behave the same. Stories 2–4 rewritten; payment-base branch of the same name created; twin plan `payment-base/docs/dev_log/20260923/sprints/sprint-10-order-shipping-address-from-billing.md`.
  - Story 1 — red e2e repro landed: `tests/e2e/playwright/tests/MollieStandard/OrderShippingAddressFromBilling.spec.ts`, fails on shipping last name '' vs 'Muster' (order 605). See `reports/01-order-shipping-address-from-billing.md`.
  - Env notes from Story 1: `MollieStandard/*` e2e needs OPC's `oeOnePageCheckoutEnabled` off for the run (OPC redirects `cl=user` to home); flag restored afterwards. `AdminOrdersPage.navigateToOrders()` repaired for the twig admin theme (pre-existing breakage).
  - Story 2 — `OrderShippingAddressCopier` landed in payment-base (`src/Adapter/OrderShippingAddressCopier.php`), 7 unit tests, gates green (phpcs, phpstan max/no new baseline, phpmd/baseline unchanged, 1345 unit tests green).
  - Story 3 — copier wired into payment-base's `OxidShopOrderService::setOrderFieldsAfterCreation()` (2nd
    constructor arg, called right before the existing `save()`); `services.yaml` updated. New
    `tests/Integration/Adapter/OxidShopOrderServiceShippingAddressTest.php` (2 tests, real `createOrder()`
    against the live shop DB): bill-to-ship persists all 13 `OXDEL*` columns equal to `OXBILL*` (confirmed
    red before the fix, green after); a selected delivery address is untouched (regression guard, was
    already green). Gates green: phpcs, phpstan (full `src/`, no new baseline), phpmd (baseline unchanged),
    1345 unit + 125 integration tests. See payment-base's sprint doc for the full gate detail.
  - Story 4 — proven in the shop. OPC's `oeOnePageCheckoutEnabled` flipped off for the run (restored to
    `true` afterwards, diff identical to backup), cache cleared. `OrderShippingAddressFromBilling.spec.ts`
    → GREEN (was red on order 605 in Story 1; new orders 610-612 all show billing = shipping). Full
    `--project=mollie-standard` regression: 11 passed, 2 pre-existing skips (Klarna specs, env-gated,
    unrelated), 0 failures. DB check: orders 610/611/612 (`oe_payments_mollie`) carry
    `OXDELLNAME`/`OXDELSTREET` equal to billing. payment-base `CHANGELOG.md` `[Unreleased]/Fixed` entry
    added (incl. the order-mail/thank-you-page side effect). Full write-up:
    `done/01-order-shipping-address-from-billing.md`, `reports/01-order-shipping-address-from-billing.md`
    "Fix and proof" section. **Nothing committed or pushed in either repo.**
