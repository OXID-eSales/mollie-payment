import { test, expect, type Page } from '@playwright/test';
import {
    loginStorefront,
    addFirstFeaturedProductToBasket,
    goToCheckoutPayment,
    selectMolliePaymentMethod,
    continueToOrderReview,
    acceptTermsAndConditions,
    pickRedirectMollieMethod,
    completeMollieTestPayment,
} from '../../fixtures/shop-helpers';
import { allOrderIds, ordersAddedSince, billingStreetOf, setBillingStreetOf } from '../../fixtures/shop-db';

/**
 * MOL-15 — Mollie validates the shopper's address with payment-base's central validation system
 * exactly as the Stripe module does: the same rules, and a per-field translated message
 * ("The street field is not valid. Allowed symbols are: letters, digits, spaces, ' - . , /").
 *
 * Two boundaries in the classic checkout:
 *  1. the payment step (`PaymentController::validatePayment`) - the shopper is stopped early;
 *  2. the order step (`MollieOrderController::execute`) - the point of no return before the PSP is
 *     called, for data that went bad after the payment step (another tab, account page).
 *
 * The invalid character is planted straight in the DB (fixtures/shop-db.ts) rather than through the
 * address form: an address-validation module on the shop may reject it at the form, and the point
 * here is what Mollie does with data that IS stored. The spec restores the street in `finally`.
 *
 * Story 1 of sprint MOL-15: RED until Stories 3-4 land (today the payment step shows the raw key
 * MOLLIE_VALIDATION_INVALID_USER_DATA and the order step does not validate at all).
 */
const USER_EMAIL = process.env.TEST_USER_EMAIL || 'playwright.user@oxid-esales.dev';
const BAD_STREET = 'Hugo-Junkers Str: <script>';
const FIELD_MESSAGE = /street field is not valid\. Allowed symbols are: letters, digits, spaces|Straße.*ungültig.*Erlaubte Zeichen/i;
const REVIEW_MESSAGE = /review your address details|Adressdaten/i;

test.describe('MOL-15 — invalid address characters block the Mollie checkout with a per-field message', () => {
    test('payment step: the message names the field and its allowed symbols; no raw translation key', async ({ page }) => {
        const originalStreet = billingStreetOf(USER_EMAIL);
        setBillingStreetOf(USER_EMAIL, BAD_STREET);
        try {
            await loginStorefront(page);
            await addFirstFeaturedProductToBasket(page);
            await goToCheckoutPayment(page);
            await selectMolliePaymentMethod(page, 'paypal');
            await continueToOrderReview(page);
            await page.waitForLoadState('domcontentloaded');

            // OXID renders the refused step on the POST response (the URL lags a step behind), so the
            // rendered step heading is the reliable signal.
            await expect(page.getByRole('heading', { name: /Versand & Zahlungsart|Shipping & Payment/i }), 'the shopper stays on the payment step')
                .toBeVisible({ timeout: 15_000 });
            const body = await page.locator('body').innerText();
            expect(body, 'no raw translation key may leak to the shopper').not.toMatch(/MOLLIE_VALIDATION_/);
            expect(body).toMatch(FIELD_MESSAGE);
        } finally {
            setBillingStreetOf(USER_EMAIL, originalStreet);
        }
    });

    test('order step: data that went bad after the payment step is refused before Mollie is called', async ({ page }) => {
        const originalStreet = billingStreetOf(USER_EMAIL);
        const baseline = allOrderIds();
        try {
            await reachOrderPage(page, { fresh: true });

            // Another tab / the account page changed the address meanwhile.
            setBillingStreetOf(USER_EMAIL, BAD_STREET);
            await page.getByRole('button', { name: /order now|zahlungspflichtig bestellen|place order/i }).first().click();
            await page.waitForLoadState('domcontentloaded');

            await expect(page.getByRole('heading', { name: /^(Adressen|Addresses|Address)$/i }), 'the shopper is sent back to the address step')
                .toBeVisible({ timeout: 15_000 });
            expect(page.url(), 'must not leave for Mollie').not.toMatch(/mollie\.com/i);
            const body = await page.locator('body').innerText();
            expect(body).not.toMatch(/MOLLIE_VALIDATION_/);
            expect(body).toMatch(FIELD_MESSAGE);
            expect(body).toMatch(REVIEW_MESSAGE);
            expect(ordersAddedSince(baseline), 'no contract, no order').toHaveLength(0);

            // Repaired -> the same session pays normally.
            setBillingStreetOf(USER_EMAIL, originalStreet);
            await reachOrderPage(page, { fresh: false });
            await page.getByRole('button', { name: /order now|zahlungspflichtig bestellen|place order/i }).first().click();
            await completeMollieTestPayment(page, 'paid');
            await page.waitForURL(/cl=thankyou/i, { timeout: 45_000 });
            expect(ordersAddedSince(baseline)).toHaveLength(1);
        } finally {
            setBillingStreetOf(USER_EMAIL, originalStreet);
        }
    });
});

async function reachOrderPage(page: Page, { fresh }: { fresh: boolean }): Promise<void> {
    if (fresh) {
        await loginStorefront(page);
        await addFirstFeaturedProductToBasket(page);
        await goToCheckoutPayment(page);
        await selectMolliePaymentMethod(page, 'paypal');
        await continueToOrderReview(page);
    }
    await page.goto('/index.php?cl=order');
    await page.waitForLoadState('domcontentloaded');
    await acceptTermsAndConditions(page);
    const method = await pickRedirectMollieMethod(page);
    test.skip(method === 'card-only', 'only the card method is offered — the redirect path cannot run');
}
