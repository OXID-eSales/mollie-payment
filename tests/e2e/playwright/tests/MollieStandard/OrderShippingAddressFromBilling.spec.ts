import { test, expect } from '@playwright/test';
import {
    loginStorefront,
    addFirstFeaturedProductToBasket,
    goToCheckoutPayment,
    selectMolliePaymentMethod,
    continueToOrderReview,
    acceptTermsAndConditions,
    pickRedirectMollieMethod,
    completeMollieTestPayment,
} from '../../fixtures/shop-helpers';
import { AdminLoginPage } from '../pages/admin/AdminLoginPage';
import { AdminOrdersPage } from '../pages/admin/AdminOrdersPage';
import { AdminMollieOrderPage } from '../pages/admin/AdminMollieOrderPage';

/**
 * Proves the ticket's defect: a logged-in customer with a valid billing address and
 * "Use billing address for shipping" enabled (the OXID default — nobody ticked
 * "Ship to a different address") pays with Mollie and places an order. In admin ->
 * Orders -> the order -> Addresses tab, the Billing Address section is filled but the
 * Shipping Address section is empty.
 *
 * Root cause (established, not re-derived here): this is core OXID behaviour —
 * `Order::setUser()` only fills `OXDEL*` when a separate `oxaddress` row was selected as
 * `deladrid`. With "use billing for shipping" there is no `deladrid`, so core leaves
 * `OXDEL*` empty for every payment method, Mollie included. The fix (Stories 2-4 of this
 * sprint) copies billing -> `OXDEL*` on the Mollie order via a Mollie-scoped
 * `OrderCreatedEvent` handler.
 *
 * Requires: a real Mollie sandbox (test-mode) API key configured on the shop under test
 * (Admin -> Extensions -> Modules -> Mollie Payment -> Settings) and admin credentials
 * (`ADMIN_USER_EMAIL` / `ADMIN_USER_PASSWORD`). See README.md.
 *
 * This is the RED e2e repro for sprint 2026-09-23/01 (Story 1). It MUST fail on the
 * shipping-last-name assertion until Story 4 wires the fix.
 */
test.describe('Mollie order — shipping address copied from billing', () => {
    const ADMIN_USER = process.env.ADMIN_USER_EMAIL || 'noreply@oxid-esales.com';
    const ADMIN_PW = process.env.ADMIN_USER_PASSWORD || 'admin';

    test('customer with "use billing for shipping" pays with Mollie -> admin Addresses tab shows shipping address equal to billing', async ({ page }) => {
        await loginStorefront(page);
        await addFirstFeaturedProductToBasket(page);

        // Confirm the premise before advancing: on a fresh checkout session nobody ticked
        // "Ship to a different address", so OXID's own default is "use billing for shipping"
        // (session var `blshowshipaddress` unset -> the checkbox renders checked and the
        // shipping-address sub-form stays hidden). No extra UI steps are added for this -
        // the flow below (goToCheckoutPayment) already walks straight through it.
        await page.goto('/index.php?cl=user');
        await page.waitForLoadState('domcontentloaded');
        const useBillingForShipping = page.locator('#showShipAddress');
        if (await useBillingForShipping.count()) {
            await expect(useBillingForShipping, 'default checkout state is "use billing address for shipping"').toBeChecked();
            await expect(page.locator('#shippingAddress')).toBeHidden();
        }

        await goToCheckoutPayment(page);
        await selectMolliePaymentMethod(page, 'ideal');
        await continueToOrderReview(page);

        await acceptTermsAndConditions(page);
        const method = await pickRedirectMollieMethod(page);
        test.skip(method === 'card-only', 'only the card method is offered — the redirect happy path cannot run');
        await page.getByRole('button', { name: /zahlungspflichtig bestellen|place order|order now/i }).click();

        await completeMollieTestPayment(page, 'paid');

        await page.waitForURL(/cl=thankyou|fnc=checkoutReturn/i, { timeout: 45_000 });
        await page.waitForLoadState('domcontentloaded');

        const body = await page.locator('body').innerText();
        expect(body).not.toMatch(/MOLLIE_CHECKOUT_UNAVAILABLE|MOLLIE_RETURN_/i);
        expect(body).toMatch(/Vielen Dank|thank you/i);

        const orderMatch = body.match(/Nummer\s*(\d+)|order number\s*[:\s]*(\d+)/i);
        expect(orderMatch, 'order number must be readable from the thank-you page').not.toBeNull();
        const orderNumber = (orderMatch?.[1] || orderMatch?.[2] || '').trim();
        expect(orderNumber).not.toBe('');

        const adminLogin = new AdminLoginPage(page);
        const ordersPage = new AdminOrdersPage(page);
        const molliePage = new AdminMollieOrderPage(page);

        await adminLogin.navigate();
        if (!(await adminLogin.isLoggedIn())) {
            await adminLogin.login({ email: ADMIN_USER, password: ADMIN_PW });
        }

        await ordersPage.navigateToOrders();
        const found = await ordersPage.selectOrderByNumber(orderNumber);
        expect(found, `order ${orderNumber} must be found in the admin order list`).toBe(true);

        await molliePage.openAddressesTab();
        const billingLastName = await molliePage.billingLastName();
        const shippingLastName = await molliePage.shippingLastName();

        expect(billingLastName, 'billing last name must be non-empty for this assertion to be meaningful').not.toBe('');

        // The ticket's defect: today this fails with shippingLastName === '' while
        // billingLastName is the customer's real last name. Expected (after Story 4):
        // shippingLastName equals billingLastName.
        expect(shippingLastName).toBe(billingLastName);
    });
});
