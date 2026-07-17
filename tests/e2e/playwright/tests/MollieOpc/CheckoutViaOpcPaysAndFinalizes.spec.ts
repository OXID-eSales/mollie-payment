import { test, expect } from '@playwright/test';
import {
    loginStorefront,
    addFirstFeaturedProductToBasket,
} from '../../fixtures/shop-helpers';

/**
 * One-Page-Checkout (OPC) payment step + Mollie — run with Stripe AND PayPal ALSO active.
 *
 * REGRESSION UNDER TEST
 * ---------------------
 * OPC's payment step loads its methods from `?cl=OeOpcPayment&fnc=getPaymentListJson`. That
 * endpoint used to 500 ("Class …\<PSP>\Controller\PaymentController_parent not found") whenever
 * two or more payment modules extended the core PaymentController, because OPC served the route
 * from a class that is itself a member of the PaymentController chain — instantiating it by its
 * concrete name triggered a re-entrant ModuleChainsGenerator build. The fix routes `OeOpcPayment`
 * to a standalone {@see \OxidEsales\OnePageCheckout\Application\Controller\OeOpcPaymentController}
 * that extends the *unified* PaymentController (not a chain member), so the list populates
 * regardless of how many PSPs are active or their chain order.
 *
 * This test drives the real storefront (logged-in customer, non-empty basket) to the OPC payment
 * step and asserts the method <select> populates with Mollie AND the other active PSPs — the exact
 * surface that previously threw HTTP 500.
 *
 * NOTE: completing an order through the OPC one-page UI is intentionally NOT asserted here — that
 * template is still work-in-progress (it renders a debug banner and no reliable submit control).
 * The full Mollie pay -> return -> finalize leg is covered by
 * ../MollieStandard/CheckoutPaysAndFinalizes.spec.ts, which now passes with all PSPs active.
 */
test.describe('Mollie one-page checkout (OPC) — payment methods load with all PSPs active', () => {
    test('OPC payment step lists Mollie, Stripe and PayPal (no PaymentController chain crash)', async ({ page }) => {
        const failedResponses: string[] = [];
        page.on('response', (r) => {
            if (r.url().includes('OeOpcPayment') && r.status() >= 400) {
                failedResponses.push(`${r.status()} ${r.url()}`);
            }
        });

        await loginStorefront(page);
        await addFirstFeaturedProductToBasket(page);

        await page.goto('/index.php?cl=OeCheckoutController');
        await page.waitForLoadState('domcontentloaded');

        // The <select> is disabled until getPaymentListJson populates it — this is the regression
        // surface. With the fix it must become enabled (previously the request 500'd).
        const select = page.locator('#paymentMethodSelect');
        await expect(select).toBeEnabled({ timeout: 30_000 });

        // All three active PSPs must be offered — proves the method list built with every PSP
        // extending PaymentController, i.e. the chain-crash is gone.
        await expect(select.locator('option[value="oe_payments_mollie"]')).toHaveCount(1);
        await expect(select.locator('option[value="oe_payments_stripe_wallet"]')).toHaveCount(1);
        await expect(select.locator('option[value="oe_payments_paypal"]')).toHaveCount(1);

        // The endpoint must never have returned an error status.
        expect(failedResponses, `OeOpcPayment returned error(s): ${failedResponses.join(', ')}`).toHaveLength(0);
    });
});
