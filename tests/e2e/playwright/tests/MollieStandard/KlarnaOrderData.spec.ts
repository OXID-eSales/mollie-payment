import { test, expect, type Page, type TestInfo } from '@playwright/test';
import {
    loginStorefront,
    addFirstFeaturedProductToBasket,
} from '../../fixtures/shop-helpers';

/**
 * MOLLIE-ORDERS-API — Klarna (pay-later) works inline via the Payments API + order data.
 *
 * Klarna needs a billing address + reconciled order lines on the create-payment. The module now sends
 * them (MollieOrderDataProvider + MollieLinesBuilder), so selecting Klarna creates a `method=klarna`
 * payment and redirects straight to Klarna's flow — instead of failing with MOLLIE_CHECKOUT_UNAVAILABLE.
 *
 * Klarna is capture-capable, so it is offered under both capture modes. Skips if Klarna isn't enabled
 * on the shop's Mollie profile.
 *
 * Run:
 *   MOLLIE_E2E_SHOP_URL=https://daniil.oxiddev.de \
 *     npx playwright test --project=mollie-standard KlarnaOrderData --reporter=html
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

test.describe('MOLLIE-ORDERS-API — Klarna inline via order data', () => {
    test('selecting Klarna redirects to its Mollie flow (no MOLLIE_CHECKOUT_UNAVAILABLE)', async ({ page }, testInfo) => {
        await loginStorefront(page);
        await addFirstFeaturedProductToBasket(page);
        await reachOrderWithMethodSelector(page);

        const klarna = page.locator('input[name="mollieMethod"][value="klarna"]');
        if ((await klarna.count()) === 0) {
            test.skip(true, 'Klarna not enabled on this Mollie profile.');
            return;
        }

        await test.step('01 — Klarna is offered inline', async () => {
            await expect(klarna, 'Klarna must be offered (order data now sent)').toHaveCount(1);
            await shot(page, testInfo, '01 — Klarna offered in the method selector');
        });

        await test.step('02 — select Klarna → redirect to the Klarna flow', async () => {
            await klarna.check();
            await page.getByRole('button', { name: /order now|zahlungspflichtig bestellen|place order/i })
                .first().click();

            await expect(page, 'must redirect to Mollie (not the unavailable error)')
                .toHaveURL(/mollie\.com\/checkout/i, { timeout: 45000 });
            expect(page.url(), 'the payment was created as method=klarna').toMatch(/method=klarna/i);
            expect(page.url(), 'not the generic method picker').not.toMatch(/select-method/i);
            await page.waitForLoadState('domcontentloaded').catch(() => {});
            await page.waitForTimeout(1500);
            await shot(page, testInfo, '02 — Mollie Klarna flow (method=klarna)');
        });
    });
});
