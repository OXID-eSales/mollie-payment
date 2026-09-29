import { test, expect, Page } from '@playwright/test';
import {
    loginStorefront,
    addFirstFeaturedProductToBasket,
    goToCheckoutPayment,
    selectMolliePaymentMethod,
    continueToOrderReview,
    acceptTermsAndConditions,
    pickRedirectMollieMethod,
} from '../../fixtures/shop-helpers';
import { articleStockOf, setArticleStock } from '../../fixtures/shop-db';

/**
 * MOL-22 — "Order now" with Mollie while an item in the basket is not orderable must tell the
 * shopper exactly that, translated, and send them back to the basket — never the raw key
 * `MOLLIE_CHECKOUT_UNAVAILABLE`, never the misleading "Mollie is not available right now".
 *
 * Two ways an item stops being orderable between the order step and the submit:
 *  - it turns unbuyable (stock 0, flag 3) → the pre-dispatch guard, no contract, no order;
 *  - the ordered quantity exceeds the stock (2 ordered, 1 left, flag 3) → core's finalizeOrder()
 *    refuses inside the dispatch (payment-base code `article_not_buyable`).
 * The product is the demodata article the helpers put in the basket; its stock is restored in `finally`.
 */
const PRODUCT_ID = process.env.MOLLIE_E2E_PRODUCT_ID || '22e135eb03a3aa69198ae30762ee785c';
const ORDER_NOW = /zahlungspflichtig bestellen|place order|order now/i;
const CLEAR_MESSAGE = /not orderable|nicht bestellbar/i;
const RAW_OR_GENERIC = /MOLLIE_CHECKOUT_UNAVAILABLE|ERROR_MESSAGE_ARTICLE|ERROR_MESSAGE_OUTOFSTOCK|not available right now|derzeit nicht verfügbar/i;

async function reachMollieOrderStep(page: Page, quantity: 1 | 2): Promise<void> {
    await loginStorefront(page);
    await addFirstFeaturedProductToBasket(page);
    if (quantity === 2) {
        await addFirstFeaturedProductToBasket(page);
    }
    await goToCheckoutPayment(page);
    await selectMolliePaymentMethod(page);
    await continueToOrderReview(page);
    await expect(page).toHaveURL(/cl=order/);
    // A redirect method keeps the submit a plain form POST (the card flow tokenizes client-side first).
    await pickRedirectMollieMethod(page);
    await acceptTermsAndConditions(page);
}

async function orderNow(page: Page): Promise<void> {
    const button = page.getByRole('button', { name: ORDER_NOW }).first();
    await expect(button).toBeEnabled();
    await button.click();
    await page.waitForLoadState('domcontentloaded');
}

async function expectClearMessageOnBasket(page: Page): Promise<string> {
    expect(page.url(), 'must stay in the shop').not.toMatch(/mollie\.com/i);
    // Apex answers the basket step under its SEO path (/warenkorb/, /cart/) or as cl=basket.
    await expect(page, 'the shopper is sent back to the basket').toHaveURL(/cl=basket|\/warenkorb\/|\/cart\//i);
    const alerts = page.locator('.alert-danger, .alert');
    await expect(alerts.first()).toBeVisible();
    const text = (await alerts.allTextContents()).join(' ');
    expect(text, 'a clear, translated sentence').toMatch(CLEAR_MESSAGE);
    expect(text, 'no raw key, no generic "unavailable"').not.toMatch(RAW_OR_GENERIC);
    return text;
}

test.describe('MOL-22 — a not-orderable item is named clearly instead of MOLLIE_CHECKOUT_UNAVAILABLE', () => {
    test('item turned unbuyable after the order step rendered → named on the basket page', async ({ page }) => {
        const original = articleStockOf(PRODUCT_ID);
        try {
            await reachMollieOrderStep(page, 1);
            setArticleStock(PRODUCT_ID, { stock: 0, flag: 3 });

            await orderNow(page);

            const text = await expectClearMessageOnBasket(page);
            expect(text, 'the item is named').toMatch(/Ocean Eyes/);
        } finally {
            setArticleStock(PRODUCT_ID, original);
        }
    });

    test('ordered quantity above the remaining stock → refused by core inside the dispatch, still clear', async ({ page }) => {
        const original = articleStockOf(PRODUCT_ID);
        try {
            await reachMollieOrderStep(page, 2);
            setArticleStock(PRODUCT_ID, { stock: 1, flag: 3 });

            await orderNow(page);

            await expectClearMessageOnBasket(page);
        } finally {
            setArticleStock(PRODUCT_ID, original);
        }
    });
});
