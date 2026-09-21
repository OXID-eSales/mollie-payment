import { test, expect, type Page, type TestInfo } from '@playwright/test';
import { loginStorefront, addFirstFeaturedProductToBasket, openOpcCheckoutModal, opcPaymentSectionFolded, OPC_FOLD_SKIP, waitForOpcPaymentState } from '../../fixtures/shop-helpers';

/**
 * IFRAME (OPC) — Mollie renders its OWN inline footer widget in the buy-now modal.
 *
 * With the payment-base "Use iframe instead of checkout button" flag ON, selecting Mollie in the OPC
 * modal loads the Mollie footer widget (inline method selector + Mollie Components card) instead of
 * the generic redirect button — the OPC parity of the classic order page's IFRAME-04 experience.
 * Picking a redirect method and submitting still hands off to Mollie's hosted page (its page cannot
 * be framed); only the method/card entry happens inline.
 *
 * This spec is also the walkthrough (video + a screenshot per step). It hard-asserts the inline
 * widget + method selector render (the behaviour under test) and does a best-effort redirect check.
 * Run:
 *   SHOP_URL=https://daniil.oxiddev.de PW_SCREENSHOT=on \
 *     npx playwright test tests/MollieOpc/InlineFooterWidget.spec.ts --project=mollie-opc
 */
test.use({ video: 'on' });

const FOOTER = '[data-controller~="mollie-checkout-footer"]';
const METHOD_RADIO = 'input[name="mollieMethod"]';

async function shot(page: Page, testInfo: TestInfo, name: string): Promise<void> {
    const body = await page.screenshot({ fullPage: true }).catch(() => null);
    if (body) {
        await testInfo.attach(name, { body, contentType: 'image/png' });
    }
}

async function openOpcModalWithMollie(page: Page): Promise<import('@playwright/test').Locator> {
    await loginStorefront(page);
    await addFirstFeaturedProductToBasket(page);

    const modal = await openOpcCheckoutModal(page);

    // Single method: OPC folds the section and disables the select; Mollie is auto-assigned.
    // The visible-footer assertions below cannot hold then — see OPC_FOLD_SKIP.
    test.skip((await waitForOpcPaymentState(modal)) === 'folded', OPC_FOLD_SKIP);
    const select = modal.locator('#paymentMethodSelect');
    await select.selectOption('oe_payments_mollie', { force: true });
    await select.dispatchEvent('change');
    await page.waitForTimeout(2000); // AJAX footer-widget swap
    return modal;
}

test.describe('IFRAME (OPC) — Mollie inline footer widget in the buy-now modal', () => {
    test('selecting Mollie renders the inline method selector widget (not the redirect button)', async ({ page }, testInfo) => {
        const modal = await openOpcModalWithMollie(page);

        await test.step('01 — Mollie footer widget is loaded into the OPC modal', async () => {
            await expect(modal.locator(FOOTER).first(), 'the Mollie inline footer widget must render')
                .toBeVisible({ timeout: 15_000 });
            await shot(page, testInfo, '01 — Mollie inline footer widget loaded');
        });

        await test.step('02 — the inline method selector offers Mollie methods', async () => {
            await expect(modal.locator(METHOD_RADIO).first(), 'a Mollie method selector must render')
                .toBeVisible({ timeout: 10_000 });
            const count = await modal.locator(METHOD_RADIO).count();
            expect(count, 'at least one Mollie method offered inline').toBeGreaterThan(0);
            await shot(page, testInfo, '02 — inline Mollie method selector');
        });

        await test.step('03 — best-effort: pick a redirect method + submit hands off to Mollie', async () => {
            // Prefer a non-card method (card would open Components + need a token).
            const nonCard = modal.locator(`${METHOD_RADIO}:not([value="creditcard"])`).first();
            const target = (await nonCard.count()) ? nonCard : modal.locator(METHOD_RADIO).first();
            await target.check({ force: true }).catch(() => {});

            for (const id of ['#confirmTermsCheckout', '#confirmPrivacyCheckout']) {
                const cb = modal.locator(id);
                if (await cb.count()) {
                    await cb.check({ force: true }).catch(() => {});
                }
            }

            const submit = modal.locator(`${FOOTER} [data-mollie-checkout-footer-target="submitButton"]`).first();
            await expect(submit, 'the widget provides its own submit button').toBeVisible();
            await shot(page, testInfo, '03 — method chosen, ready to submit');

            await submit.click().catch(() => {});
            const handedOff = await page
                .waitForURL(/mollie\.com|cl=thankyou|fnc=checkoutReturn/i, { timeout: 45_000 })
                .then(() => true)
                .catch(() => false);
            // Best-effort: the environment may not have live Mollie keys. The hard assertions above
            // (widget + selector render) are the behaviour under test; log the handoff result.
            console.log('[mollie-opc] post-submit url:', page.url(), '| handedOff:', handedOff);
            await shot(page, testInfo, '04 — after submit');
        });
    });
});
