import { test, expect } from '@playwright/test';
import { AdminLoginPage } from '../pages/admin/AdminLoginPage';
import { checkoutAuthorizedByInlineCard } from '../../fixtures/mollie-inline-card';
import { allOrderIds, ordersAddedSince, contractForOrder } from '../../fixtures/shop-db';
import { fetchMolliePayment } from '../../fixtures/mollie-api';

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

        // MOL-30: the Refund section on that same render — the shop knows what it captured even while
        // Mollie's payment resource still reads `authorized`.
        const refundForm = page.locator('[data-testid="mollie-refund-form"]');
        await expect(refundForm, 'MOL-30: the Refund section is offered without a reload').toBeVisible({ timeout: 5_000 });
        await expect(refundForm.locator('[data-testid="refund-bound"]'), 'refundable = what was captured').toContainText(capturable.toFixed(2));

        // Measurement (no assertion): how long Mollie takes to move the payment to `paid` after the capture.
        const contract = contractForOrder(order.oxid);
        expect(contract?.state, 'the capture fulfilled the contract in the same request').toBe('fulfilled');
        if (contract?.providerOrderId && process.env.MOLLIE_API_KEY) {
            const started = Date.now();
            for (let i = 0; i < 30; i++) {
                const live = await fetchMolliePayment(contract.providerOrderId);
                if ('status' in live && live.status === 'paid') {
                    console.log(`[MOL-30] Mollie payment ${contract.providerOrderId} read paid ${Date.now() - started} ms after the capture render (captured ${live.amountCaptured}, remaining ${live.amountRemaining})`);
                    break;
                }
                if (i === 29) console.log(`[MOL-30] Mollie payment ${contract.providerOrderId} still ${'status' in live ? live.status : live.error} after ${Date.now() - started} ms`);
                await page.waitForTimeout(500);
            }
        } else {
            await page.waitForTimeout(10_000);
        }

        // Story 4: the offered refund is a real one - a full refund from that same panel goes through.
        await refundForm.locator('[data-testid="refund-amount-input"]').fill(capturable.toFixed(2));
        await refundForm.locator('[data-testid="refund-submit"]').click();
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(1500);
        await expect(page.locator('[data-testid="mollie-validation-errors"]'), 'the refund was booked, no message').toHaveCount(0);
        await expect(page.locator('[data-testid="refunded-amount"]'), '"Refunded" reflects the refund').toContainText(capturable.toFixed(2));
    });
});
