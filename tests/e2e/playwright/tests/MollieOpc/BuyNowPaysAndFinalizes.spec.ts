import { test, expect } from '@playwright/test';
import {
    loginStorefront,
    addFirstFeaturedProductToBasket,
    completeMollieTestPayment,
    submitOpcMollieFooter, openOpcCheckoutModal, opcPaymentSectionFolded, OPC_FOLD_SKIP, waitForOpcPaymentState } from '../../fixtures/shop-helpers';

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

        // Open the one-page-checkout buy-now modal from the basket.
        const modal = await openOpcCheckoutModal(page);
        test.skip((await waitForOpcPaymentState(modal)) === 'folded', OPC_FOLD_SKIP);

        // Payment methods load from ?cl=OeOpcPayment&fnc=getPaymentListJson.
        const select = modal.locator('#paymentMethodSelect');
        await select.selectOption('oe_payments_mollie', { force: true });
        await select.dispatchEvent('change');
        await page.waitForTimeout(1500);

        // Capture the footer type BEFORE submitting (the page navigates away on submit).
        const inlineWidget = (await modal.locator('[data-controller~="mollie-checkout-footer"]').count()) > 0;

        // Footer-agnostic submit: default redirect footer (iframe flag off) or the Mollie inline
        // widget (flag on). Both hand the browser off to Mollie's hosted checkout.
        await submitOpcMollieFooter(modal);

        // The inline widget forces the chosen Mollie method, so the hosted page lands on THAT
        // method's flow. submitOpcMollieFooter prefers PayPal, whose test page is the simple
        // status screen completeMollieTestPayment drives (it clicks the PayPal tile only when
        // Mollie shows a method selection). Since manual capture stopped narrowing the inline
        // list (2026-09-21) PayPal is offered here too, so both footers complete the whole flow.
        await expect(page, `${inlineWidget ? 'inline widget' : 'default footer'} hands off to Mollie hosted checkout`)
            .toHaveURL(/mollie\.com\/checkout/i, { timeout: 45_000 });

        // Pay in test mode (PayPal → status "Paid").
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
