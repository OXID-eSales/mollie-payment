import { test, expect, type Page, type TestInfo } from '@playwright/test';
import {
    loginStorefront,
    addFirstFeaturedProductToBasket,
    acceptTermsAndConditions,
    completeMollieTestPayment,
} from '../../fixtures/shop-helpers';

/**
 * Manual capture must not narrow what the shopper can pay with.
 *
 * With `sMollieCaptureMode=manual` the inline selector used to list only the methods Mollie can
 * authorize first (card, Klarna, …), so a manual-capture shop silently lost iDEAL, PayPal, bank
 * transfer and the rest. Now every enabled method is offered; the ones that cannot hold an
 * authorization are created with automatic capture and settle immediately, and manual capture
 * applies only where Mollie supports it.
 *
 * Adaptive: the spec reads which methods the inline selector offers. It needs inline mode (payment-
 * base iframe flag + a Mollie profile id) and at least one instant method enabled on the Mollie
 * profile; otherwise it records why and skips. It cannot read the shop's capture mode, so it proves
 * the contract that holds in BOTH modes: instant methods are listed and a payment made with one
 * finalizes as paid.
 */
const INSTANT = ['ideal', 'paypal', 'banktransfer', 'bancontact', 'eps', 'przelewy24', 'kbc', 'belfius', 'paybybank'];

async function shot(page: Page, testInfo: TestInfo, name: string): Promise<void> {
    await testInfo.attach(name, { body: await page.screenshot({ fullPage: true }), contentType: 'image/png' });
}

async function reachOrderPage(page: Page): Promise<void> {
    await page.goto('/index.php?cl=payment&lang=1');
    await page.waitForLoadState('networkidle');
    if (!page.url().includes('cl=order')) {
        const mollie = page.locator('input[name=paymentid][value=oe_payments_mollie]').first();
        if (await mollie.isVisible().catch(() => false)) await mollie.check({ force: true });
        await page.getByRole('button', { name: /weiter|continue|next/i }).first().click();
        await page.waitForLoadState('networkidle').catch(() => {});
    }
    await expect(page).toHaveURL(/cl=order/);
}

test.describe('Mollie manual capture — every method stays available', () => {
    test('instant methods are offered next to card, and paying with one finalizes', async ({ page }, testInfo) => {
        await loginStorefront(page);
        await addFirstFeaturedProductToBasket(page);
        await reachOrderPage(page);

        const radios = page.locator('input[name=mollieMethod]');
        const offered = await radios.evaluateAll((els) => els.map((e) => (e as HTMLInputElement).value));
        testInfo.annotations.push({ type: 'offered', description: offered.join(',') });
        console.log(`[manual-capture] inline selector offers: ${offered.join(', ')}`);
        test.skip(offered.length === 0, 'inline selector not rendered (redirect mode) — nothing to read here');

        const instant = offered.filter((id) => INSTANT.includes(id));
        await shot(page, testInfo, `01 — inline selector offers: ${offered.join(', ')}`);
        expect(instant, `instant methods must be listed next to card; offered: ${offered.join(', ')}`).not.toHaveLength(0);

        const paypal = page.locator('input[name=mollieMethod][value=paypal]');
        const chosen = (await paypal.count()) ? 'paypal' : instant[0];
        await page.locator(`input[name=mollieMethod][value=${chosen}]`).check({ force: true });
        await acceptTermsAndConditions(page);
        await page.getByRole('button', { name: /zahlungspflichtig bestellen|place order|order now/i }).click();
        await page.waitForURL(/mollie\.com/i, { timeout: 45_000 });
        testInfo.annotations.push({ type: 'paid-with', description: chosen });
        console.log(`[manual-capture] paying with ${chosen} at ${page.url()}`);

        // An instant method on Mollie's test page: pick "Paid" and return.
        await completeMollieTestPayment(page, 'paid');
        await page.waitForURL(/cl=thankyou|fnc=checkoutReturn/i, { timeout: 45_000 });
        await page.waitForLoadState('domcontentloaded');
        const body = await page.locator('body').innerText();
        expect(body).not.toMatch(/MOLLIE_CHECKOUT_UNAVAILABLE|MOLLIE_RETURN_/i);
        expect(body).toMatch(/Vielen Dank|thank you/i);
        console.log(`[manual-capture] finalized: ${page.url()}`);
        await shot(page, testInfo, `02 — paid with ${chosen}, order finalized`);
    });
});
