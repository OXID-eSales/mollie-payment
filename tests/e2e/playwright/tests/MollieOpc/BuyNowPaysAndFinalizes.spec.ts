import { test, expect } from '@playwright/test';
import {
    loginStorefront,
    addFirstFeaturedProductToBasket,
    completeMollieTestPayment,
    submitOpcMollieFooter,
} from '../../fixtures/shop-helpers';

/**
 * Full OPC happy path: buy-now one-page-checkout → pay with Mollie → return → order finalized.
 *
 * Exercises the two fixes together:
 *   - MolliePaymentHandler (oe.payment.handler) so OPC can route Mollie, and
 *   - the OPC default-footer redirect fix so the browser is handed off to Mollie's hosted checkout
 *     instead of finalizing the order early.
 * Then Mollie's test-mode pay + the redirect return finalizes the order (thank-you page).
 */
test.describe('OPC buy-now — Mollie happy path', () => {
    test('customer buys via OPC, pays with Mollie, and the order finalizes', async ({ page }) => {
        await loginStorefront(page);
        await addFirstFeaturedProductToBasket(page);

        // Open the one-page-checkout buy-now modal.
        await page.locator(
            '[data-action*="buy-now#openCheckoutFromBasket"], [data-action*="buy-now#prepareBuyNow"], .onepage-buy-now',
        ).first().click({ force: true });

        const modal = page.locator('#buyNowCheckoutModal');
        await expect(modal).toBeVisible({ timeout: 20_000 });

        // Payment methods load from ?cl=OeOpcPayment&fnc=getPaymentListJson.
        const select = modal.locator('#paymentMethodSelect');
        await expect(select).toBeEnabled({ timeout: 30_000 });
        await select.selectOption('oe_payments_mollie', { force: true });
        await select.dispatchEvent('change');
        await page.waitForTimeout(1500);

        // Capture the footer type BEFORE submitting (the page navigates away on submit).
        const inlineWidget = (await modal.locator('[data-controller~="mollie-checkout-footer"]').count()) > 0;

        // Footer-agnostic submit: default redirect footer (iframe flag off) or the Mollie inline
        // widget (flag on). Both hand the browser off to Mollie's hosted checkout.
        await submitOpcMollieFooter(modal);

        // The inline widget forces a specific Mollie method, so the hosted page lands on THAT
        // method's flow (PayPal — the method completeMollieTestPayment can drive — is not in the
        // inline list on this profile). Assert the redirect handoff and stop: the full pay+finalize
        // leg is covered by the default-footer path (iframe flag off, as in CI) and by the
        // MollieStandard specs. With the default footer, complete the whole flow here.
        if (inlineWidget) {
            await expect(page, 'inline widget hands off to Mollie hosted checkout')
                .toHaveURL(/mollie\.com\/checkout/i, { timeout: 45_000 });
            return;
        }

        // Hand-off to Mollie's hosted checkout, then pay in test mode (PayPal → status "Paid").
        await completeMollieTestPayment(page, 'paid');

        // Return leg re-fetches the paid status, finalizes the order, and renders thank-you.
        await page.waitForURL(/cl=thankyou|fnc=checkoutReturn/i, { timeout: 45_000 });
        await page.waitForLoadState('domcontentloaded');

        const body = await page.locator('body').innerText();
        expect(body).not.toMatch(/MOLLIE_CHECKOUT_UNAVAILABLE|MOLLIE_RETURN_/i);
        expect(body).toMatch(/Vielen Dank|thank you/i);
        expect(body).toMatch(/Nummer\s*\d+|order number/i);
    });
});
