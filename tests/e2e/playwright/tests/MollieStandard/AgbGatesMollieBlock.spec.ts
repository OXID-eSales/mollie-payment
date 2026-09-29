import { test, expect, Page, Locator } from '@playwright/test';
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
 * MOL-9 — on the standard order step the WHOLE Mollie block (payment-method radios and inline card
 * fields) is locked and dimmed until the AGB checkbox is ticked, not only "Order now" (MOL-11);
 * unticking locks it again. With `blConfirmAGB` off nothing is locked.
 *
 * Needs the inline-Components flow (payment-base iframe flag on) with at least two Mollie methods —
 * the classic redirect flow has no block to lock (its button is gated since MOL-11). Each test sets
 * `blConfirmAGB` through the DB and restores it in `finally`.
 */
const AGB_CHECKBOX = '#checkAgbTop';
const BLOCK = '[data-controller~="mollie-components"]';
const REGION = '[data-mollie-agb-gate-target="region"]';
const ORDER_NOW = /zahlungspflichtig bestellen|place order|order now/i;

async function openMollieOrderStep(page: Page): Promise<Locator> {
    await loginStorefront(page);
    await addFirstFeaturedProductToBasket(page);
    await goToCheckoutPayment(page);
    await selectMolliePaymentMethod(page);
    await continueToOrderReview(page);
    await expect(page).toHaveURL(/cl=order/);
    const block = page.locator(BLOCK);
    test.skip((await block.count()) === 0, 'classic redirect flow (iframe flag off) — no Mollie block to lock, the button gate is covered by AgbGatesOrderButton');
    const radios = block.locator('input[name="mollieMethod"]:not([hidden])');
    test.skip((await radios.count()) < 2, 'a single Mollie method renders no selector — nothing to click');
    return block;
}

/** Tries to pick the second method; answers whether the pick landed. */
async function tryPickSecondMethod(block: Locator): Promise<boolean> {
    const second = block.locator('input[name="mollieMethod"]:not([hidden])').nth(1);
    const label = block.locator(`label[for="${await second.getAttribute('id')}"]`);
    await label.click({ timeout: 2_000, force: false }).catch(() => undefined);
    return second.isChecked();
}

test.describe('MOL-9 — the AGB checkbox locks the whole Mollie block (standard checkout)', () => {
    test('AGB on: block inert and dimmed, a method cannot be picked; tick → unlocked; untick → locked again', async ({ page }) => {
        const originalFlag = confirmAgbEnabled();
        setConfirmAgbEnabled(true);
        try {
            const block = await openMollieOrderStep(page);
            const agb = page.locator(AGB_CHECKBOX);
            await expect(agb, 'blConfirmAGB on must render the AGB checkbox').toHaveCount(1);
            const region = block.locator(REGION);
            await expect(region, 'the method selector and card fields form one gated region').toHaveCount(1);

            await expect(region, 'unticked → the region is inert').toHaveAttribute('inert', '');
            await expect(region).toHaveClass(/mollie-agb-locked/);
            await expect(page.getByRole('button', { name: ORDER_NOW }).first()).toBeDisabled();
            expect(await tryPickSecondMethod(block), 'a click on a method must not land while locked').toBe(false);
            const opacity = await region.evaluate((el) => Number(getComputedStyle(el).opacity));
            expect(opacity, 'the region is dimmed').toBeLessThan(1);

            await setAgbChecked(page, true);
            await expect(region, 'ticked → the region is interactive').not.toHaveAttribute('inert', '');
            await expect(region).not.toHaveClass(/mollie-agb-locked/);
            await expect(page.getByRole('button', { name: ORDER_NOW }).first()).toBeEnabled();
            expect(await tryPickSecondMethod(block), 'a method can be picked once the AGB is ticked').toBe(true);

            await setAgbChecked(page, false);
            await expect(region, 'unticked again → locked again').toHaveAttribute('inert', '');
            await expect(page.getByRole('button', { name: ORDER_NOW }).first()).toBeDisabled();
        } finally {
            setConfirmAgbEnabled(originalFlag);
        }
    });

    test('AGB off: no checkbox, the block is interactive at once', async ({ page }) => {
        const originalFlag = confirmAgbEnabled();
        setConfirmAgbEnabled(false);
        try {
            const block = await openMollieOrderStep(page);
            await expect(page.locator(AGB_CHECKBOX)).toHaveCount(0);
            const region = block.locator(REGION);
            await expect(region).toHaveCount(1);
            await expect(region).not.toHaveAttribute('inert', '');
            await expect(page.getByRole('button', { name: ORDER_NOW }).first()).toBeEnabled();
            expect(await tryPickSecondMethod(block), 'nothing to agree to → methods can be picked').toBe(true);
        } finally {
            setConfirmAgbEnabled(originalFlag);
        }
    });
});
