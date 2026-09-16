import { test, expect } from '@playwright/test';
import {
    loginStorefront,
    addFirstFeaturedProductToBasket,
    goToCheckoutPayment,
    selectMolliePaymentMethod,
    continueToOrderReview,
    acceptTermsAndConditions,
    completeMollieTestPayment,
} from '../../fixtures/shop-helpers';

/**
 * Requires a real Mollie sandbox (test-mode) API key configured on the shop under test
 * (Admin -> Modules -> Mollie -> Settings) and the shop reachable at SHOP_URL. See README.md.
 *
 * Proves the full happy-path spine end to end:
 *   login -> standard checkout -> Mollie test-mode pay -> return -> thank-you page.
 *
 * The module's return leg is `cl=order&fnc=checkoutReturn`, which re-fetches the payment status
 * from Mollie's API and (for a paid payment) finalizes the order and renders the `thankyou`
 * template — so the browser return alone is sufficient here; a public webhook is not required
 * (the webhook remains authoritative for OXPAID in production, but is not exercised by this run).
 */
test.describe('Mollie standard checkout — happy path', () => {
    test('customer pays with Mollie and the order finalizes', async ({ page }) => {
        await loginStorefront(page);
        await addFirstFeaturedProductToBasket(page);
        await goToCheckoutPayment(page);
        await selectMolliePaymentMethod(page, 'ideal');
        await continueToOrderReview(page);

        // "Place order" submits to cl=order&fnc=execute; MolliePaymentController hands off to
        // MollieOrderController::execute(), which 302s straight to Mollie's hosted checkout.
        await acceptTermsAndConditions(page);
        await page.getByRole('button', { name: /zahlungspflichtig bestellen|place order|order now/i }).click();

        await completeMollieTestPayment(page, 'paid');

        // Mollie 302s back to MollieOrderController::checkoutReturn(); on a paid payment the
        // return re-fetches the status, finalizes the order, and renders the thank-you page
        // (which OXID redirects to the canonical cl=thankyou URL).
        await page.waitForURL(/cl=thankyou|fnc=checkoutReturn/i, { timeout: 45_000 });
        await page.waitForLoadState('domcontentloaded');

        // Confirm a real, finalized order — never a raw checkout/return error.
        const body = (await page.locator('body').innerText());
        expect(body).not.toMatch(/MOLLIE_CHECKOUT_UNAVAILABLE|MOLLIE_RETURN_/i);
        expect(body).toMatch(/Vielen Dank|thank you/i);
        expect(body).toMatch(/Nummer\s*\d+|order number/i);
    });
});
