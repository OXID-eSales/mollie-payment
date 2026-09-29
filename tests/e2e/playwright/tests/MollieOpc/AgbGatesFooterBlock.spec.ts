import { test, expect } from '@playwright/test';
import {
    loginStorefront,
    addFirstFeaturedProductToBasket,
    openOpcCheckoutModal,
    chooseMollieInOpcModal,
    OPC_FOLD_SKIP,
    tickOpcConsents,
} from '../../fixtures/shop-helpers';
import { confirmAgbEnabled, setConfirmAgbEnabled } from '../../fixtures/shop-db';

/**
 * MOL-9 — in the one-page checkout the WHOLE Mollie footer (method radios, card fields, submit) is
 * unreachable until the consents are ticked: OPC's section lock sets `inert` on the payment-execution
 * body, and the Mollie footer renders inside it. Pinned here for Mollie, like the button gate (MOL-11).
 *
 * Requires OPC on and the iframe flag on. Sets `blConfirmAGB` through the DB and restores it.
 */
const FOOTER = '[data-controller~="mollie-checkout-footer"]';

test.describe('MOL-9 — consents lock the whole Mollie footer (OPC)', () => {
    test('AGB on: footer body inert until the consents are ticked', async ({ page }) => {
        const originalFlag = confirmAgbEnabled();
        setConfirmAgbEnabled(true);
        try {
            await loginStorefront(page);
            await addFirstFeaturedProductToBasket(page);
            const modal = await openOpcCheckoutModal(page);
            const state = await chooseMollieInOpcModal(modal);
            test.skip(state === 'folded', OPC_FOLD_SKIP);
            const footer = modal.locator(FOOTER);
            await expect(footer, 'the Mollie footer must render').toBeVisible({ timeout: 20_000 });
            await page.waitForTimeout(1500);

            const lockedAncestor = footer.locator('xpath=ancestor::*[@inert][1]');
            await expect(lockedAncestor, 'unticked consents → the footer sits inside an inert body').toHaveCount(1);
            const radio = footer.locator('input[name="mollieMethod"]:not([hidden])').nth(1);
            if (await radio.count()) {
                const id = await radio.getAttribute('id');
                await footer.locator(`label[for="${id}"]`).click({ timeout: 2_000 }).catch(() => undefined);
                expect(await radio.isChecked(), 'a method click must not land while locked').toBe(false);
            }

            await tickOpcConsents(modal);
            await expect(lockedAncestor, 'ticked consents → no inert ancestor left').toHaveCount(0);
        } finally {
            setConfirmAgbEnabled(originalFlag);
        }
    });
});
