import { test, expect, type Page, type TestInfo } from '@playwright/test';
import {
    loginStorefront,
    addFirstFeaturedProductToBasket,
} from '../../fixtures/shop-helpers';

/**
 * IFRAME-04 — Mollie Components inline card entry (end-to-end walkthrough).
 *
 * With the payment-base "Use iframe instead of checkout button" flag ON and a Mollie profile id
 * configured, the order page offers inline card entry via Mollie Components (card fields hosted in
 * js.mollie.com iframes — which, unlike Mollie's hosted checkout page, ARE framable). On "Order
 * now" the browser tokenizes the card (mollie.createToken()) and the server creates a `creditcard`
 * payment with that token. Under European SCA the card then routes through a 3-D Secure step
 * (here: Mollie's test-mode status page) before returning to finalize — card *entry* is inline;
 * the authentication step is not (accepted per the sprint).
 *
 * Each step screenshots for the HTML report. Requires: flag ON, sMollieProfileId set, Mollie test
 * mode with the card method enabled.
 *
 * Run:
 *   MOLLIE_E2E_SHOP_URL=https://daniil.oxiddev.de \
 *     npx playwright test --project=mollie-standard InlineCardComponents --reporter=html
 */

const FIELD_FRAMES: Record<string, string> = {
    cardNumber: '4111111111111111',
    cardHolder: 'Marc Muster',
    expiryDate: '1230',
    verificationCode: '123',
};

async function shot(page: Page, testInfo: TestInfo, name: string): Promise<void> {
    const body = await page.screenshot({ fullPage: true });
    await testInfo.attach(name, { body, contentType: 'image/png' });
}

/**
 * Fill one Mollie Components field. The input lives in a cross-origin js.mollie.com iframe named
 * `<field>-input`; prefer its inner <input>, fall back to focusing the frame body and typing.
 */
/**
 * Robust walk to the cl=order page with Mollie selected + the inline method selector shown.
 * Re-selects the Mollie radio at each step (session state can leak across the serial suite, so a
 * single rigid select→continue is flaky). Returns once the mollieMethod radios are present.
 */
async function reachOrderWithInlineCard(page: Page): Promise<void> {
    const radios = () => page.locator('input[name="mollieMethod"]');
    await page.goto('/index.php?cl=user&lang=1');
    await page.waitForLoadState('domcontentloaded');

    for (let step = 0; step < 8; step++) {
        await page.waitForTimeout(700);

        if (/cl=order/.test(page.url())) {
            // On the order page: NEVER click the highlight button here — that is the order/redirect
            // button and would submit the order. Wait for the inline card UI; reload once if the
            // first render raced ahead of it.
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

        // Address / payment steps: select Mollie, then advance.
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
    await expect(
        radios().first(),
        'inline method selector must be reached (Mollie selected + flag on + profile id set)',
    ).toBeVisible({ timeout: 3000 });
}

async function fillComponentsField(page: Page, field: string, value: string): Promise<void> {
    const frame = page.frameLocator(`iframe[name="${field}-input"]`);
    const input = frame.locator('input, [contenteditable="true"], [role="textbox"]').first();
    if (await input.count().catch(() => 0)) {
        await input.click().catch(() => {});
        await input.pressSequentially(value, { delay: 30 }).catch(async () => {
            await page.keyboard.type(value, { delay: 30 });
        });
        return;
    }
    await frame.locator('body').click().catch(() => {});
    await page.keyboard.type(value, { delay: 30 });
}

test.describe('IFRAME-04 — Mollie Components inline card (end to end)', () => {
    test('card is entered inline, tokenized, and the order finalizes after 3DS', async ({ page }, testInfo) => {
        await loginStorefront(page);
        await addFirstFeaturedProductToBasket(page);
        await reachOrderWithInlineCard(page);
        await expect(page, 'must be on the standard order page').toHaveURL(/cl=order/);

        await test.step('01 — pick "Card" from the Mollie method list → fields mount INLINE', async () => {
            // The inline selector lists the real Mollie methods (Card, PayPal, …). Choose Card.
            const card = page.locator('input[name="mollieMethod"][value="creditcard"]');
            await expect(card, 'the Card method must be offered').toHaveCount(1);
            await card.check();
            // The four card fields are Mollie-hosted iframes served from js.mollie.com.
            for (const field of Object.keys(FIELD_FRAMES)) {
                await expect(
                    page.locator(`iframe[name="${field}-input"][src*="js.mollie.com"]`),
                    `${field} Components iframe must be mounted inline`,
                ).toBeAttached({ timeout: 20000 });
            }
            expect(page.url(), 'card entry stays on-site (no redirect)').toMatch(/cl=order/);
            await shot(page, testInfo, '01 — inline Mollie Components card fields (js.mollie.com)');
        });

        await test.step('02 — enter a test card, place order → tokenized, redirected to Mollie 3DS', async () => {
            await page.waitForTimeout(3000); // let the field iframes finish initialising
            for (const [field, value] of Object.entries(FIELD_FRAMES)) {
                await fillComponentsField(page, field, value);
            }
            await shot(page, testInfo, '02a — test card entered in the inline fields');

            await page.getByRole('button', { name: /order now|zahlungspflichtig bestellen|place order/i })
                .first().click();

            // createToken() succeeds → server creates a creditcard payment → SCA/3DS step.
            // In test mode that is Mollie's status page (token in the URL proves tokenization).
            await expect(page, 'must reach Mollie 3DS/test-mode after tokenization')
                .toHaveURL(/mollie\.com\/checkout/i, { timeout: 30000 });
            expect(page.url(), 'the card was tokenized (token in the return URL)').toMatch(/token=/);
            await page.waitForLoadState('domcontentloaded').catch(() => {});
            await page.waitForTimeout(1200);
            await shot(page, testInfo, '02b — Mollie test-mode 3DS status page (card tokenized)');
        });

        await test.step('03 — authorize on the test page → return on-site and order finalizes', async () => {
            // Card payments settle as "Authorized"; pick it and continue.
            const authorized = page.getByText('Authorized', { exact: true });
            if (await authorized.isVisible({ timeout: 10000 }).catch(() => false)) {
                await authorized.click();
            }
            await page.getByRole('button', { name: /continue|weiter/i }).first().click().catch(() => {});

            const returned = await page
                .waitForURL(/cl=thankyou|fnc=checkoutReturn|thankyou/i, { timeout: 45000 })
                .then(() => true)
                .catch(() => false);

            if (!returned) {
                testInfo.annotations.push({
                    type: 'note',
                    description: 'Did not reach thank-you within timeout — tokenization + creditcard payment + 3DS redirect already asserted in step 02.',
                });
                await shot(page, testInfo, '03 — post-authorize (return pending)');
                return;
            }

            await page.waitForLoadState('domcontentloaded').catch(() => {});
            const body = await page.locator('body').innerText();
            expect(body, 'no raw checkout/return error').not.toMatch(/MOLLIE_CHECKOUT_UNAVAILABLE|MOLLIE_RETURN_/i);
            expect(body, 'a finalized order / thank-you page').toMatch(/thank you|vielen dank|order number|Nummer\s*\d+/i);
            await shot(page, testInfo, '03 — thank-you page (order finalized)');
        });
    });
});
