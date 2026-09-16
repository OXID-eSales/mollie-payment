import { test, expect, type Page, type TestInfo } from '@playwright/test';
import {
    loginStorefront,
    addFirstFeaturedProductToBasket,
    acceptTermsAndConditions,
} from '../../fixtures/shop-helpers';

/**
 * IFRAME-04 — a PENDING Mollie payment on return lands on thank-you, not an error.
 *
 * PayPal (and bank-style methods) can return "pending": the payment is initiated but not yet
 * confirmed; Mollie's webhook finalizes it later. Previously the return leg showed
 * MOLLIE_RETURN_RETURN_NOT_FINALISED (an error) for pending payments. Now a pending return lands on
 * the thank-you page with a "payment is being processed" notice.
 *
 * Needs automatic capture (so PayPal is offered inline); on a manual-capture shop PayPal is hidden
 * and the test skips.
 *
 * Run:
 *   MOLLIE_E2E_SHOP_URL=https://daniil.oxiddev.de \
 *     npx playwright test --project=mollie-standard PaypalPendingReturn --reporter=html
 */

async function shot(page: Page, testInfo: TestInfo, name: string): Promise<void> {
    const body = await page.screenshot({ fullPage: true });
    await testInfo.attach(name, { body, contentType: 'image/png' });
}

async function reachOrderWithMethodSelector(page: Page): Promise<void> {
    const radios = () => page.locator('input[name="mollieMethod"]');
    await page.goto('/index.php?cl=user&lang=1');
    await page.waitForLoadState('domcontentloaded');
    for (let step = 0; step < 8; step++) {
        await page.waitForTimeout(700);
        if (/cl=order/.test(page.url())) {
            if (await radios().count() > 0) {
                return;
            }
            await page.goto('/index.php?cl=order&lang=1');
            await page.waitForLoadState('domcontentloaded');
            await page.waitForTimeout(1500);
            if (await radios().count() > 0) {
                return;
            }
            continue;
        }
        const mollie = page.locator('input[type="radio"][value="oe_payments_mollie"]').first();
        if (await mollie.isVisible({ timeout: 1000 }).catch(() => false)) {
            await mollie.check({ force: true }).catch(() => {});
            await page.waitForTimeout(300);
        }
        const next = page.locator(
            'button.btn-highlight.btn-lg, button:has-text("Next"), button:has-text("Continue"), button:has-text("Weiter")'
        ).first();
        if (await next.isVisible({ timeout: 1000 }).catch(() => false)) {
            await next.click().catch(() => {});
            await page.waitForLoadState('networkidle', { timeout: 6000 }).catch(() => {});
        }
    }
    await expect(radios().first(), 'the Mollie method selector must be reached').toBeVisible({ timeout: 3000 });
}

test.describe('IFRAME-04 — Mollie pending payment lands on thank-you (not an error)', () => {
    test('PayPal → pending → thank-you page with a processing notice', async ({ page }, testInfo) => {
        await loginStorefront(page);
        await addFirstFeaturedProductToBasket(page);
        await reachOrderWithMethodSelector(page);

        const paypal = page.locator('input[name="mollieMethod"][value="paypal"]');
        if ((await paypal.count()) === 0) {
            test.skip(true, 'PayPal not offered (manual-capture shop) — pending-return path needs PayPal.');
            return;
        }

        await test.step('01 — pay with PayPal', async () => {
            await paypal.check();
            await acceptTermsAndConditions(page);
            await page.getByRole('button', { name: /order now|zahlungspflichtig bestellen|place order/i })
                .first().click();
            await expect(page, 'redirected to Mollie').toHaveURL(/mollie\.com\/checkout/i, { timeout: 45000 });
            await shot(page, testInfo, '01 — Mollie test-mode page for PayPal');
        });

        await test.step('02 — choose "Pending" on Mollie and continue', async () => {
            const pending = page.getByText('Pending', { exact: true });
            const open = page.getByText('Open', { exact: true });
            if (await pending.isVisible({ timeout: 8000 }).catch(() => false)) {
                await pending.click();
            } else if (await open.isVisible({ timeout: 3000 }).catch(() => false)) {
                await open.click();
            }
            await page.getByRole('button', { name: /continue|weiter/i }).first().click().catch(() => {});
        });

        await test.step('03 — returns to thank-you with a "processing" notice, NOT an error', async () => {
            const returned = await page.waitForURL(/cl=thankyou|thankyou/i, { timeout: 45000 })
                .then(() => true)
                .catch(() => false);
            expect(returned, 'a pending payment must land on the thank-you page').toBeTruthy();

            const body = await page.locator('body').innerText();
            expect(body, 'must NOT show the payment-failed / not-finalised error')
                .not.toMatch(/could not be completed|nicht abgeschlossen/i);
            expect(body, 'must show the payment-processing notice')
                .toMatch(/being processed|verarbeitet/i);
            await shot(page, testInfo, '03 — thank-you with payment-processing notice');
        });
    });
});
