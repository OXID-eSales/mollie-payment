import { test, expect, type Page, type TestInfo } from '@playwright/test';
import { loginStorefront, addFirstFeaturedProductToBasket } from '../../fixtures/shop-helpers';

/**
 * Mollie standard order page (cl=order) in a FRESH session, with the payment-base skip flags on
 * and exactly one payment method (Mollie) and one delivery set offered to the customer — the
 * payment step then redirects straight to cl=order.
 *
 * Contract (mirrors what was pinned for the Stripe order page on 2026-09-18):
 *   - the payment card (#orderPayment, heading included) is NOT shown;
 *   - the shipping card IS shown: heading + carrier name, no #orderShipping form, no pencil;
 *   - in inline mode the Mollie block is on the page immediately — nothing is gated behind a
 *     button or the Terms tick;
 *   - one enabled Mollie method is named read-only (no "Choose your payment method" selector);
 *     two or more still render the selector;
 *   - no console errors, no page errors.
 *
 * Adaptive: when the payment step renders a selection (2+ methods or sets) the spec records that
 * and stops after checking the classic editable cards.
 */
async function shot(page: Page, testInfo: TestInfo, name: string): Promise<void> {
    await testInfo.attach(name, { body: await page.screenshot({ fullPage: true }), contentType: 'image/png' });
}

test.describe('Mollie order page — single method + single delivery set', () => {
    test('cards are informative, the Mollie block is immediate, one method is not a choice', async ({ page }, testInfo) => {
        const consoleErrors: string[] = [];
        const pageErrors: string[] = [];
        page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text()); });
        page.on('pageerror', (e) => pageErrors.push(String(e)));

        await loginStorefront(page);
        await addFirstFeaturedProductToBasket(page);
        await page.goto('/index.php?cl=payment&lang=1');
        await page.waitForLoadState('networkidle');

        const paymentStepSkipped = page.url().includes('cl=order');
        testInfo.annotations.push({ type: 'config', description: `paymentStepSkipped=${paymentStepSkipped} paymentRadios=${await page.locator('input[name=paymentid]').count()}` });
        if (!paymentStepSkipped) {
            const mollie = page.locator('input[name=paymentid][value=oe_payments_mollie]').first();
            if (await mollie.isVisible().catch(() => false)) await mollie.check({ force: true });
            await page.getByRole('button', { name: /weiter|continue|next/i }).first().click();
            await page.waitForLoadState('networkidle').catch(() => {});
            await expect(page.locator('#orderPayment'), 'selection was offered → editable payment card').toHaveCount(1);
            await shot(page, testInfo, 'editable cards (selection was offered)');
            return;
        }

        await test.step('cards: shipping read-only, no payment card', async () => {
            await expect(page.locator('h2.card-header, h4.card-header').filter({ hasText: /shipping method|versandart/i }).first(), 'shipping heading shown').toBeVisible();
            await expect(page.locator('#orderShipping'), 'no form posting back to cl=payment').toHaveCount(0);
            await expect(page.locator('#orderPayment'), 'payment form not shown').toHaveCount(0);
            await expect(page.locator('h2.card-header, h4.card-header').filter({ hasText: /payment method|zahlungsart/i }), 'payment heading not shown').toHaveCount(0);
        });

        const block = page.locator('[data-controller~="mollie-components"]');
        if (!(await block.count())) {
            testInfo.annotations.push({ type: 'mode', description: 'redirect (inline card disabled) — classic Order-now button' });
            await expect(page.getByRole('button', { name: /zahlungspflichtig bestellen|place order|order now/i })).toBeVisible();
            await shot(page, testInfo, 'redirect mode');
            return;
        }

        await test.step('inline mode: the Mollie block is on the page immediately, nothing gated', async () => {
            await expect(block, 'Mollie block visible on load').toBeVisible();
            const methods = page.locator('input[name=mollieMethod]');
            const count = await methods.count();
            testInfo.annotations.push({ type: 'methods', description: `${count}` });
            expect(count, 'at least one Mollie method').toBeGreaterThan(0);
            if (count === 1) {
                await expect(page.locator('.mollie-methods'), 'one method → no selector').toHaveCount(0);
                await expect(page.locator('[data-mollie-order-card="method"]'), 'one method → named read-only').toBeVisible();
                await expect(methods.first(), 'the hidden field still carries the method').toBeChecked();
            } else {
                await expect(page.locator('.mollie-methods'), '2+ methods → selector shown').toBeVisible();
            }
            if ((await methods.first().inputValue()) === 'creditcard') {
                await expect(page.locator('.mollie-card-fields iframe').first(), 'card Components mounted without any click').toBeAttached({ timeout: 20000 });
            }
            await expect(page.getByRole('button', { name: /zahlungspflichtig bestellen|place order|order now/i }), 'Order-now is the submit for Components').toBeVisible();
            await shot(page, testInfo, 'inline mode: read-only cards, immediate Mollie block');
        });

        await test.step('no console or page errors', async () => {
            expect(pageErrors).toEqual([]);
            expect(consoleErrors).toEqual([]);
        });
    });
});
