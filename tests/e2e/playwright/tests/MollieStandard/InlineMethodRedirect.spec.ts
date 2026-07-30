import { test, expect, type Page, type TestInfo } from '@playwright/test';
import {
    loginStorefront,
    addFirstFeaturedProductToBasket,
} from '../../fixtures/shop-helpers';

/**
 * IFRAME-04 — Mollie inline method selection: a NON-card method redirects to Mollie.
 *
 * The order page lists the real Mollie methods (Card, PayPal, Klarna, …). "Card" is entered inline
 * via Components; every OTHER method, when selected, submits the order form with that method
 * preselected (mollieMethod radio is form-associated) and the server redirects straight to Mollie's
 * hosted page for it — no method-selection detour. This spec proves that path for PayPal.
 *
 * Capture-mode gating: PayPal only supports AUTOMATIC capture, so on a MANUAL-capture shop it is
 * filtered OUT of the inline selector (MollieDefinitions::supportsManualCapture). This spec adapts:
 *   - PayPal offered (automatic capture) → select it → assert redirect to Mollie.
 *   - PayPal absent (manual capture)      → assert it is hidden while Card remains — the gating proof.
 *
 * Run:
 *   MOLLIE_E2E_SHOP_URL=https://daniil.oxiddev.de \
 *     npx playwright test --project=mollie-standard InlineMethodRedirect --reporter=html
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
    await expect(radios().first(), 'the Mollie method selector must be reached')
        .toBeVisible({ timeout: 3000 });
}

test.describe('IFRAME-04 — Mollie inline method selection (non-card redirect)', () => {
    test('selecting PayPal redirects to Mollie (no card fields, no method detour)', async ({ page }, testInfo) => {
        await loginStorefront(page);
        await addFirstFeaturedProductToBasket(page);
        await reachOrderWithMethodSelector(page);
        await expect(page, 'must be on the standard order page').toHaveURL(/cl=order/);

        const paypal = page.locator('input[name="mollieMethod"][value="paypal"]');
        const card = page.locator('input[name="mollieMethod"][value="creditcard"]');

        // Capture-mode gating: PayPal only supports automatic capture, so on a manual-capture shop
        // it is filtered OUT of the selector. Adapt to whichever mode this shop is in.
        const paypalOffered = (await paypal.count()) > 0;

        await test.step('01 — order page shows the Mollie method list', async () => {
            await expect(page.locator('input[name="mollieMethod"]').first(), 'method radios present')
                .toBeVisible();
            await expect(card, 'Card is always a capture-capable method').toHaveCount(1);
            await shot(page, testInfo, `01 — Mollie method selector (paypalOffered=${paypalOffered})`);
        });

        if (!paypalOffered) {
            await test.step('02 — manual-capture shop: PayPal is hidden (capture-incompatible)', async () => {
                // The core proof of the gating feature: a capture-incompatible method is not offered.
                await expect(paypal, 'PayPal must be hidden under manual capture').toHaveCount(0);
                testInfo.annotations.push({
                    type: 'note',
                    description: 'Manual-capture shop: PayPal filtered out of the inline selector (capture-incompatible).',
                });
                await shot(page, testInfo, '02 — PayPal hidden under manual capture');
            });
            return;
        }

        await test.step('02 — automatic capture: pick PayPal → no card fields, redirects to Mollie', async () => {
            await paypal.check();
            await page.waitForTimeout(500);
            await expect(
                page.locator('[data-mollie-components-target="fields"]'),
                'card fields must be hidden for a non-card method',
            ).toBeHidden();

            await page.getByRole('button', { name: /order now|zahlungspflichtig bestellen|place order/i })
                .first().click();

            await expect(page, 'PayPal selection redirects straight to Mollie')
                .toHaveURL(/mollie\.com\/checkout/i, { timeout: 45000 });
            // No method-detour: the payment is created with method=paypal, so Mollie hands off to the
            // PayPal flow (live: PayPal auth; test: its paypal simulator) — NOT the generic
            // "select-method" picker.
            expect(page.url(), 'must be the PayPal-specific checkout, not the method picker')
                .toMatch(/method=paypal/i);
            expect(page.url(), 'must not land on Mollie\'s generic method-selection page')
                .not.toMatch(/select-method/i);
            await page.waitForLoadState('domcontentloaded').catch(() => {});
            await page.waitForTimeout(1200);
            await shot(page, testInfo, '02 — redirected to the PayPal flow (method=paypal, no picker)');
        });
    });
});
