import { test, expect, Page } from '@playwright/test';
import { AdminLoginPage } from '../pages/admin/AdminLoginPage';
import { AdminOrdersPage } from '../pages/admin/AdminOrdersPage';
import { AdminMollieOrderPage } from '../pages/admin/AdminMollieOrderPage';

const ADMIN_USER = process.env.ADMIN_USER_EMAIL || 'noreply@oxid-esales.com';
const ADMIN_PW = process.env.ADMIN_USER_PASSWORD || 'admin';

/**
 * Robust admin login. The OXID admin renders in a frameset and (on a staging shop) can show a
 * "Start OXID eShop Admin" interstitial. Submit the login form if present, click the staging
 * button if present, then wait for the admin menu frame to actually exist.
 */
async function ensureAdminLoggedIn(login: AdminLoginPage, page: Page): Promise<void> {
    void login;
    await page.goto('/admin/');
    await page.waitForTimeout(1500);

    if (await page.locator('input[name="user"]').isVisible({ timeout: 8000 }).catch(() => false)) {
        await page.locator('input[name="user"]').fill(ADMIN_USER);
        await page.locator('input[name="pwd"]').fill(ADMIN_PW);
        await page.locator('input[type="submit"]').first().click();
        await page.waitForLoadState('domcontentloaded').catch(() => {});
        await page.waitForTimeout(2500);
    }

    const startBtn = page.locator('button:has-text("Start OXID eShop Admin")').first();
    if (await startBtn.isVisible({ timeout: 4000 }).catch(() => false)) {
        await startBtn.click();
        await page.waitForTimeout(2500);
    }

    for (let i = 0; i < 8; i++) {
        if (page.frame('adminnav') || page.frame('navigation')) return;
        await page.waitForTimeout(1500);
    }
}

/**
 * Mollie Admin — Capture & Cancel-authorization E2E.
 *
 * Exercises the two admin Payments-tab actions for a two-step (manual-capture) Mollie
 * payment sitting in the `authorized` state:
 *   - Capture (partial): releases funds; contract AUTHORIZED -> READY_TO_COMMIT.
 *   - Cancel  (full):     voids the authorization; contract -> CANCELLED.
 *
 * The target orders are injected by ORDER NUMBER via env (each must have a Mollie payment
 * in status `authorized` and its contract in state `authorized`):
 *   MOLLIE_CAPTURE_ORDER_NR — order used for the partial-capture action
 *   MOLLIE_CANCEL_ORDER_NR  — order used for the cancel-authorization action
 *
 * Navigation uses the shared admin frameset (login -> Orders -> select -> Payment tab); the
 * panel renders in the `edit` frame (see AdminMollieOrderPage).
 */

const CAPTURE_ORDER_NR = process.env.MOLLIE_CAPTURE_ORDER_NR || '';
const CANCEL_ORDER_NR = process.env.MOLLIE_CANCEL_ORDER_NR || '';

test.describe('Mollie admin: capture & cancel authorization', () => {
    test('Capture: partial capture of an authorized payment', async ({ page }) => {
        test.skip(!CAPTURE_ORDER_NR, 'MOLLIE_CAPTURE_ORDER_NR not set');
        test.setTimeout(150_000);
        page.on('dialog', (d) => d.accept().catch(() => {}));

        const login = new AdminLoginPage(page);
        const orders = new AdminOrdersPage(page);
        const mollie = new AdminMollieOrderPage(page);

        await ensureAdminLoggedIn(login, page);
        await orders.navigateToOrders();
        expect(await orders.selectOrderByNumber(CAPTURE_ORDER_NR)).toBeTruthy();
        await orders.openPaymentTab();
        await page.screenshot({ path: 'reports/capture-01-panel.png', fullPage: true });

        // Both action cards must be present for an authorized contract.
        expect(await mollie.isCaptureButtonVisible()).toBeTruthy();
        expect(await mollie.isCancelButtonVisible()).toBeTruthy();

        const capturable = await mollie.getCaptureableAmount();
        console.log(`  capturable bound: ${capturable}`);
        expect(capturable).toBeGreaterThan(0);

        // Partial capture: half (rounded to cents) so the remainder is released.
        const partial = Math.max(0.01, parseFloat((capturable / 2).toFixed(2)));
        console.log(`  capturing ${partial} of ${capturable}`);
        expect(await mollie.executeCapture(partial, 'E2E partial capture')).toBeTruthy();
        expect(await mollie.hasValidationError()).toBeFalsy();
        await page.screenshot({ path: 'reports/capture-02-after.png', fullPage: true });

        // Re-open the panel (fresh render) and assert on the source of truth: the captured
        // amount now reflects the partial capture, and the capture card is gone.
        await orders.openPaymentTab();
        const capturedNow = await mollie.getCapturedAmount();
        console.log(`  captured-amount now: ${capturedNow}`);
        expect(capturedNow).toBeGreaterThan(0);
        expect(await mollie.isCaptureButtonVisible()).toBeFalsy();
    });

    test('Cancel: void an authorized payment', async ({ page }) => {
        test.skip(!CANCEL_ORDER_NR, 'MOLLIE_CANCEL_ORDER_NR not set');
        test.setTimeout(150_000);
        page.on('dialog', (d) => d.accept().catch(() => {}));

        const login = new AdminLoginPage(page);
        const orders = new AdminOrdersPage(page);
        const mollie = new AdminMollieOrderPage(page);

        await ensureAdminLoggedIn(login, page);
        await orders.navigateToOrders();
        expect(await orders.selectOrderByNumber(CANCEL_ORDER_NR)).toBeTruthy();
        await orders.openPaymentTab();
        await page.screenshot({ path: 'reports/cancel-01-panel.png', fullPage: true });

        expect(await mollie.isCancelButtonVisible()).toBeTruthy();
        expect(await mollie.executeCancel('requested_by_customer')).toBeTruthy();
        expect(await mollie.hasValidationError()).toBeFalsy();
        await page.screenshot({ path: 'reports/cancel-02-after.png', fullPage: true });

        // Re-open the panel (fresh render); the authorization is voided so the contract is
        // CANCELLED and neither action card remains.
        await orders.openPaymentTab();
        expect(await mollie.isCancelButtonVisible()).toBeFalsy();
        expect(await mollie.isCaptureButtonVisible()).toBeFalsy();
    });
});
