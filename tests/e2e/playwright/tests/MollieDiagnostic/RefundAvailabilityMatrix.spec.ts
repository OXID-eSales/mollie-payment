import { test, type Page } from '@playwright/test';
import { writeFileSync } from 'node:fs';
import {
    loginStorefront,
    addFirstFeaturedProductToBasket,
    goToCheckoutPayment,
    selectMolliePaymentMethod,
    continueToOrderReview,
    acceptTermsAndConditions,
} from '../../fixtures/shop-helpers';
import { readThankYouOrderNumber } from '../../fixtures/mollie-authorized-helpers';
import { shopDbQuery } from '../../fixtures/shop-db';
import { fetchMolliePayment } from '../../fixtures/mollie-api';
import { AdminLoginPage } from '../pages/admin/AdminLoginPage';
import { AdminOrdersPage } from '../pages/admin/AdminOrdersPage';
import { AdminMollieOrderPage } from '../pages/admin/AdminMollieOrderPage';

/**
 * DIAGNOSTIC (ticket: paid Pay by Bank / EPS orders offer no refund in admin). Pays one order per
 * Mollie method offered on the order page, then records three views of each order: the shop DB
 * (contract state, captured / refunded amounts, transaction rows), Mollie's API (status, method,
 * amountRemaining, amountRefunded), and the admin Payment tab (which forms render). Writes JSON to
 * MATRIX_OUT. It asserts nothing - the pattern is read off the table.
 */
const OUT = process.env.MATRIX_OUT || '/tmp/refund-matrix.json';
const SKIP_METHODS = new Set(['creditcard', 'applepay', 'giftcard', 'voucher', 'pointofsale']);
const ADMIN_USER = process.env.ADMIN_USER_EMAIL || 'noreply@oxid-esales.com';
const ADMIN_PW = process.env.ADMIN_USER_PASSWORD || 'admin';

interface Row {
    method: string;
    checkout: string;
    landedOn?: string;
    orderNr?: string | null;
    contract?: Record<string, string>;
    transactions?: string[];
    mollie?: unknown;
    admin?: Record<string, unknown>;
    contractAtAdminTime?: Record<string, string>;
}

test.describe('DIAGNOSTIC — refund availability per Mollie method', () => {
    test('pay with every offered method, then inspect DB, Mollie and admin', async ({ page }) => {
        test.setTimeout(40 * 60_000);
        const rows: Row[] = [];
        const methods = await listOfferedMethods(page);
        rows.push({ method: '_offered', checkout: methods.join(',') });

        for (const method of methods) {
            if (SKIP_METHODS.has(method)) {
                rows.push({ method, checkout: 'skipped (needs a different flow)' });
                continue;
            }
            const row: Row = { method, checkout: 'started' };
            rows.push(row);
            try {
                await payWith(page, method, row);
            } catch (error) {
                row.checkout = `error: ${(error as Error).message.split('\n')[0].slice(0, 160)}`;
                await page.goto('/index.php?cl=basket').catch(() => {});
                await addFirstFeaturedProductToBasket(page).catch(() => {});
            }
            writeFileSync(OUT, JSON.stringify(rows, null, 2));
        }

        // Admin pass, once for all orders (webhooks had time to land meanwhile).
        const adminLogin = new AdminLoginPage(page);
        const ordersPage = new AdminOrdersPage(page);
        const molliePage = new AdminMollieOrderPage(page);
        await adminLogin.navigate();
        if (!(await adminLogin.isLoggedIn())) {
            await adminLogin.login({ email: ADMIN_USER, password: ADMIN_PW });
        }
        for (const row of rows) {
            if (!row.orderNr) continue;
            try {
                await ordersPage.navigateToOrders();
                const found = await ordersPage.selectOrderByNumber(row.orderNr);
                if (!found) { row.admin = { error: 'order not found in list' }; continue; }
                await ordersPage.openPaymentTab();
                await molliePage.waitForContentLoaded().catch(() => {});
                row.admin = await readPanel(molliePage);
                row.contractAtAdminTime = contractRow(row.orderNr);
            } catch (error) {
                row.admin = { error: (error as Error).message.split('\n')[0].slice(0, 160) };
            }
            writeFileSync(OUT, JSON.stringify(rows, null, 2));
        }
        writeFileSync(OUT, JSON.stringify(rows, null, 2));
        console.log(JSON.stringify(rows, null, 2));
    });
});

async function listOfferedMethods(page: Page): Promise<string[]> {
    await loginStorefront(page);
    await addFirstFeaturedProductToBasket(page);
    await goToCheckoutPayment(page);
    await selectMolliePaymentMethod(page, 'paypal');
    await continueToOrderReview(page);
    await page.goto('/index.php?cl=order');
    await page.waitForLoadState('domcontentloaded');
    const values = await page.locator('input[name="mollieMethod"]').evaluateAll(
        (inputs) => inputs.map((i) => (i as HTMLInputElement).value),
    );
    return values;
}

async function payWith(page: Page, method: string, row: Row): Promise<void> {
    await page.goto('/index.php?cl=order');
    await page.waitForLoadState('domcontentloaded');
    if ((await page.locator('input[name="mollieMethod"]').count()) === 0) {
        // Basket emptied by the previous order: rebuild it.
        await addFirstFeaturedProductToBasket(page);
        await goToCheckoutPayment(page);
        await selectMolliePaymentMethod(page, 'paypal');
        await continueToOrderReview(page);
        await page.goto('/index.php?cl=order');
        await page.waitForLoadState('domcontentloaded');
    }
    await acceptTermsAndConditions(page);
    await page.locator(`input[name="mollieMethod"][value="${method}"]`).check({ force: true });
    await page.getByRole('button', { name: /order now|zahlungspflichtig bestellen|place order/i }).first().click();
    await page.waitForURL(/mollie\.com/i, { timeout: 45_000 });
    await page.waitForLoadState('domcontentloaded');

    // Mollie test-mode: an issuer page (iDEAL, KBC) or a method page may precede the status page.
    const paid = page.getByText('Paid', { exact: true });
    for (let step = 0; step < 3 && !(await paid.isVisible({ timeout: 4_000 }).catch(() => false)); step++) {
        if (/select-issuer/i.test(page.url())) {
            const issuer = page.getByRole('button').filter({ hasNotText: /previous page/i }).first();
            row.checkout = `issuer page -> ${(await issuer.innerText().catch(() => '?')).trim()}`;
            await issuer.click();
            await page.waitForLoadState('domcontentloaded');
            continue;
        }
        const methodButton = page.getByRole('button', { name: new RegExp(method, 'i') }).first();
        if (await methodButton.isVisible({ timeout: 2_000 }).catch(() => false)) {
            await methodButton.click();
            await page.waitForLoadState('domcontentloaded');
        }
    }
    if (!(await paid.isVisible({ timeout: 5_000 }).catch(() => false))) {
        row.checkout = `no "Paid" option on Mollie's page (${page.url()})`;
        const options = await page.locator('label, button').allInnerTexts().catch(() => []);
        row.admin = { mollieOptions: options.map((o) => o.trim()).filter(Boolean).slice(0, 12) };
        // Leave the attempt behind with a CHANGED basket total, otherwise MOL-18 replays it for the
        // next method (the in-flight check compares basket totals, not the chosen method).
        await page.goto('/index.php?cl=basket');
        await addFirstFeaturedProductToBasket(page);
        return;
    }
    await paid.click();
    await page.getByRole('button', { name: /continue/i }).click();
    await page.waitForURL((url) => !/mollie\.com$/i.test(url.hostname), { timeout: 45_000 });
    await page.waitForLoadState('networkidle').catch(() => {});
    row.landedOn = page.url().replace(/force_sid=[^&]+&?/, '');
    row.orderNr = await readThankYouOrderNumber(page);
    row.checkout = row.orderNr ? 'paid' : `returned without order number (${await bodyNotice(page)})`;
    await page.waitForTimeout(5_000); // give the webhook a moment

    if (row.orderNr) {
        row.contract = contractRow(row.orderNr);
        row.transactions = transactionRows(row.contract.contractId);
        row.mollie = row.contract.providerOrderId ? await fetchMolliePayment(row.contract.providerOrderId) : { error: 'no provider order id' };
    }
}

async function bodyNotice(page: Page): Promise<string> {
    const text = await page.locator('.alert, .status').allInnerTexts().catch(() => []);
    return text.join(' | ').slice(0, 160);
}

function contractRow(orderNr: string): Record<string, string> {
    const rows = shopDbQuery(
        `SELECT c.OXID, c.OXSTATE, IFNULL(c.OXCAPTUREDAMOUNT,'NULL'), IFNULL(c.OXREFUNDEDAMOUNT,'NULL'), IFNULL(c.OXPROVIDERORDERID,''), ` +
        `o.OXTRANSSTATUS, o.OXPAID, IFNULL(c.OXCAPTUREDAT,'NULL'), IFNULL(c.OXMETADATA,'') ` +
        `FROM oxorder o LEFT JOIN oe_payments_contract c ON c.OXORDERID = o.OXID WHERE o.OXORDERNR = ${Number(orderNr)}`,
    );
    const r = rows[0] ?? [];
    return {
        contractId: r[0] ?? '', state: r[1] ?? '', capturedAmount: r[2] ?? '', refundedAmount: r[3] ?? '',
        providerOrderId: r[4] ?? '', orderTransStatus: r[5] ?? '', orderPaid: r[6] ?? '', capturedAt: r[7] ?? '',
        metadata: (r[8] ?? '').slice(0, 200),
    };
}

function transactionRows(contractId: string): string[] {
    if (!contractId) return [];
    return shopDbQuery(
        `SELECT CONCAT(OXTYPE,'/',OXSTATUS,'/',OXAMOUNT,'/',IFNULL(OXPAYMENTMETHODID,'-')) FROM oe_payments_transaction WHERE OXCONTRACTID = '${contractId}' ORDER BY OXCREATED`,
    ).map((r) => r[0]);
}

async function readPanel(molliePage: AdminMollieOrderPage): Promise<Record<string, unknown>> {
    const frame = (molliePage as unknown as { getEditFrame: () => import('@playwright/test').FrameLocator | null }).getEditFrame();
    const text = async (testId: string) => frame ? (await frame.locator(`[data-testid="${testId}"]`).first().innerText({ timeout: 2_000 }).catch(() => '')).trim() : '';
    const visible = async (testId: string) => frame ? frame.locator(`[data-testid="${testId}"]`).first().isVisible({ timeout: 1_500 }).catch(() => false) : false;
    return {
        panelVisible: await molliePage.isMolliePanelVisible(),
        paymentMethodUsed: await text('payment-method-used'),
        capturedAmount: await text('captured-amount'),
        refundedAmount: await text('refunded-amount'),
        refundForm: await visible('mollie-refund-form'),
        refundBound: await text('refund-bound'),
        captureForm: await visible('mollie-capture-form'),
        cancelForm: await visible('mollie-cancel-form'),
        noContract: await visible('mollie-no-contract'),
        error: await text('mollie-panel-error'),
        transactionRows: frame ? await frame.locator('[data-testid="mollie-transaction-row"]').allInnerTexts().catch(() => []) : [],
    };
}
