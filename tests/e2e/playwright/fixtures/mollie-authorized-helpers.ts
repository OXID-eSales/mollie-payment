import { Page, expect } from '@playwright/test';
import {
    loginStorefront,
    addFirstFeaturedProductToBasket,
    goToCheckoutPayment,
    selectMolliePaymentMethod,
    continueToOrderReview,
} from './shop-helpers';

/**
 * Mollie's standard TEST VISA card (masked "…9996" on the hosted test page).
 * Any future expiry + any 3-digit CVC. With captureMode=manual a successful card
 * payment lands in status `authorized` (funds held, not captured).
 */
const TEST_VISA = '4543474002249996';

/**
 * Fills Mollie's hosted credit-card component (an iframe: js.mollie.com/v2/components/card)
 * and submits, producing an `authorized` payment when the module runs in manual-capture mode.
 */
export async function payWithMollieCardExpectingAuthorized(page: Page): Promise<void> {
    await expect(page).toHaveURL(/mollie\.com\/checkout/i, { timeout: 45_000 });

    // Method-selection page -> Card.
    await page.getByRole('button', { name: /^card$/i }).first().click();
    await page.waitForLoadState('domcontentloaded');

    // Card fields live inside the Mollie Components iframe.
    const card = page.frameLocator('iframe[src*="components/card"]');
    await card.locator('#cardNumber').waitFor({ state: 'visible', timeout: 20_000 });
    await card.locator('#cardNumber').fill(TEST_VISA);
    await card.locator('#cardExpiryDate').pressSequentially('1230', { delay: 40 });
    await card.locator('#cardCvv').fill('123');
    await card.locator('#cardHolder').fill('Playwright Tester');

    // Submit: Mollie's hosted card page has a pay/continue button on the outer page.
    const payBtn = page
        .getByRole('button', { name: /pay|bezahlen|betalen|continue|weiter|€|eur/i })
        .first();
    if (await payBtn.isVisible().catch(() => false)) {
        await payBtn.click();
    } else {
        // Fallback: submit via the CVV field.
        await card.locator('#cardCvv').press('Enter');
    }

    // A valid test card in manual-capture mode routes to Mollie's test-mode status
    // selector, which offers "Authorized". Pick it and continue.
    await page.waitForURL(/mollie\.com\/checkout\/test-mode/i, { timeout: 45_000 });
    const statuses = await page.locator('body').innerText().catch(() => '');
    console.log('CARD TEST-MODE STATUSES:', statuses.replace(/\s+/g, ' ').slice(0, 300));
    const authorized = page.getByText('Authorized', { exact: true });
    await authorized.waitFor({ state: 'visible', timeout: 10_000 });
    await authorized.click();
    await page.getByRole('button', { name: /continue|weiter/i }).first().click();

    // Back to the shop thank-you (browser return). NOTE: this does NOT authorize the
    // contract by itself — the webhook does. Callers must poll for the authorized state.
    await page.waitForURL(/cl=thankyou|fnc=checkoutReturn|cl=order/i, { timeout: 60_000 });
}

/** Extracts the OXID order number shown on the thank-you page (best-effort). */
export async function readThankYouOrderNumber(page: Page): Promise<string | null> {
    const body = await page.locator('body').innerText().catch(() => '');
    const m = body.match(/(?:Order (?:no\.?|number)|Bestell(?:nummer)?)\D*(\d{2,})/i);
    return m ? m[1] : null;
}

/** Runs a full storefront checkout paying by card, expecting an authorized payment. */
export async function checkoutAuthorizedByCard(page: Page): Promise<{ orderNumber: string | null }> {
    await loginStorefront(page);
    await addFirstFeaturedProductToBasket(page);
    await goToCheckoutPayment(page);
    await selectMolliePaymentMethod(page);
    await continueToOrderReview(page);

    for (const sel of ['#checkAgbTop', '#checkAgb', 'input[name="ord_agb"]']) {
        const cb = page.locator(sel);
        if ((await cb.count()) > 0 && (await cb.first().isVisible().catch(() => false))) {
            await cb.first().check().catch(() => {});
        }
    }
    await page
        .getByRole('button', { name: /zahlungspflichtig bestellen|place order|order now/i })
        .first()
        .click();

    await payWithMollieCardExpectingAuthorized(page);
    const orderNumber = await readThankYouOrderNumber(page);
    return { orderNumber };
}
