import { Page, Frame, expect } from '@playwright/test';

/**
 * Admin-backend helpers for the "order completion in the admin" specs — the Mollie counterpart
 * of Stripe's `pages/admin/*` Page Objects (`AdminBasePage` / `AdminOrdersPage` /
 * `AdminStripeOrderPage`), flattened into plain functions to match this project's
 * fixture-driven style (see `shop-helpers.ts`).
 *
 * The OXID admin is a classic frameset: `navigation` (left menu), `basefrm` (content), which in
 * turn holds `list` (top: item list + tab bar) and `edit` (bottom: the edit form / panel body).
 * Selecting an order reloads `edit`; the tab bar links live in `list`; clicking a tab reloads
 * `edit` with that tab's content — so the shared "Payment"/"Zahlung" tab (owned by
 * `payment-base`) renders the Mollie panel body into `edit`.
 */

const ADMIN_USER = process.env.ADMIN_USER_EMAIL || 'noreply@oxid-esales.com';
const ADMIN_PW = process.env.ADMIN_USER_PASSWORD || 'admin';

// ---------------------------------------------------------------------------
// Frame accessors (mirror AdminBasePage)
// ---------------------------------------------------------------------------

export function getMenuFrame(page: Page): Frame | null {
    return page.frame('navigation') || page.frame('adminnav');
}

export function getBaseFrame(page: Page): Frame | null {
    return page.frame('basefrm');
}

export function getListFrame(page: Page): Frame | null {
    return page.frame('list');
}

export function getEditFrame(page: Page): Frame | null {
    return page.frame('edit');
}

async function waitForAdminFrames(page: Page): Promise<void> {
    await page.waitForTimeout(2000);
    for (let attempt = 0; attempt < 10; attempt++) {
        if (getMenuFrame(page)) {
            return;
        }
        await page.waitForTimeout(1000);
    }
}

// ---------------------------------------------------------------------------
// Login + navigation (mirror AdminLoginPage / AdminOrdersPage)
// ---------------------------------------------------------------------------

/**
 * Logs into the admin backend using ADMIN_USER_EMAIL / ADMIN_USER_PASSWORD (see `.env.dist`),
 * then waits for the admin frameset to load.
 */
export async function loginAdmin(page: Page): Promise<void> {
    await page.goto('/admin');
    await page.locator('input[name="user"]').fill(ADMIN_USER);
    await page.locator('input[name="pwd"]').fill(ADMIN_PW);
    await page.locator('input[type="submit"], button[type="submit"]').first().click();
    await page.waitForLoadState('networkidle').catch(() => {});
    await waitForAdminFrames(page);
    expect(getMenuFrame(page), 'admin navigation frame should load after login').not.toBeNull();
}

/**
 * Expands "Administer Orders" in the left menu and opens the "Orders" list. Returns the `list`
 * frame that now holds the order table. Labels are matched in both EN and DE.
 */
export async function navigateToOrders(page: Page): Promise<Frame> {
    const menu = getMenuFrame(page);
    if (!menu) {
        throw new Error('Admin navigation frame not found');
    }

    const adminOrders = menu.locator('a').filter({ hasText: /Administer Orders|Bestellungen verwalten/ }).first();
    await adminOrders.click().catch(() => {});
    await page.waitForTimeout(1000);
    // A second click reliably expands the group when the first only selected it.
    await adminOrders.click().catch(() => {});
    await page.waitForTimeout(1000);

    const base = getBaseFrame(page);
    if (base) {
        const ordersLink = base.locator('a').filter({ hasText: /^\s*(Orders|Bestellungen)\s*$/ }).first();
        if (await ordersLink.isVisible({ timeout: 3000 }).catch(() => false)) {
            await ordersLink.click();
            await page.waitForTimeout(3000);
        }
    }

    await page.waitForTimeout(2000);
    const list = getListFrame(page);
    if (!list) {
        throw new Error('Order list frame not found after navigating to Orders');
    }
    return list;
}

// ---------------------------------------------------------------------------
// Order list parsing (OXID core order_list.html.twig columns)
// ---------------------------------------------------------------------------

/**
 * One row of the admin order list. Column order comes straight from OXID core's
 * `order_list.html.twig`: order-creation date (`.order_time`), OXPAID payment date
 * (`.payment_date`), order number (`.order_no`).
 */
export interface AdminOrderRow {
    orderDate: string;
    paymentDate: string;
    orderNumber: string;
    hasValidPaymentDate: boolean;
}

/**
 * OXPAID is valid once a payment has completed. Unpaid/NOT_FINISHED orders render the zero date
 * (`0000-00-00 ...`), which `format_date` may also collapse to an empty cell — both are "not set".
 */
export function isValidPaymentDate(paymentDate: string): boolean {
    const value = paymentDate.trim();
    return /\d{4}-\d{2}-\d{2}/.test(value) && !value.startsWith('0000');
}

export async function readOrderRows(list: Frame): Promise<AdminOrderRow[]> {
    const rows = await list.locator('tr:has(td.order_no)').all();
    const parsed: AdminOrderRow[] = [];

    for (const row of rows) {
        const orderDate = ((await row.locator('td.order_time').first().textContent().catch(() => '')) || '').trim();
        const paymentDate = ((await row.locator('td.payment_date').first().textContent().catch(() => '')) || '').trim();
        const orderNumber = ((await row.locator('td.order_no').first().textContent().catch(() => '')) || '').trim();

        parsed.push({ orderDate, paymentDate, orderNumber, hasValidPaymentDate: isValidPaymentDate(paymentDate) });
    }

    return parsed;
}

// ---------------------------------------------------------------------------
// Shared "Payment" tab + Mollie panel (mirror AdminStripeOrderPage, Mollie-flavoured)
// ---------------------------------------------------------------------------

/**
 * Opens the shared "Payment"/"Zahlung" tab (rendered by `payment-base`). The tab bar lives in the
 * `list` frame; the panel body loads into `edit`. Waits for the panel wrapper to appear.
 */
export async function openPaymentTab(page: Page): Promise<void> {
    const list = getListFrame(page);
    if (!list) {
        throw new Error('List frame not found');
    }

    // Scope to the tab bar so we never match "Payment Date" copy elsewhere on the page.
    const tab = list
        .locator('table.tabs a, .tabs a, [id^="tbcl"]')
        .filter({ hasText: /^\s*(Payment|Zahlung)\s*$/ })
        .first();

    if (await tab.isVisible({ timeout: 4000 }).catch(() => false)) {
        await tab.click();
    } else {
        const fallback = list.locator('a').filter({ hasText: /^\s*(Payment|Zahlung)\s*$/ }).first();
        await fallback.click();
    }

    await page.waitForTimeout(2500);
    const edit = getEditFrame(page);
    if (edit) {
        await edit.locator('[data-testid="payment-admin-tab"]').waitFor({ timeout: 10_000 }).catch(() => {});
    }
}

function cleanValue(raw: string | null): string | null {
    if (raw === null) {
        return null;
    }
    const value = raw.trim();
    if (value === '' || value === '—' || value === '-') {
        return null;
    }
    return value;
}

/**
 * The Mollie payment details rendered by `views/twig/admin/panel/mollie_panel.html.twig`.
 * `provider` is the wrapper's `data-provider` (should be `mollie` for a Mollie order); the payment
 * id (`tr_...`) comes from either the dashboard link or the plain `<code>`.
 */
export interface MolliePanelDetails {
    provider: string | null;
    contractId: string | null;
    paymentId: string | null;
    dashboardLink: string | null;
    capturedAmount: string | null;
    refundedAmount: string | null;
}

export async function readMolliePanel(edit: Frame): Promise<MolliePanelDetails> {
    const provider = await edit
        .locator('[data-testid="payment-admin-tab"]')
        .getAttribute('data-provider')
        .catch(() => null);

    const contractId = cleanValue(
        await edit.locator('[data-testid="contract-id"]').first().textContent().catch(() => null),
    );

    // The payment id is shown as a link when a dashboard URL is configured, otherwise as plain code.
    const linkedId = cleanValue(
        await edit.locator('[data-testid="mollie-dashboard-link"]').first().textContent().catch(() => null),
    );
    const plainId = cleanValue(
        await edit.locator('[data-testid="mollie-payment-id"]').first().textContent().catch(() => null),
    );

    const dashboardLink = await edit
        .locator('[data-testid="mollie-dashboard-link"]')
        .first()
        .getAttribute('href')
        .catch(() => null);

    const capturedAmount = cleanValue(
        await edit.locator('[data-testid="captured-amount"]').first().textContent().catch(() => null),
    );
    const refundedAmount = cleanValue(
        await edit.locator('[data-testid="refunded-amount"]').first().textContent().catch(() => null),
    );

    return {
        provider,
        contractId,
        paymentId: linkedId || plainId,
        dashboardLink,
        capturedAmount,
        refundedAmount,
    };
}

/**
 * Walks the first `maxToScan` orders, opening each and its Payment tab, until one is identified as
 * a Mollie order (the shared panel wrapper reports `data-provider="mollie"` and a contract id is
 * present). Returns that order's `edit` frame and its list-row data, or `null` if none is found.
 *
 * These specs are meant to run against a shop that already has a completed Mollie order — the
 * `CheckoutPaysAndFinalizes` spec (or a prior manual/CI run) produces one.
 */
export async function openFirstMollieOrder(
    page: Page,
    maxToScan = 8,
): Promise<{ edit: Frame; row: AdminOrderRow } | null> {
    const list = getListFrame(page);
    if (!list) {
        return null;
    }

    const rowCount = await list.locator('tr:has(td.order_no)').count();
    const limit = Math.min(rowCount, maxToScan);

    for (let index = 0; index < limit; index++) {
        // Re-query each iteration: opening an order and its Payment tab reloads the `edit` frame.
        const row = list.locator('tr:has(td.order_no)').nth(index);
        const orderNumber = ((await row.locator('td.order_no').first().textContent().catch(() => '')) || '').trim();
        const paymentDate = ((await row.locator('td.payment_date').first().textContent().catch(() => '')) || '').trim();

        await row.locator('a').first().click();
        await page.waitForTimeout(1500);

        await openPaymentTab(page);
        const edit = getEditFrame(page);
        if (!edit) {
            continue;
        }

        const isMollie = await edit
            .locator('[data-testid="payment-admin-tab"][data-provider="mollie"]')
            .isVisible({ timeout: 4000 })
            .catch(() => false);
        const hasContract = await edit
            .locator('[data-testid="contract-id"]')
            .isVisible({ timeout: 2000 })
            .catch(() => false);

        if (isMollie && hasContract) {
            return {
                edit,
                row: {
                    orderDate: '',
                    paymentDate,
                    orderNumber,
                    hasValidPaymentDate: isValidPaymentDate(paymentDate),
                },
            };
        }
    }

    return null;
}
