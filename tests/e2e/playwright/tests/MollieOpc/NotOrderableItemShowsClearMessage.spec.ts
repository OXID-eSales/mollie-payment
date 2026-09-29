import { test, expect } from '@playwright/test';
import {
    loginStorefront,
    addFirstFeaturedProductToBasket,
    openOpcCheckoutModal,
    chooseMollieInOpcModal,
    OPC_FOLD_SKIP,
    submitOpcMollieFooter,
} from '../../fixtures/shop-helpers';
import { articleStockOf, setArticleStock } from '../../fixtures/shop-db';

/**
 * MOL-22 — the OPC Mollie footer: submitting while an item in the basket is not orderable shows
 * a clear, translated sentence in the footer's error box — not the raw core key the handler used
 * to pass through ("Mollie payment processing failed: ERROR_MESSAGE_ARTICLE_ARTICLE_NOT_BUYABLE").
 *
 * Requires OPC on and the iframe flag on (Mollie footer widget). Stock is restored in `finally`.
 */
const PRODUCT_ID = process.env.MOLLIE_E2E_PRODUCT_ID || '22e135eb03a3aa69198ae30762ee785c';
const CLEAR_MESSAGE = /not orderable|nicht bestellbar/i;
const RAW_OR_GENERIC = /MOLLIE_CHECKOUT_UNAVAILABLE|ERROR_MESSAGE_ARTICLE|ERROR_MESSAGE_OUTOFSTOCK|Mollie payment processing failed|not available right now|derzeit nicht verfügbar/i;

test.describe('MOL-22 — OPC footer names a not-orderable item clearly', () => {
    test('item turned unbuyable after the modal opened → clear sentence in the footer, no redirect', async ({ page }) => {
        const original = articleStockOf(PRODUCT_ID);
        try {
            await loginStorefront(page);
            await addFirstFeaturedProductToBasket(page);
            const modal = await openOpcCheckoutModal(page);
            const state = await chooseMollieInOpcModal(modal);
            test.skip(state === 'folded', OPC_FOLD_SKIP);
            const footer = modal.locator('[data-controller~="mollie-checkout-footer"]');
            await expect(footer, 'the Mollie footer must render').toBeVisible({ timeout: 20_000 });

            setArticleStock(PRODUCT_ID, { stock: 0, flag: 3 });
            await submitOpcMollieFooter(modal);

            const error = footer.locator('[data-mollie-checkout-footer-target="error"]');
            await expect(error, 'the footer shows an error').toBeVisible({ timeout: 20_000 });
            const text = (await error.textContent()) ?? '';
            expect(text, 'a clear, translated sentence').toMatch(CLEAR_MESSAGE);
            expect(text, 'the item is named').toMatch(/Ocean Eyes/);
            expect(text, 'no raw key, no technical prefix').not.toMatch(RAW_OR_GENERIC);
            expect(page.url(), 'must stay in the shop').not.toMatch(/mollie\.com/i);
        } finally {
            setArticleStock(PRODUCT_ID, original);
        }
    });
});
