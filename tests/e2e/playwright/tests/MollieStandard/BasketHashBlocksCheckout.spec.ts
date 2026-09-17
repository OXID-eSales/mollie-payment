import { test, expect } from '@playwright/test';
import {
    loginStorefront,
    addFirstFeaturedProductToBasket,
    goToCheckoutPayment,
    selectMolliePaymentMethod,
    continueToOrderReview,
    acceptTermsAndConditions,
    pickRedirectMollieMethod,
} from '../../fixtures/shop-helpers';

/**
 * Regression (Sprint 03): "Order now" with a stale/tampered basketSummaryHash must never hand
 * off to Mollie's hosted checkout.
 *
 * Core `OrderController::execute()` compares the posted `basketSummaryHash` against the live
 * basket and, on mismatch, shows BASKET_ITEMS_CHANGED_ERROR and returns to the order (or
 * basket) step — the "basket changed in another tab" guard. `MollieOrderController::execute()`
 * intercepted before that comparison, so a stale order could be sent to Mollie with pricing
 * that diverges from the basket that gets finalized. The fix mirrors core's comparison in the
 * module (core's helpers are private).
 */
test.describe('Mollie standard checkout — basket-summary-hash enforcement', () => {
    test('tampered basket hash blocks the Mollie redirect and shows the basket-changed error', async ({ page }) => {
        await loginStorefront(page);
        await addFirstFeaturedProductToBasket(page);
        await goToCheckoutPayment(page);
        await selectMolliePaymentMethod(page);
        await continueToOrderReview(page);

        await acceptTermsAndConditions(page);

        // Pick a redirect method so the form POSTs natively to cl=order&fnc=execute — the card
        // Components JS would intercept the submit and mask the server-side guard under test.
        const method = await pickRedirectMollieMethod(page);
        test.skip(method === 'card-only', 'only the card method is offered — the server-side guard cannot be exercised');

        // Simulate "basket changed in another tab": the rendered form still carries the OLD hash.
        const tampered = await page.evaluate(() => {
            const inputs = document.querySelectorAll<HTMLInputElement>('input[name="basketSummaryHash"]');
            inputs.forEach((el) => { el.value = 'stale-basket-hash-from-another-tab'; });
            return inputs.length;
        });
        test.skip(tampered === 0, 'no basketSummaryHash field rendered on this shop/theme');

        const orderNowBtn = page.getByRole('button', {
            name: /zahlungspflichtig bestellen|place order|order now/i,
        });
        await orderNowBtn.click();
        await page.waitForLoadState('domcontentloaded');

        // Must NOT leave the shop for Mollie's hosted checkout.
        expect(page.url(), 'checkout left the shop despite a stale basket hash').not.toMatch(/mollie\.com/i);

        // Core's BASKET_ITEMS_CHANGED_ERROR, DE or EN.
        await expect(page.locator('.alert-danger').first()).toContainText(
            /Warenkorbartikel wurden geändert|shopping cart items have been changed/i,
        );
    });
});
