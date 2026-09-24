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
import { readThankYouOrderNumber } from '../../fixtures/mollie-authorized-helpers';
import { shopDbQuery } from '../../fixtures/shop-db';
import { AdminLoginPage } from '../pages/admin/AdminLoginPage';
import { AdminOrdersPage } from '../pages/admin/AdminOrdersPage';
import { AdminMollieOrderPage } from '../pages/admin/AdminMollieOrderPage';

/**
 * MOL-17 — the shopper's return leg and Mollie's `paid` webhook race on the contract. When the
 * webhook wins, the return leg used to overwrite the `fulfilled` contract with its stale `committed`
 * copy, and the admin Payment tab offered no Refund form for a paid order.
 *
 * Deterministic: the return request (`fnc=checkoutReturn`) is held back for a few seconds so the
 * webhook is guaranteed to land first. Expected: thank-you page, contract `fulfilled`, Refund form.
 */
const ADMIN_USER = process.env.ADMIN_USER_EMAIL || 'noreply@oxid-esales.com';
const ADMIN_PW = process.env.ADMIN_USER_PASSWORD || 'admin';

test.describe('MOL-17 — refund form after a paid payment when the webhook arrives first', () => {
    test('held-back return leg does not undo the webhook fulfilment', async ({ page, browser }) => {
        test.setTimeout(6 * 60_000); // checkout + held return + Mollie pages + admin navigation
        await loginStorefront(page);
        await addFirstFeaturedProductToBasket(page);
        await goToCheckoutPayment(page);
        await selectMolliePaymentMethod(page, 'paypal');
        await continueToOrderReview(page);
        await acceptTermsAndConditions(page);
        const method = await pickRedirectMollieMethod(page);
        test.skip(method === 'card-only', 'only the card method is offered — the redirect path cannot run');

        // Let the webhook win: hold the shopper's return request for 6 seconds.
        const holdReturn = async (route: import('@playwright/test').Route) => {
            if (/checkoutReturn/i.test(route.request().url())) {
                await new Promise((resolve) => setTimeout(resolve, 6_000));
            }
            await route.continue();
        };
        await page.route('**/*', holdReturn);

        await page.getByRole('button', { name: /order now|zahlungspflichtig bestellen|place order/i }).first().click();
        await completeMollieTestPayment(page, 'paid');
        await page.waitForURL((url) => !/mollie\.com$/i.test(url.hostname), { timeout: 60_000 });
        await page.waitForLoadState('networkidle').catch(() => {});
        await page.unroute('**/*', holdReturn);

        const body = await page.locator('body').innerText();
        expect(body, 'the shopper must not see an error after a paid payment').not.toMatch(/MOLLIE_RETURN_|Fatal|Exception|error 500/i);
        expect(page.url(), 'the shopper lands on the thank-you page').toMatch(/cl=thankyou/i);
        const orderNumber = await readThankYouOrderNumber(page);
        expect(orderNumber).not.toBeNull();

        const [state, fulfilledAt] = shopDbQuery(
            `SELECT c.OXSTATE, IFNULL(c.OXFULFILLEDAT,'NULL') FROM oxorder o JOIN oe_payments_contract c ON c.OXORDERID=o.OXID WHERE o.OXORDERNR=${Number(orderNumber)}`,
        )[0];
        expect(state, 'the webhook fulfilment must survive the return leg').toBe('fulfilled');
        expect(fulfilledAt).not.toBe('NULL');

        // Admin in its own context: the storefront page still carries the route handler and its
        // session; the diagnostic RefundAdminInspection spec proved this exact admin flow standalone.
        const adminPage = await (await browser.newContext()).newPage();
        const adminLogin = new AdminLoginPage(adminPage);
        const ordersPage = new AdminOrdersPage(adminPage);
        const molliePage = new AdminMollieOrderPage(adminPage);
        await adminLogin.navigate();
        if (!(await adminLogin.isLoggedIn())) {
            await adminLogin.login({ email: ADMIN_USER, password: ADMIN_PW });
        }
        await ordersPage.navigateToOrders();
        expect(await ordersPage.selectOrderByNumber(orderNumber as string)).toBe(true);
        await ordersPage.openPaymentTab().catch(() => {});
        await molliePage.waitForContentLoaded().catch(() => {});
        await expect.poll(() => molliePage.isRefundButtonVisible(), { timeout: 20_000, message: 'a paid order must offer a refund' }).toBe(true);
        expect(await molliePage.getRefundableAmount()).toBeGreaterThan(0);
        await adminPage.context().close();
    });
});
