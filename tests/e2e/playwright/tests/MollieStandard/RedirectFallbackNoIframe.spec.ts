import { test, expect, type Page, type TestInfo } from '@playwright/test';
import {
    loginStorefront,
    addFirstFeaturedProductToBasket,
    goToCheckoutPayment,
    selectMolliePaymentMethod,
    continueToOrderReview,
} from '../../fixtures/shop-helpers';

/**
 * IFRAME-03 — Mollie "graceful redirect fallback" walkthrough.
 *
 * DoD artifact for the 20260723 sprint's Mollie phase. The framing spike
 * (2026-07-29) proved Mollie's hosted checkout sends `X-Frame-Options: DENY`
 * + CSP `frame-ancestors 'self'`, so it CANNOT be embedded in an iframe. The
 * required outcome is therefore the "framing blocked" path: even with the
 * payment-base "Use iframe instead of checkout button" flag ON, Mollie must
 *   (a) show NO embedded / blank iframe container on the order-review page, and
 *   (b) cleanly redirect to its hosted checkout page.
 *
 * Each user-visible step attaches a full-page screenshot so the HTML report
 * reads as an illustrated walkthrough (the `mollie.png` proof).
 *
 * Run to produce the report:
 *   MOLLIE_E2E_SHOP_URL=https://daniil.oxiddev.de \
 *     npx playwright test --project=mollie-standard RedirectFallbackNoIframe --reporter=html
 */

// Any inline-embed container would be a BUG for Mollie — none of these may appear on its order page.
const EMBED_ANY =
    '.pc-embed, .stripe-embedded-checkout, #stripe-embedded-checkout, [data-order-submit-target="embedded"]';

const PLACE_ORDER = /zahlungspflichtig bestellen|place order|order now/i;

async function shot(page: Page, testInfo: TestInfo, name: string): Promise<void> {
    const body = await page.screenshot({ fullPage: true });
    await testInfo.attach(name, { body, contentType: 'image/png' });
}

test.describe('IFRAME-03 — Mollie graceful redirect fallback (no broken iframe)', () => {
    test('flag ON: Mollie shows no embed on the order page and redirects to its hosted checkout', async ({ page }, testInfo) => {
        await loginStorefront(page);
        await addFirstFeaturedProductToBasket(page);
        await goToCheckoutPayment(page);
        await selectMolliePaymentMethod(page);
        await continueToOrderReview(page);

        await test.step('01 — order-review page with Mollie selected: NO embedded/iframe container', async () => {
            // Core regression assertion: Mollie must NOT render an inline embed container
            // (its hosted page cannot be framed — X-Frame-Options: DENY / frame-ancestors 'self').
            await expect(
                page.locator(EMBED_ANY),
                'Mollie must not render an embedded checkout container',
            ).toHaveCount(0);
            await expect(
                page.getByRole('button', { name: PLACE_ORDER }).first(),
                'the redirect order button must be present',
            ).toBeVisible();
            await shot(page, testInfo, '01 — order-review, Mollie selected (no embed container)');
        });

        await test.step('02 — place order: browser redirects to Mollie hosted checkout', async () => {
            // "Place order" -> cl=order&fnc=execute -> MollieOrderController::execute() 302s to Mollie.
            await page.getByRole('button', { name: PLACE_ORDER }).first().click();

            await expect(page, 'must redirect to Mollie hosted checkout (mollie.com)')
                .toHaveURL(/mollie\.com\/checkout/i, { timeout: 45_000 });

            await page.waitForLoadState('domcontentloaded').catch(() => {});
            await page.waitForTimeout(1500); // let the hosted page paint for the screenshot
            await shot(page, testInfo, '02 — Mollie hosted checkout page (graceful redirect, matches mollie.png)');
        });
    });
});
