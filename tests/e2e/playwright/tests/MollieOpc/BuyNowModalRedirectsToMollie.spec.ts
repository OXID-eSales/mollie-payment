import { test, expect } from '@playwright/test';
import {
    loginStorefront,
    addFirstFeaturedProductToBasket,
    submitOpcMollieFooter, openOpcCheckoutModal, opcPaymentSectionFolded, OPC_FOLD_SKIP, waitForOpcPaymentState, chooseMollieInOpcModal } from '../../fixtures/shop-helpers';

/**
 * OPC buy-now modal + Mollie must REDIRECT to Mollie's hosted checkout (redirect return + webhook
 * are the source of truth), NOT finalize the order and land on thankyou. Diagnostic: streams the
 * DefaultFooter checkout flow + OeCheckoutApi traffic so the behaviour is observable.
 */
test.describe('OPC buy-now modal — Mollie must redirect to hosted checkout', () => {
    test('selecting Mollie and submitting redirects to mollie.com (not thankyou)', async ({ page }) => {
        await loginStorefront(page);
        await addFirstFeaturedProductToBasket(page); // reliable server-side basket population

        // Open the one-page-checkout modal from the basket (shared helper picks the right trigger).
        const opcModal = await openOpcCheckoutModal(page);
        const opcState = await chooseMollieInOpcModal(opcModal);
        test.skip(opcState === 'folded', OPC_FOLD_SKIP);

        const modal = page.locator('#buyNowCheckoutModal');
        await expect(modal).toBeVisible({ timeout: 20_000 });

        await page.waitForTimeout(1500);

        // Footer-agnostic submit: works with the default redirect footer (iframe flag off) and the
        // Mollie inline widget (flag on). Either way Mollie must hand off to its hosted checkout.
        await submitOpcMollieFooter(modal);

        await page.waitForURL(/mollie\.com|cl=thankyou|fnc=checkoutReturn/i, { timeout: 45_000 }).catch(() => {});
        const url = page.url();
        console.log('  [repro] landed on:', url);

        expect(url, `expected redirect to Mollie hosted checkout, but landed on ${url}`).toMatch(/mollie\.com/i);
    });
});
