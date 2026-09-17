/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

import { test, expect, Frame } from '@playwright/test';
import { AdminLoginPage } from '../pages/admin/AdminLoginPage';

/**
 * 2026-09-17 — after an admin action the Payment tab must show the post-action state
 * WITHOUT the operator reloading anything.
 *
 * Regression: the action request memoized the live Mollie payment while validating the
 * amount, then re-rendered the panel from that pre-action memo. The refundable bound only
 * changed after a manual reload. (`mollie-admin-refund.spec.ts` papered over this by
 * re-opening the tab before asserting — this spec deliberately does not.)
 *
 * Prerequisites (see README): a shop with a Mollie test-mode key and a fulfilled Mollie order
 * with a refundable balance. Point at it with MOLLIE_E2E_ORDER_NUMBER (default 559).
 * Every run refunds a small, run-unique amount (< 1.00) from that order at Mollie (test mode).
 */
const ORDER_NUMBER = process.env.MOLLIE_E2E_ORDER_NUMBER || '559';
// Unique per run: Mollie refunds are created with an idempotency key derived from the request,
// so repeating the exact same amount on the same order is deduplicated (no new refund).
const REFUND_STEP = parseFloat((0.01 * (1 + (Date.now() % 89))).toFixed(2));
const ADMIN = {
    email: process.env.ADMIN_USER_EMAIL || 'noreply@oxid-esales.com',
    password: process.env.ADMIN_USER_PASSWORD || 'admin',
};

const money = (text: string | null): number => {
    const match = (text || '').match(/(\d+(?:[.,]\d{2})?)/);
    return match ? parseFloat(match[1].replace(',', '.')) : NaN;
};

async function readPanel(edit: Frame) {
    await edit.locator('[data-testid="mollie-panel-card"]').waitFor({ timeout: 15_000 });
    const refundBound = money(await edit.locator('[data-testid="refund-bound"]').textContent());
    const refundInputMax = parseFloat(await edit.locator('#refund_amount').getAttribute('max') || 'NaN');
    const refunded = money(await edit.locator('[data-testid="refunded-amount"]').textContent());
    const refundRows = await edit.locator('[data-testid="mollie-transaction-history"] tr:has-text("refund")').count();
    return { refundBound, refundInputMax, refunded, refundRows };
}

test('Payment tab reflects a partial refund immediately after the action, without a reload', async ({ page }) => {
    const login = new AdminLoginPage(page);

    await login.navigate();
    if (!(await login.isLoggedIn())) {
        await login.login(ADMIN);
    }
    // Open the order admin frameset (list + edit frames) through the sidebar's own link, loaded
    // into the base frame: sidebar labels differ between admin themes/editions, the controller
    // key does not, and the link already carries the admin session + stoken of the origin the
    // login redirected to (the shop's configured URL, not necessarily SHOP_URL).
    const menu = login.getMenuFrame();
    expect(menu, 'admin navigation frame').not.toBeNull();
    const ordersHref = await menu!.locator('a[href*="cl=admin_order"]').first().getAttribute('href');
    expect(ordersHref, 'order list link in the sidebar').toBeTruthy();
    await page.frame('basefrm')!.goto(ordersHref!);
    await expect.poll(() => page.frame('list') !== null, { timeout: 30_000 }).toBe(true);
    const list = page.frame('list')!;
    await list.locator('table').first().waitFor({ timeout: 30_000 });

    // The order-number link carries the order's OXID (`editThis('<oxid>')`). Open the Payment
    // tab controller for it directly in the edit frame, reusing the session + stoken the list
    // frame already has — the same document the tab click would load.
    const orderLink = list.locator('a', { hasText: new RegExp(`^\\s*${ORDER_NUMBER}\\s*$`) }).first();
    await expect(orderLink, `order ${ORDER_NUMBER} in the list`).toBeVisible({ timeout: 15_000 });
    const oxid = (await orderLink.getAttribute('href'))?.match(/editThis\('([^']+)'\)/)?.[1];
    expect(oxid, 'order OXID from the list link').toBeTruthy();
    const paymentTabUrl = new URL(list.url());
    paymentTabUrl.searchParams.set('cl', 'PaymentAdmin');
    paymentTabUrl.searchParams.set('oxid', oxid!);
    await page.frame('edit')!.goto(paymentTabUrl.toString());

    let edit = page.frame('edit');
    expect(edit, 'admin edit frame').not.toBeNull();
    const before = await readPanel(edit!);
    console.log('before:', before);
    expect(before.refundBound).toBeGreaterThanOrEqual(REFUND_STEP);
    expect(before.refundInputMax).toBeCloseTo(before.refundBound, 2);

    // Partial refund of REFUND_STEP through the panel's own form — this is the ONLY navigation
    // that happens from here on; the assertions below run against the response of this POST.
    page.once('dialog', (dialog) => dialog.accept());
    await edit!.locator('#refund_amount').fill(REFUND_STEP.toFixed(2));
    await edit!.locator('#refund_reason').selectOption('requested_by_customer');
    await edit!.locator('#refund_description').fill('E2E: panel must rebuild after refund');
    const responded = page.waitForResponse(
        (r) => r.url().includes('cl=PaymentAdmin') || (r.request().method() === 'POST' && r.url().includes('/admin/')),
        { timeout: 60_000 },
    );
    await edit!.locator('input[data-testid="refund-submit"]').click();
    await responded;

    // Re-acquire the frame (the POST replaced its document) and read what the action request
    // itself rendered. No reload, no tab re-open, no second navigation.
    edit = page.frame('edit');
    expect(edit).not.toBeNull();
    const after = await readPanel(edit!);
    console.log('after :', after);

    await expect(edit!.locator('[data-testid="mollie-validation-errors"]')).toHaveCount(0);
    expect(after.refundBound, 'refundable bound shown by the action response')
        .toBeCloseTo(before.refundBound - REFUND_STEP, 2);
    expect(after.refundInputMax, 'refund input max shown by the action response')
        .toBeCloseTo(before.refundBound - REFUND_STEP, 2);
    expect(after.refunded, 'refunded total shown by the action response')
        .toBeCloseTo(before.refunded + REFUND_STEP, 2);
    expect(after.refundRows, 'refund row in the transaction history').toBeGreaterThan(before.refundRows);
});
