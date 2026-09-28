import { test, expect, Locator } from '@playwright/test';
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
 * MOL-11 — the OPC Mollie footer behaves like the standard order step: its "Order now" is inactive
 * until the consents are ticked (`blConfirmAGB` on), active again only while they stay ticked, and
 * active without any consent when the shop renders none (`blConfirmAGB` off).
 *
 * Requires OPC on (`oeOnePageCheckoutEnabled: true`) and the iframe flag on (Mollie footer widget).
 * Each test sets `blConfirmAGB` through the DB and restores it in `finally`.
 */
const SUBMIT = '[data-mollie-checkout-footer-target="submitButton"]';
const TERMS = '#confirmTermsAccordion, #opcConfirmTerms, #confirmTermsCheckout, input[name="confirmTerms"]';

async function untick(box: Locator): Promise<void> {
    await box.evaluate((el) => {
        const cb = el as HTMLInputElement;
        cb.checked = false;
        cb.dispatchEvent(new Event('input', { bubbles: true }));
        cb.dispatchEvent(new Event('change', { bubbles: true }));
    });
}

test.describe('MOL-11 — consents gate the Mollie footer button (OPC)', () => {
    test('AGB on: inactive until the consents are ticked, inactive again when terms are unticked', async ({ page }) => {
        const originalFlag = confirmAgbEnabled();
        setConfirmAgbEnabled(true);
        try {
            await loginStorefront(page);
            await addFirstFeaturedProductToBasket(page);
            const modal = await openOpcCheckoutModal(page);
            const state = await chooseMollieInOpcModal(modal);
            test.skip(state === 'folded', OPC_FOLD_SKIP);

            const submit = modal.locator(SUBMIT).first();
            await expect(submit, 'the Mollie footer must render').toBeVisible({ timeout: 20_000 });
            const terms = modal.locator(TERMS).first();
            await expect(terms, 'blConfirmAGB on must render the terms consent').toHaveCount(1);

            await expect(submit, 'unticked consents → button inactive').toBeDisabled();
            await page.waitForTimeout(1500);
            await expect(submit, 'still inactive once the footer has settled').toBeDisabled();

            await tickOpcConsents(modal);
            await expect(submit, 'ticked consents → button active').toBeEnabled();

            await untick(terms);
            await expect(submit, 'terms unticked → button inactive again').toBeDisabled();
        } finally {
            setConfirmAgbEnabled(originalFlag);
        }
    });

    test('AGB off: no consent rendered, the button is active without ticking anything', async ({ page }) => {
        const originalFlag = confirmAgbEnabled();
        setConfirmAgbEnabled(false);
        try {
            await loginStorefront(page);
            await addFirstFeaturedProductToBasket(page);
            const modal = await openOpcCheckoutModal(page);
            const state = await chooseMollieInOpcModal(modal);
            test.skip(state === 'folded', OPC_FOLD_SKIP);

            const submit = modal.locator(SUBMIT).first();
            await expect(submit, 'the Mollie footer must render').toBeVisible({ timeout: 20_000 });
            await expect(modal.locator('#confirmTermsAccordion, #opcConfirmTerms'), 'blConfirmAGB off renders no terms consent').toHaveCount(0);
            await expect(submit, 'nothing to consent to → button active').toBeEnabled({ timeout: 20_000 });
        } finally {
            setConfirmAgbEnabled(originalFlag);
        }
    });
});
