import { test, expect, Page } from '@playwright/test';
import {
    loginStorefront,
    addFirstFeaturedProductToBasket,
    goToCheckoutPayment,
    selectMolliePaymentMethod,
    continueToOrderReview,
    setAgbChecked,
} from '../../fixtures/shop-helpers';
import { confirmAgbEnabled, setConfirmAgbEnabled } from '../../fixtures/shop-db';

/**
 * MOL-11 — on the standard order step the Mollie "Order now" button is inactive until the shopper
 * ticks the AGB checkbox (`blConfirmAGB` on), and active at once when the shop renders no checkbox
 * (`blConfirmAGB` off). Holds for both Mollie flows (classic redirect button and the inline
 * Components button): the spec locates the button by its role, not by flow.
 *
 * The server-side guard (AgbRequiredBlocksCheckout) stays the safety net; this is the shopper-facing
 * gate. Each test sets the flag itself through the DB and restores it in `finally`, so the proof does
 * not depend on the shop's current setting.
 */
const ORDER_NOW = /zahlungspflichtig bestellen|place order|order now/i;
const AGB_CHECKBOX = '#checkAgbTop';

async function openMollieOrderStep(page: Page): Promise<void> {
    await loginStorefront(page);
    await addFirstFeaturedProductToBasket(page);
    await goToCheckoutPayment(page);
    await selectMolliePaymentMethod(page);
    await continueToOrderReview(page);
    await expect(page).toHaveURL(/cl=order/);
    // Which Mollie flow rendered the button — recorded in the report so a run in either mode
    // (payment-base iframe flag on → inline Components, off → classic redirect) says what it proved.
    const classic = (await page.locator('[data-controller~="mollie-place-order"]').count()) > 0;
    test.info().annotations.push({ type: 'mollie-flow', description: classic ? 'classic redirect button' : 'inline Components button' });
}

test.describe('MOL-11 — AGB checkbox gates the Mollie "Order now" button (standard checkout)', () => {
    test('AGB on: inactive until ticked, inactive again when unticked, a forced click never submits', async ({ page }) => {
        const originalFlag = confirmAgbEnabled();
        setConfirmAgbEnabled(true);
        const executePosts: string[] = [];
        page.on('request', (request) => {
            if (request.method() === 'POST' && /fnc=execute/.test(request.url() + (request.postData() ?? ''))) {
                executePosts.push(request.url());
            }
        });
        try {
            await openMollieOrderStep(page);

            const agb = page.locator(AGB_CHECKBOX);
            await expect(agb, 'blConfirmAGB on must render the AGB checkbox').toHaveCount(1);
            await expect(agb).not.toBeChecked();
            const orderNow = page.getByRole('button', { name: ORDER_NOW }).first();
            await expect(orderNow).toBeVisible();

            await expect(orderNow, 'unticked AGB → button inactive').toBeDisabled();

            await orderNow.click({ force: true, trial: false }).catch(() => {});
            await page.waitForTimeout(500);
            expect(executePosts, 'a click on the inactive button must not POST fnc=execute').toEqual([]);
            expect(page.url()).toMatch(/cl=order/);

            await setAgbChecked(page, true);
            await expect(orderNow, 'ticked AGB → button active').toBeEnabled();

            await setAgbChecked(page, false);
            await expect(orderNow, 'unticked again → button inactive again').toBeDisabled();
        } finally {
            setConfirmAgbEnabled(originalFlag);
        }
    });

    test('AGB off: no checkbox, the button is active at once', async ({ page }) => {
        const originalFlag = confirmAgbEnabled();
        setConfirmAgbEnabled(false);
        try {
            await openMollieOrderStep(page);

            await expect(page.locator(AGB_CHECKBOX), 'blConfirmAGB off renders no AGB checkbox').toHaveCount(0);
            const orderNow = page.getByRole('button', { name: ORDER_NOW }).first();
            await expect(orderNow).toBeVisible();
            await expect(orderNow, 'no checkbox → nothing to gate on').toBeEnabled();
        } finally {
            setConfirmAgbEnabled(originalFlag);
        }
    });
});
