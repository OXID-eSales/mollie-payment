import { test, expect } from '@playwright/test';
import {
    loginStorefront,
    addFirstFeaturedProductToBasket,
    openOpcCheckoutModal,
    chooseMollieInOpcModal,
    OPC_FOLD_SKIP,
    submitOpcMollieFooter,
} from '../../fixtures/shop-helpers';
import { billingStreetOf, setBillingStreetOf } from '../../fixtures/shop-db';

/**
 * MOL-15 — the OPC Mollie footer validates the live address fields against payment-base's central
 * endpoint (cl=oepaymentvalidationapi) with Mollie's rules before it posts processCheckout, exactly
 * like the Stripe footer: the rejected field gets `.is-invalid` with the server-formatted message and
 * no processCheckout request is sent.
 *
 * Requires OPC on (`oeOnePageCheckoutEnabled: true`) and the iframe flag on (Mollie footer widget).
 * The invalid character is planted in the DB and restored in `finally`.
 */
const USER_EMAIL = process.env.TEST_USER_EMAIL || 'playwright.user@oxid-esales.dev';
const BAD_STREET = 'Hugo-Junkers Str: <script>';

test.describe('MOL-15 — OPC footer refuses an invalid address before processCheckout', () => {
    test('invalid street -> inline message on the field, no processCheckout, no redirect', async ({ page }) => {
        const originalStreet = billingStreetOf(USER_EMAIL);
        setBillingStreetOf(USER_EMAIL, BAD_STREET);
        const requests: string[] = [];
        page.on('request', (request) => {
            if (/processCheckout|oepaymentvalidationapi/i.test(request.url() + (request.postData() ?? ''))) {
                requests.push(request.url());
            }
        });
        try {
            await loginStorefront(page);
            await addFirstFeaturedProductToBasket(page);
            const modal = await openOpcCheckoutModal(page);
            const state = await chooseMollieInOpcModal(modal);
            test.skip(state === 'folded', OPC_FOLD_SKIP);
            await expect(page.locator('#buyNowCheckoutModal')).toBeVisible({ timeout: 20_000 });
            await page.waitForTimeout(1500);

            await submitOpcMollieFooter(page.locator('#buyNowCheckoutModal'));

            const street = page.locator('[data-billing-address-target="street"]').first();
            await expect(street).toHaveClass(/is-invalid/, { timeout: 15_000 });
            const feedback = page.locator('.invalid-feedback[data-mollie-validation]').first();
            await expect(feedback).toContainText(/street field is not valid|Straße ist ungültig/i);
            await expect(feedback).toContainText(/Allowed symbols|Erlaubte Zeichen/i);

            expect(page.url()).not.toMatch(/mollie\.com/i);
            expect(requests.some((url) => /oepaymentvalidationapi/i.test(url)), 'the central endpoint must be asked').toBe(true);
            expect(requests.some((url) => /processCheckout/i.test(url)), 'processCheckout must not be posted').toBe(false);
        } finally {
            setBillingStreetOf(USER_EMAIL, originalStreet);
        }
    });
});
