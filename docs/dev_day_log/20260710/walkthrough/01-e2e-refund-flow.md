# E2E Walkthrough Report: Mollie Partial Refund Flow

**Date:** 2026-07-10  
**Environment:** https://daniil.oxiddev.de (Docker tunnel)  
**Mollie API Mode:** Test mode (working)

---

## ✅ Test Results Summary

| Test | Status | Notes |
|------|--------|-------|
| Checkout: Create Mollie order | ✅ **PASSED** | Order 214 created successfully |
| Admin: View order and verify Mollie panel | ❌ Blocked | Mollie panel selectors need adjustment |
| Admin: Execute partial refund | ⏳ Skipped | Depends on previous test |

---

## 🎉 Key Achievement: Mollie Checkout Works End-to-End!

```
STEP 1: Login - ✓ Logged in
STEP 2: Add product to cart - ✓ Product added to cart (Ocean Eyes)
STEP 3: Complete checkout with Mollie - ✓ Redirected to Mollie
STEP 4: Complete Mollie payment - ✓ Payment completed (PayPal test mode)
STEP 5: Verify thank you page - ✓ On thank you page (Order 214)
```

**Root Cause Found & Fixed:**
The original error `MOLLIE_CHECKOUT_UNAVAILABLE` was caused by a **stale container cache** (`container_cache_shop_1.php`) that had the old 3-parameter `RefundService` constructor signature instead of the new 5-parameter signature (with `StockRestorationServiceInterface`).

**Solution:** Cleared the OXID cache with `bin/oe-console oe:cache:clear`

---

## Test Files Created

### Page Objects (following Stripe patterns)
- `tests/pages/frontend/BasePage.ts`
- `tests/pages/frontend/LoginPage.ts` - Selectors: `#loginUser`, `#loginPwd`, `#loginButton`
- `tests/pages/frontend/ProductPage.ts` - Selector: `#toBasket`
- `tests/pages/frontend/CheckoutPage.ts`
- `tests/pages/admin/AdminBasePage.ts`
- `tests/pages/admin/AdminLoginPage.ts` - Admin URL: `/admin/index.php`
- `tests/pages/admin/AdminMollieOrderPage.ts`

### Test Files
- `tests/mollie-checkout.spec.ts` - Full E2E flow
- `tests/admin-access.spec.ts` - Admin access verification

### Config
- `playwright.config.ts` - Projects: `mollie-standard`, `mollie-admin`, `mollie-checkout`, `admin-access`

---

## Key Fixes Made

1. **Cache cleared:** `docker compose exec php bin/oe-console oe:cache:clear`
2. **Admin URL fixed:** Use `/admin/index.php` instead of `/admin` (Playwright DNS resolution)
3. **Order number regex:** Match "We registered your order with number 209"

---

## Next Steps for Story 5 Completion

1. **Fix Mollie panel detection** in admin order view:
   - The Mollie payment tab/panel may have different selectors
   - Need to identify the correct selectors for the Mollie payment section in admin

2. **Execute partial refund test:**
   - Once panel is visible, test partial refund functionality
   - Verify stock restoration (Story 1)
   - Verify refund description field (Story 3)

3. **Multi-partial-refund sequence:**
   - Refund 10% → 20% → remaining
   - Verify amounts match Mollie dashboard

---

## Test Execution

```bash
cd ~/osc/strpwt7-nov26/source/extensions/mollie-payment/tests/e2e/playwright

# Run full E2E test suite
MOLLIE_E2E_SHOP_URL=https://daniil.oxiddev.de \
npx playwright test tests/mollie-checkout.spec.ts --project=mollie-checkout

# Run checkout only (for quick verification)
MOLLIE_E2E_SHOP_URL=https://daniil.oxiddev.de \
npx playwright test tests/mollie-checkout.spec.ts --project=mollie-checkout --grep="Checkout"

# Run admin test only
MOLLIE_E2E_SHOP_URL=https://daniil.oxiddev.de \
npx playwright test tests/admin-access.spec.ts --project=admin-access
```

---

## Conclusion

**Sprint 9 Stories 1-3:** ✅ COMPLETE
- Stock restoration on refund
- View cache reset after actions
- Refund description field

**Story 5 (E2E Walkthrough):** ⏳ 60% Complete
- ✅ Checkout flow works end-to-end
- ✅ Mollie payment successful
- ✅ Order created (Order 214)
- ✅ Admin login works
- ❌ Mollie panel detection in admin (needs selector adjustment)
- ⏳ Refund tests pending