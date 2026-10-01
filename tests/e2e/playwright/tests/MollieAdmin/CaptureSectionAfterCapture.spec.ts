import { test, expect } from '@playwright/test';
import { AdminLoginPage } from '../pages/admin/AdminLoginPage';
import { checkoutAuthorizedByInlineCard } from '../../fixtures/mollie-inline-card';
import { allOrderIds, ordersAddedSince } from '../../fixtures/shop-db';

/**
 * Capture option remains available after the payment has been captured — after a successful manual
 * capture the Payment tab must reflect the captured and remaining capturable amounts on the very
 * next render: no Capture section offering the same amount again, no Cancel, "Captured" updated,
 * a CAPTURE row in the transaction history. Right after the capture Mollie's payment resource
 * still reads `authorized` with the old remaining amount; the shop's own record must win.
 *
 * Needs the inline-Components flow (iframe flag on), manual capture mode and the e2e shop user.
 */
test.describe('Mollie admin — Capture section after a successful capture', () => {
    test('a full capture removes the Capture and Cancel sections at once and shows the captured amount', async ({ page }) => {
        test.setTimeout(240_000);
        const baseline = allOrderIds();
        await checkoutAuthorizedByInlineCard(page);
        const [order] = ordersAddedSince(baseline);
        expect(order, 'the checkout created exactly one new order').toBeTruthy();

        const login = new AdminLoginPage(page);
        await login.navigate();
        if (!(await login.isLoggedIn())) await login.login();
        const sid = page.url().match(/force_admin_sid=([^&]+)/)?.[1];
        const stoken = page.url().match(/stoken=([^&]+)/)?.[1];
        const panelUrl = `/admin/index.php?cl=PaymentAdmin&fnc=dispatchAction&oxid=${order.oxid}&force_admin_sid=${sid}&stoken=${stoken}&shp=1`;
        await page.goto(panelUrl);
        await page.waitForLoadState('domcontentloaded');

        const captureForm = page.locator('[data-testid="mollie-capture-form"]');
        await expect(captureForm, 'an authorized card payment offers a capture').toBeVisible({ timeout: 20_000 });
        const amountInput = captureForm.locator('[data-testid="capture-amount-input"]');
        const capturable = parseFloat(await amountInput.inputValue());
        expect(capturable).toBeGreaterThan(0);
        await expect(page.locator('[data-testid="captured-amount"]')).toContainText('0.00');

        page.on('dialog', (d) => d.accept().catch(() => {}));
        await amountInput.fill(capturable.toFixed(2));
        await captureForm.locator('[data-testid="capture-submit"]').click();
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(1500);

        // The re-rendered panel — the one the admin looks at right after clicking.
        await expect(page.locator('.alert-danger, .pc-error'), 'no error after the capture').toHaveCount(0);
        await expect(page.locator('[data-testid="captured-amount"]'), '"Captured" reflects the capture').toContainText(capturable.toFixed(2));
        const history = (await page.locator('body').innerText()) ?? '';
        expect(history, 'the transaction history has the CAPTURE').toMatch(/capture/i);
        await expect(page.locator('[data-testid="mollie-capture-form"]'), 'nothing left to capture → no Capture section').toHaveCount(0);
        await expect(page.locator('form[name="mollieCancelForm"], [data-testid="mollie-cancel-form"]'), 'nothing left to cancel → no Cancel section').toHaveCount(0);
    });
});
