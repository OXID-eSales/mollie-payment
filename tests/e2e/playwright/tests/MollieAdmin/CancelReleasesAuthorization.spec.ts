import { test, expect, Page } from '@playwright/test';
import { AdminLoginPage } from '../pages/admin/AdminLoginPage';
import { checkoutAuthorizedByInlineCard } from '../../fixtures/mollie-inline-card';
import { allOrderIds, ordersAddedSince, shopDbQuery } from '../../fixtures/shop-db';

/**
 * Mollie admin Payment tab — capture for authorized-only holds, cancel full or partial.
 *
 * "Cancel authorization" is Mollie's release-authorization: what is still uncaptured goes back to
 * the customer. Before any capture that is the whole hold and the order is cancelled with it. After
 * a partial capture only the remainder is concerned — the captured amount stays booked, the order
 * stays live. A card without multi-capture (this test profile) releases its remainder by itself on
 * the first partial capture, exactly like Stripe; the panel must then stop offering capture/cancel
 * and never cancel the order. Needs the inline-Components flow, manual capture and the e2e shop user.
 */
async function openPanel(page: Page, orderOxid: string): Promise<void> {
    const login = new AdminLoginPage(page);
    await login.navigate();
    if (!(await login.isLoggedIn())) await login.login();
    const sid = page.url().match(/force_admin_sid=([^&]+)/)?.[1];
    const stoken = page.url().match(/stoken=([^&]+)/)?.[1];
    await page.goto(`/admin/index.php?cl=PaymentAdmin&fnc=dispatchAction&oxid=${orderOxid}&force_admin_sid=${sid}&stoken=${stoken}&shp=1`);
    await page.waitForLoadState('domcontentloaded');
}

function contractOf(orderOxid: string): { state: string; captured: string; metadata: string } {
    const [row] = shopDbQuery(
        `SELECT OXSTATE, IFNULL(OXCAPTUREDAMOUNT, ''), IFNULL(OXMETADATA, '') FROM oe_payments_contract WHERE OXORDERID = '${orderOxid}'`,
    );
    return { state: row?.[0] ?? '', captured: row?.[1] ?? '', metadata: row?.[2] ?? '' };
}

/** The shop mirrors a cancelled contract onto the order as OXTRANSSTATUS = CANCELLED (never storno). */
function orderTransStatus(orderOxid: string): string {
    return shopDbQuery(`SELECT OXTRANSSTATUS FROM oxorder WHERE OXID = '${orderOxid}'`)[0]?.[0] ?? '';
}

async function freshAuthorizedOrder(page: Page): Promise<string> {
    const baseline = allOrderIds();
    await checkoutAuthorizedByInlineCard(page);
    const [order] = ordersAddedSince(baseline);
    expect(order, 'the checkout created exactly one new order').toBeTruthy();
    return order.oxid;
}

test.describe('Mollie admin — cancel authorization releases the hold', () => {
    test('uncaptured hold: Cancel releases the whole authorization and cancels the order', async ({ page }) => {
        test.setTimeout(240_000);
        const orderOxid = await freshAuthorizedOrder(page);
        await openPanel(page, orderOxid);

        const captureForm = page.locator('[data-testid="mollie-capture-form"]');
        const cancelForm = page.locator('[data-testid="mollie-cancel-form"]');
        await expect(captureForm, 'an authorized hold offers a capture').toBeVisible({ timeout: 20_000 });
        await expect(cancelForm, 'an authorized hold offers a cancel').toBeVisible();
        const bound = (await page.locator('[data-testid="capture-bound"]').innerText()).trim();
        await expect(page.locator('[data-testid="cancel-release-amount"]'), 'cancel names the amount it releases').toHaveText(bound);
        await expect(page.locator('[data-testid="cancel-partial-note"]'), 'nothing captured → no partial note').toHaveCount(0);
        await expect(page.locator('[data-testid="capture-partial-hint"]')).toBeVisible();

        page.on('dialog', (d) => d.accept().catch(() => {}));
        await cancelForm.locator('[data-testid="cancel-reason-select"]').selectOption('requested_by_customer');
        await cancelForm.locator('[data-testid="cancel-submit"]').click();
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(1500);

        await expect(page.locator('[data-testid="mollie-validation-errors"]'), 'Mollie accepted the release').toHaveCount(0);
        await expect(page.locator('[data-testid="mollie-capture-form"]'), 'released → nothing to capture').toHaveCount(0);
        await expect(page.locator('[data-testid="mollie-cancel-form"]'), 'released → nothing to cancel').toHaveCount(0);
        const contract = contractOf(orderOxid);
        expect(contract.state, 'the contract is cancelled').toBe('cancelled');
        expect(orderTransStatus(orderOxid), 'the order is cancelled with it').toBe('CANCELLED');
    });

    test('partial capture: the remainder is released, the captured amount stays booked, the order stays live', async ({ page }) => {
        test.setTimeout(300_000);
        const orderOxid = await freshAuthorizedOrder(page);
        await openPanel(page, orderOxid);

        const captureForm = page.locator('[data-testid="mollie-capture-form"]');
        await expect(captureForm).toBeVisible({ timeout: 20_000 });
        const amountInput = captureForm.locator('[data-testid="capture-amount-input"]');
        const capturable = parseFloat(await amountInput.inputValue());
        expect(capturable).toBeGreaterThan(1.0);
        const partial = (capturable - 1.0).toFixed(2);

        page.on('dialog', (d) => d.accept().catch(() => {}));
        await amountInput.fill(partial);
        await captureForm.locator('[data-testid="capture-submit"]').click();
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(1500);

        await expect(page.locator('[data-testid="mollie-validation-errors"]'), 'the partial capture went through').toHaveCount(0);
        await expect(page.locator('[data-testid="captured-amount"]')).toContainText(partial);
        let contract = contractOf(orderOxid);
        expect(contract.state, 'a partial capture fulfils the contract for the captured part').toBe('fulfilled');
        expect(parseFloat(contract.captured)).toBeCloseTo(parseFloat(partial), 2);
        expect(orderTransStatus(orderOxid)).not.toBe('CANCELLED');

        const cancelForm = page.locator('[data-testid="mollie-cancel-form"]');
        if (await cancelForm.isVisible().catch(() => false)) {
            // Mollie still reports the hold: the panel offers to release exactly the remainder.
            await expect(page.locator('[data-testid="cancel-release-amount"]')).toContainText('1.00');
            await expect(page.locator('[data-testid="cancel-partial-note"]'), 'the operator is told the captured part stays').toBeVisible();
            await cancelForm.locator('[data-testid="cancel-submit"]').click();
            await page.waitForLoadState('domcontentloaded');
            await page.waitForTimeout(1500);
            const refused = await page.locator('[data-testid="mollie-validation-errors"]').count();
            if (refused > 0) {
                // The card released its remainder by itself in the meantime: the operator sees why.
                await expect(page.locator('[data-testid="mollie-validation-errors"]')).toContainText(/not released|nicht freigegeben/);
                console.log('RELEASE_REFUSED_BY_MOLLIE (remainder already released by the card method)');
            } else {
                await expect(page.locator('[data-testid="mollie-cancel-form"]')).toHaveCount(0);
                await expect(page.locator('[data-testid="mollie-capture-form"]')).toHaveCount(0);
                console.log('RELEASE_ACCEPTED_BY_MOLLIE');
            }
        } else {
            console.log('REMAINDER_RELEASED_BY_MOLLIE_ON_PARTIAL_CAPTURE (no Cancel offered)');
            await expect(page.locator('[data-testid="mollie-capture-form"]'), 'no hold left → nothing to capture').toHaveCount(0);
        }

        // Whatever released the remainder, a partial release never cancels anything the shop booked.
        contract = contractOf(orderOxid);
        expect(contract.state, 'the contract stays fulfilled').toBe('fulfilled');
        expect(parseFloat(contract.captured), 'the captured amount stays booked').toBeCloseTo(parseFloat(partial), 2);
        expect(orderTransStatus(orderOxid), 'the order stays live').not.toBe('CANCELLED');

        // Once Mollie reports the final state the panel offers neither capture nor cancel.
        await page.waitForTimeout(3000);
        await page.reload();
        await page.waitForLoadState('domcontentloaded');
        await expect(page.locator('[data-testid="captured-amount"]')).toContainText(partial);
        await expect(page.locator('[data-testid="mollie-capture-form"]')).toHaveCount(0);
        await expect(page.locator('[data-testid="mollie-cancel-form"]')).toHaveCount(0);
        await expect(page.locator('[data-testid="mollie-refund-form"]'), 'the captured part is refundable').toBeVisible();
    });
});
