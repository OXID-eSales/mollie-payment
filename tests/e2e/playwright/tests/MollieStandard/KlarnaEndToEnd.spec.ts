import { test, expect, type Page, type TestInfo } from '@playwright/test';
import {
    loginStorefront,
    addFirstFeaturedProductToBasket,
    acceptTermsAndConditions,
} from '../../fixtures/shop-helpers';

/**
 * MOLLIE-ORDERS-API — Klarna (pay-later) FULL end-to-end walkthrough (with video).
 *
 * Selecting Klarna sends a billing address + reconciled order lines on the create-payment (via the
 * session basket/user), so Mollie accepts a `method=klarna` payment and hands off to Klarna's flow.
 * Here we drive it to completion: choose the "Paid" outcome on Mollie's test page → return on-site →
 * the order finalizes (thank-you), no MOLLIE_CHECKOUT_UNAVAILABLE.
 *
 * Needs Klarna enabled on the shop's Mollie profile. Records a video and a screenshot per step for the
 * HTML report.
 */
test.use({ video: 'on' });

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

test.describe('MOLLIE-ORDERS-API — Klarna full checkout (end to end)', () => {
    test('pay with Klarna → order finalizes', async ({ page }, testInfo) => {
        await loginStorefront(page);
        await addFirstFeaturedProductToBasket(page);
        await reachOrderWithMethodSelector(page);

        const klarna = page.locator('input[name="mollieMethod"][value="klarna"]');
        if ((await klarna.count()) === 0) {
            test.skip(true, 'Klarna not enabled on this Mollie profile.');
            return;
        }

        await test.step('01 — order page: choose Klarna from the Mollie method selector', async () => {
            await klarna.check();
            await shot(page, testInfo, '01 — Klarna selected in the method selector');
        });

        await test.step('02 — place order → redirect to Klarna via Mollie (order data accepted)', async () => {
            await acceptTermsAndConditions(page);
            await page.getByRole('button', { name: /order now|zahlungspflichtig bestellen|place order/i })
                .first().click();
            await expect(page, 'must redirect to Mollie, not the unavailable error')
                .toHaveURL(/mollie\.com\/checkout/i, { timeout: 45000 });
            expect(page.url(), 'payment created as method=klarna').toMatch(/method=klarna/i);
            await page.waitForLoadState('domcontentloaded').catch(() => {});
            await page.waitForTimeout(1500);
            await shot(page, testInfo, '02 — Mollie Klarna test page (method=klarna)');
        });

        await test.step('03 — choose "Paid" on Mollie and continue', async () => {
            const paid = page.getByText('Paid', { exact: true });
            if (await paid.isVisible({ timeout: 8000 }).catch(() => false)) {
                await paid.click();
            }
            await shot(page, testInfo, '03 — Klarna test outcome = Paid');
            await page.getByRole('button', { name: /continue|weiter/i }).first().click().catch(() => {});
        });

        await test.step('04 — return on-site → order finalized (thank-you)', async () => {
            const returned = await page.waitForURL(/cl=thankyou|thankyou|fnc=checkoutReturn/i, { timeout: 60000 })
                .then(() => true)
                .catch(() => false);
            expect(returned, 'must return to the shop').toBeTruthy();

            await page.waitForLoadState('domcontentloaded').catch(() => {});
            const body = await page.locator('body').innerText();
            expect(body, 'no checkout/return error').not.toMatch(/MOLLIE_CHECKOUT_UNAVAILABLE|could not be completed/i);
            expect(body, 'a finalized order / thank-you').toMatch(/thank you|vielen dank|order number|Nummer\s*\d+/i);
            await shot(page, testInfo, '04 — thank-you page (Klarna order finalized)');
        });
    });
});
