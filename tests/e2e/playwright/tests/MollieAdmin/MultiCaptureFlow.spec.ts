import { test, expect } from '@playwright/test';
import { AdminLoginPage } from '../pages/admin/AdminLoginPage';
import { checkoutAuthorizedByInlineCard } from '../../fixtures/mollie-inline-card';
import { allOrderIds, ordersAddedSince } from '../../fixtures/shop-db';

/**
 * MOL-32 — multi-capture end to end, on a Mollie account that has multiple captures enabled and the
 * module setting `blMollieMultiCapture` on.
 *
 * This REQUIRES a multi-capture-enabled profile (cards/PayPal: enabled by Mollie on request; or a
 * Klarna/Billie method, which multi-capture by default) AND the setting on. Mollie exposes no API
 * flag for the account capability, so there is nothing to auto-detect — the run is opted in with
 * MOLLIE_E2E_MULTICAPTURE=1; otherwise it skips (never false-fails) because a single-capture account
 * makes the first partial capture final by design (MOL-31 option B), which these assertions would
 * wrongly read as a regression.
 */
const MULTI_CAPTURE_READY = process.env.MOLLIE_E2E_MULTICAPTURE === '1';

test.describe('Mollie admin — multi-capture (MOL-32)', () => {
    test.skip(!MULTI_CAPTURE_READY, 'needs a multi-capture-enabled Mollie account + blMollieMultiCapture on (set MOLLIE_E2E_MULTICAPTURE=1)');

    test('an authorization is captured in two parts and the order is paid only when fully captured', async ({ page }) => {
        test.setTimeout(300_000);
        const baseline = allOrderIds();
        await checkoutAuthorizedByInlineCard(page);
        const [order] = ordersAddedSince(baseline);
        expect(order, 'the checkout created exactly one new order').toBeTruthy();

        await openPaymentTab(page, order.oxid);

        // First partial capture: 40% of the capturable amount.
        const firstCapturable = await capturableAmount(page);
        const firstAmount = round2(firstCapturable * 0.4);
        await submitCapture(page, firstAmount);

        // The order stays open: capture section still present, captured reflects the first part,
        // and the remainder shrank — the order is NOT yet paid in full.
        await expect(page.locator('[data-testid="captured-amount"]')).toContainText(firstAmount.toFixed(2));
        await expect(page.locator('[data-testid="mollie-capture-form"]'), 'more is still capturable').toBeVisible();
        const remainder = await capturableAmount(page);
        expect(remainder).toBeCloseTo(round2(firstCapturable - firstAmount), 2);

        // Second capture completes the authorization → the order is now settled in full.
        await submitCapture(page, remainder);
        await expect(page.locator('[data-testid="captured-amount"]')).toContainText(firstCapturable.toFixed(2));
        await expect(page.locator('[data-testid="mollie-capture-form"]'), 'nothing left to capture').toHaveCount(0);
        await expect(page.locator('[data-testid="mollie-refund-form"]'), 'the settled order is refundable').toBeVisible();
    });

    test('a partial capture then a release returns the remainder and shows it', async ({ page }) => {
        test.setTimeout(300_000);
        const baseline = allOrderIds();
        await checkoutAuthorizedByInlineCard(page);
        const [order] = ordersAddedSince(baseline);
        expect(order).toBeTruthy();

        await openPaymentTab(page, order.oxid);

        const capturable = await capturableAmount(page);
        const captured = round2(capturable * 0.5);
        await submitCapture(page, captured);

        // With a remainder authorized, Release is offered and Cancel is not.
        const releaseForm = page.locator('[data-testid="mollie-release-form"]');
        await expect(releaseForm, 'release is offered once something is captured').toBeVisible();
        await expect(page.locator('[data-testid="mollie-cancel-form"]'), 'cancel is not offered after a capture').toHaveCount(0);

        page.on('dialog', (d) => d.accept().catch(() => {}));
        await releaseForm.locator('[data-testid="release-submit"]').click();
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(1500);

        // The remainder is reported as released and the order is settled with the captured amount.
        await expect(page.locator('[data-testid="released-amount"]')).toContainText(round2(capturable - captured).toFixed(2));
        await expect(page.locator('[data-testid="captured-amount"]')).toContainText(captured.toFixed(2));
        await expect(page.locator('[data-testid="mollie-capture-form"]'), 'no capturable remainder left').toHaveCount(0);
    });
});

async function openPaymentTab(page: import('@playwright/test').Page, oxid: string): Promise<void> {
    const login = new AdminLoginPage(page);
    await login.navigate();
    if (!(await login.isLoggedIn())) await login.login();
    const sid = page.url().match(/force_admin_sid=([^&]+)/)?.[1];
    const stoken = page.url().match(/stoken=([^&]+)/)?.[1];
    await page.goto(`/admin/index.php?cl=PaymentAdmin&fnc=dispatchAction&oxid=${oxid}&force_admin_sid=${sid}&stoken=${stoken}&shp=1`);
    await page.waitForLoadState('domcontentloaded');
}

async function capturableAmount(page: import('@playwright/test').Page): Promise<number> {
    const input = page.locator('[data-testid="mollie-capture-form"] [data-testid="capture-amount-input"]');
    await expect(input).toBeVisible({ timeout: 20_000 });
    return parseFloat(await input.inputValue());
}

async function submitCapture(page: import('@playwright/test').Page, amount: number): Promise<void> {
    const form = page.locator('[data-testid="mollie-capture-form"]');
    page.on('dialog', (d) => d.accept().catch(() => {}));
    await form.locator('[data-testid="capture-amount-input"]').fill(amount.toFixed(2));
    await form.locator('[data-testid="capture-submit"]').click();
    await page.waitForLoadState('domcontentloaded');
    await page.waitForTimeout(1500);
}

function round2(value: number): number {
    return Math.round(value * 100) / 100;
}
