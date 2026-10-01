import { Page, expect } from '@playwright/test';
import {
    loginStorefront,
    addFirstFeaturedProductToBasket,
    goToCheckoutPayment,
    selectMolliePaymentMethod,
    continueToOrderReview,
    setAgbChecked,
} from './shop-helpers';

/**
 * Mollie Components inline card flow on the standard order step (iframe flag on, manual capture):
 * fill the four js.mollie.com field iframes, place the order, authorize on Mollie's test page and
 * return to the thank-you page. The payment then is an `authorized` hold the admin can capture.
 * Extracted from MollieStandard/InlineCardComponents.spec.ts for admin specs that need a fresh
 * capturable order.
 */
export const TEST_CARD = {
    cardNumber: '4111111111111111',
    cardHolder: 'Marc Muster',
    expiryDate: '1230',
    verificationCode: '123',
};

export async function fillComponentsField(page: Page, field: string, value: string): Promise<void> {
    const frame = page.frameLocator(`iframe[name="${field}-input"]`);
    const input = frame.locator('input, [contenteditable="true"], [role="textbox"]').first();
    if (await input.count().catch(() => 0)) {
        await input.click().catch(() => {});
        await input.pressSequentially(value, { delay: 30 }).catch(async () => {
            await page.keyboard.type(value, { delay: 30 });
        });
        return;
    }
    await frame.locator('body').click().catch(() => {});
    await page.keyboard.type(value, { delay: 30 });
}

/** Walks the storefront to a finalized order paid by an authorized (uncaptured) card. */
export async function checkoutAuthorizedByInlineCard(page: Page): Promise<void> {
    await loginStorefront(page);
    await addFirstFeaturedProductToBasket(page);
    await goToCheckoutPayment(page);
    await selectMolliePaymentMethod(page);
    await continueToOrderReview(page);
    await expect(page).toHaveURL(/cl=order/);

    // MOL-9: the Mollie block is locked until the AGB box is ticked — tick first, then pick Card.
    if (await page.locator('#checkAgbTop').count()) {
        await setAgbChecked(page, true);
    }
    const card = page.locator('input[name="mollieMethod"][value="creditcard"]');
    await expect(card, 'the Card method must be offered (inline Components flow)').toHaveCount(1);
    await card.check();
    for (const field of Object.keys(TEST_CARD)) {
        await expect(page.locator(`iframe[name="${field}-input"]`)).toBeAttached({ timeout: 20_000 });
    }
    await page.waitForTimeout(3000);
    for (const [field, value] of Object.entries(TEST_CARD)) {
        await fillComponentsField(page, field, value);
    }
    await page.getByRole('button', { name: /order now|zahlungspflichtig bestellen|place order/i }).first().click();

    await expect(page, 'Mollie 3DS / test-mode page').toHaveURL(/mollie\.com\/checkout/i, { timeout: 30_000 });
    await page.waitForLoadState('domcontentloaded').catch(() => {});
    const authorized = page.getByText('Authorized', { exact: true });
    if (await authorized.isVisible({ timeout: 10_000 }).catch(() => false)) {
        await authorized.click();
    }
    await page.getByRole('button', { name: /continue|weiter/i }).first().click().catch(() => {});
    await page.waitForURL(/cl=thankyou|fnc=checkoutReturn|thankyou/i, { timeout: 45_000 });
    await page.waitForLoadState('domcontentloaded').catch(() => {});
}
