# E2E Walkthrough Report: Mollie Checkout + Admin Refund Flow

**Date:** 2026-07-13  
**Environment:** https://daniil.oxiddev.de (Docker tunnel)  
**Mollie API Mode:** Test mode

---

## ✅ Test Results Summary

| Test | Status | Notes |
|------|--------|-------|
| Checkout (EN) | ✅ **PASSED** | Order #218 created successfully |
| Checkout (DE) | ✅ **PASSED** | Order #219 created successfully |
| Admin: Login | ✅ **PASSED** | Credentials: noreply@oxid-esales.com / admin |
| Admin: Find Mollie order | ✅ **PASSED** | Order for Збигнев Бжезинский |
| Admin: Verify Mollie panel | ✅ **PASSED** | Panel visible with contract ID |
| Admin: View payment details | ✅ **PASSED** | Contract ID: 8d30b83..., Dashboard Link: ✓ |
| Admin: Execute refund | ⚠️ **PARTIAL** | Dialog accepted, but amount doesn't update |

---

## 🎉 Key Achievement: Mollie Checkout Works End-to-End!

```
STEP 1: Loading shop (English (lang=1))...
✓ Shop loaded
STEP 2: Logging in...
  Attempting login with email: playwright.user@oxid-esales.dev
✓ Logged in
STEP 3: Adding product to cart via /en/Spare-parts/Axle-parts/Wishbone-aluminum.html...
✓ Added to cart
STEP 4: Entering checkout...
✓ Reached order review step (lang=1)
STEP 5: Clicking "Order now" (triggers Mollie redirect)...
✓ Clicked "Order now"
STEP 6: Completing Mollie payment...
✓ Mollie payment completed
✓ Redirected back to shop
STEP 7: Verifying order confirmation...
Final URL: https://daniil.oxiddev.de/index.php?cl=thankyou&lang=1&...
✓ Order confirmed: #218
```

**Order Number Extraction:**
- Pattern: `/We registered your order with number\s*(\d+)/i`
- Works in both English and German

---

## 🧪 Playwright Tests Created

### Checkout Test (`tests/checkout/mollie-checkout.spec.ts`)
- Runs in both English (lang=1) and German (lang=0)
- Full flow: login → add product → checkout → select Mollie → complete payment
- Uses Mollie test mode with PayPal payment method
- Extracts order number from thankyou page

### Admin Refund Test (`tests/admin/mollie-admin-refund.spec.ts`)
- Complete E2E flow in single test
- Navigates to Mollie order in admin
- Opens Payment tab
- Verifies Mollie panel is visible
- Gets payment details (Contract ID, Transaction ID, Dashboard Link)
- Attempts to execute partial refund

---

## 🔧 Test Infrastructure

### Page Objects
- `LoginPage.ts` - Frontend login with `#loginUser` / `#loginPwd` selectors
- `ProductPage.ts` - Product navigation and add to cart
- `MollieCheckoutPage.ts` - Mollie hosted checkout interaction
- `AdminLoginPage.ts` - Admin login with staging mode handling
- `AdminOrdersPage.ts` - Admin order navigation
- `AdminMollieOrderPage.ts` - Mollie payment panel interaction

### Configuration
```env
SHOP_URL=https://daniil.oxiddev.de
MOLLIE_E2E_SHOP_URL=https://daniil.oxiddev.de
TEST_USER_EMAIL=playwright.user@oxid-esales.dev
TEST_USER_PASSWORD=useruser
```

### Test Execution
```bash
cd tests/e2e/playwright

# Run checkout tests
npx playwright test --headed --project=mollie-checkout

# Run admin refund tests
npx playwright test --headed --project=mollie-admin-refund

# Run all Mollie tests
npx playwright test --headed --project=mollie-all
```

---

## ⚠️ Known Issue: Refund Form Submission

The refund form submission appears to not work correctly via Playwright:

1. **What works:**
   - Dialog is caught and accepted: `Dialog: confirm - Refund the captured amount via Mollie?`
   - Amount is set correctly in input: `Set refund amount: 131 (input shows: 131)`
   - Form submit button is clicked successfully

2. **What doesn't work:**
   - The refundable amount doesn't decrease after "refund"
   - Remaining amount stays at 262 instead of decreasing to 131

3. **Possible causes:**
   - Form uses AJAX (not full page reload)
   - CSRF token validation
   - Frame navigation issues
   - The Mollie API key might not be configured for test mode refunds

4. **Next steps to investigate:**
   - Check if Mollie test API key is configured
   - Verify the RefundService is receiving the correct request
   - Check Mollie dashboard for refund attempts
   - Add more debugging to the test

---

## 📋 Test Files Created

```
tests/e2e/playwright/
├── playwright.config.ts
├── .env
├── tests/
│   ├── checkout/
│   │   └── mollie-checkout.spec.ts
│   ├── admin/
│   │   └── mollie-admin-refund.spec.ts
│   └── pages/
│       ├── frontend/
│       │   ├── BasePage.ts
│       │   ├── LoginPage.ts
│       │   ├── ProductPage.ts
│       │   ├── CheckoutPage.ts
│       │   └── MollieCheckoutPage.ts
│       └── admin/
│           ├── AdminBasePage.ts
│           ├── AdminLoginPage.ts
│           ├── AdminOrdersPage.ts
│           └── AdminMollieOrderPage.ts
├── fixtures/
│   └── shop-helpers.ts
└── reports/
    ├── mollie-refund-01-payment-tab.png
    ├── mollie-refund-02-payment-details.png
    └── mollie-refund-03-after-refund.png
```

---

## ✅ Completed

- [x] Checkout flow works end-to-end (EN + DE)
- [x] Mollie test mode payment completion
- [x] Order number extraction from thankyou page
- [x] Admin login with staging mode
- [x] Order navigation and selection
- [x] Mollie payment panel detection
- [x] Payment details extraction
- [x] Refund form interaction
- [x] Dialog handling

## 📝 To Do

- [ ] Fix refund form submission issue
- [ ] Verify refund in Mollie dashboard
- [ ] Test partial refunds (multiple sequential)
- [ ] Test stock restoration after refund
- [ ] Add transaction history verification
- [ ] Take screenshots at each step for documentation

---

## Running Tests

```bash
# From the mollie-payment directory
cd /home/dtkachev/osc/strpwt7-nov26/source/extensions/mollie-payment/tests/e2e/playwright

# Checkout only
npx playwright test --headed --project=mollie-checkout --grep="English"

# Admin refund flow
npx playwright test --headed --project=mollie-admin-refund

# All tests
npx playwright test --headed --project=mollie-all
```

---

**Conclusion:** Checkout flow is fully working ✅. Admin refund flow shows the panel correctly but the refund form submission needs debugging 🔧.