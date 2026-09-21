import { test, expect, type Page, type TestInfo } from '@playwright/test';
import { loginStorefront, addFirstFeaturedProductToBasket, completeMollieTestPayment, openOpcCheckoutModal, opcPaymentSectionFolded, OPC_FOLD_SKIP, waitForOpcPaymentState, tickOpcConsents, chooseMollieInOpcModal, pickMollieMethodRadio } from '../../fixtures/shop-helpers';

/**
 * OPC footer — manual capture must not narrow what the shopper can pay with.
 *
 * The Mollie footer widget in the buy-now modal lists the same methods as the standard order page
 * (both read PaymentMethodListService through the ViewConfig). With `sMollieCaptureMode=manual`
 * that list used to hold only the methods Mollie can authorize first; now every enabled method is
 * offered, the chosen one rides through OPC's processCheckout into the create-payment, and
 * CheckoutPaymentService asks for manual capture only where the method supports it.
 *
 * Proven here end to end: instant methods are listed in the footer, PayPal is chosen, Mollie's
 * test page marks it paid, the order finalizes. Adaptive: skips (loudly) when the inline footer
 * is not rendered (iframe flag off) or no instant method is enabled on the profile.
 */
const INSTANT = ['ideal', 'paypal', 'banktransfer', 'bancontact', 'eps', 'przelewy24', 'kbc', 'belfius', 'paybybank'];
const FOOTER = '[data-controller~="mollie-checkout-footer"]';

async function shot(page: Page, testInfo: TestInfo, name: string): Promise<void> {
    const body = await page.screenshot({ fullPage: true }).catch(() => null);
    if (body) await testInfo.attach(name, { body, contentType: 'image/png' });
}

test.describe('OPC footer — manual capture keeps every Mollie method available', () => {
    test('instant methods are offered in the footer, and paying with one via OPC finalizes', async ({ page }, testInfo) => {
        await loginStorefront(page);
        await addFirstFeaturedProductToBasket(page);
        const modal = await openOpcCheckoutModal(page);
        const state = await chooseMollieInOpcModal(modal);
        testInfo.annotations.push({ type: 'opc-payment-state', description: state });
        // Single method: OPC auto-assigns it and loads its footer without any selection.
        const footer = modal.locator(FOOTER);
        await footer.waitFor({ state: 'attached', timeout: 20_000 }).catch(() => {});
        test.skip((await footer.count()) === 0, 'Mollie inline footer not rendered (iframe flag off) — nothing to read here');

        const radios = modal.locator('input[name=mollieMethod]');
        const offered = await radios.evaluateAll((els) => els.map((e) => (e as HTMLInputElement).value));
        console.log(`[opc-manual-capture] footer offers: ${offered.join(', ')}`);
        testInfo.annotations.push({ type: 'offered', description: offered.join(',') });
        const instant = offered.filter((id) => INSTANT.includes(id));
        await shot(page, testInfo, `01 — footer offers: ${offered.join(', ')}`);
        expect(instant, `instant methods must be listed next to card; offered: ${offered.join(', ')}`).not.toHaveLength(0);

        // The list above is read from the DOM, so it holds even when OPC keeps the section folded.
        test.skip(await opcPaymentSectionFolded(modal), OPC_FOLD_SKIP);

        const chosen = offered.includes('paypal') ? 'paypal' : instant[0];
        await pickMollieMethodRadio(modal.locator(`input[name=mollieMethod][value=${chosen}]`));
        await tickOpcConsents(modal);
        await modal.locator(`${FOOTER} [data-mollie-checkout-footer-target="submitButton"]`).first().click();
        await page.waitForURL(/mollie\.com\/checkout/i, { timeout: 45_000 });
        console.log(`[opc-manual-capture] paying with ${chosen} at ${page.url()}`);

        await completeMollieTestPayment(page, 'paid');
        await page.waitForURL(/cl=thankyou|fnc=checkoutReturn/i, { timeout: 45_000 });
        await page.waitForLoadState('domcontentloaded');
        const body = await page.locator('body').innerText();
        expect(body).not.toMatch(/MOLLIE_CHECKOUT_UNAVAILABLE|MOLLIE_RETURN_/i);
        expect(body).toMatch(/Vielen Dank|thank you/i);
        console.log(`[opc-manual-capture] finalized: ${page.url()}`);
        await shot(page, testInfo, `02 — paid with ${chosen} via OPC, order finalized`);
    });
});
