import { test } from '@playwright/test';
import { writeFileSync } from 'node:fs';
import { AdminLoginPage } from '../pages/admin/AdminLoginPage';
import { AdminOrdersPage } from '../pages/admin/AdminOrdersPage';
import { AdminMollieOrderPage } from '../pages/admin/AdminMollieOrderPage';
import { shopDbQuery } from '../../fixtures/shop-db';

/**
 * DIAGNOSTIC companion of RefundAvailabilityMatrix: for the order numbers in INSPECT_ORDERS, open each in
 * admin and record which Mollie panel forms render, next to the contract state in the DB. Writes JSON to
 * INSPECT_OUT. Asserts nothing.
 */
const ORDERS = (process.env.INSPECT_ORDERS || '').split(',').map((s) => s.trim()).filter(Boolean);
const OUT = process.env.INSPECT_OUT || '/tmp/refund-admin.json';
const ADMIN_USER = process.env.ADMIN_USER_EMAIL || 'noreply@oxid-esales.com';
const ADMIN_PW = process.env.ADMIN_USER_PASSWORD || 'admin';

test('DIAGNOSTIC — admin Payment tab per order', async ({ page }) => {
    test.setTimeout(15 * 60_000);
    const adminLogin = new AdminLoginPage(page);
    const ordersPage = new AdminOrdersPage(page);
    const molliePage = new AdminMollieOrderPage(page);
    await adminLogin.navigate();
    if (!(await adminLogin.isLoggedIn())) {
        await adminLogin.login({ email: ADMIN_USER, password: ADMIN_PW });
    }
    const results: Record<string, unknown>[] = [];
    for (const nr of ORDERS) {
        const row: Record<string, unknown> = { nr, state: contractState(nr) };
        try {
            await ordersPage.navigateToOrders();
            row.found = await ordersPage.selectOrderByNumber(nr);
            if (row.found) {
                await ordersPage.openPaymentTab().catch((e: Error) => { row.paymentTabError = e.message.slice(0, 80); });
                await molliePage.waitForContentLoaded().catch(() => {});
                const frame = molliePage.getEditFrame();
                const vis = async (id: string) => frame ? frame.locator(`[data-testid="${id}"]`).first().isVisible({ timeout: 2_000 }).catch(() => false) : null;
                const txt = async (id: string) => frame ? (await frame.locator(`[data-testid="${id}"]`).first().innerText({ timeout: 2_000 }).catch(() => '')).trim() : '';
                row.panel = await vis('mollie-panel-card');
                row.refundForm = await vis('mollie-refund-form');
                row.refundBound = await txt('refund-bound');
                row.captureForm = await vis('mollie-capture-form');
                row.cancelForm = await vis('mollie-cancel-form');
                row.capturedAmount = await txt('captured-amount');
                row.methodUsed = await txt('payment-method-used');
                row.transactionRows = frame ? (await frame.locator('[data-testid="mollie-transaction-row"]').allInnerTexts().catch(() => [])).map((t) => t.replace(/\s+/g, ' ').trim()) : [];
            }
        } catch (error) {
            row.error = (error as Error).message.split('\n')[0].slice(0, 120);
        }
        results.push(row);
        writeFileSync(OUT, JSON.stringify(results, null, 2));
    }
    console.log(JSON.stringify(results, null, 2));
});

function contractState(nr: string): string {
    return shopDbQuery(`SELECT c.OXSTATE FROM oxorder o JOIN oe_payments_contract c ON c.OXORDERID=o.OXID WHERE o.OXORDERNR=${Number(nr)}`)[0]?.[0] ?? '';
}
