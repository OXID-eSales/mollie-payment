import { test, expect, Frame, Page } from '@playwright/test';
import { AdminLoginPage } from '../pages/admin/AdminLoginPage';
import { shopDbQuery } from '../../fixtures/shop-db';

/**
 * MOL-10 — the Mollie module Settings tab ends with a "Help" group (after "Logging") explaining the
 * OXID contract states and their Mollie payment status, in the admin's language; and the order
 * Payment tab labels the contract state "OXID Contract Status".
 */
const MODULE_TITLE_RE = /Mollie Payment/;
const HELP_RE = /^(Help|Hilfe)$/;
const LOGGING_RE = /^(Logging|Protokollierung)$/;
const LABEL_RE = /OXID Contract Status|OXID-Vertragsstatus/;

function frame(page: Page, name: string): Frame {
    const f = page.frame(name);
    if (!f) throw new Error(`${name} frame not found`);
    return f;
}

async function openMollieSettings(page: Page): Promise<Frame> {
    const login = new AdminLoginPage(page);
    await login.navigate();
    if (!(await login.isLoggedIn())) await login.login();
    const menu = page.frame('adminnav') || page.frame('navigation');
    if (!menu) throw new Error('navigation frame not found');
    await menu.locator('a:has-text("Extensions"), a:has-text("Erweiterungen")').first().click();
    await page.waitForTimeout(800);
    const modules = menu.locator('a:has-text("Modules"), a:has-text("Module")').first();
    if (await modules.isVisible({ timeout: 3000 }).catch(() => false)) await modules.click();
    else await frame(page, 'basefrm').locator('a:has-text("Modules"), a:has-text("Module")').first().click();
    await page.waitForTimeout(2000);
    const list = frame(page, 'list');
    const row = list.locator('table tr').filter({ hasText: MODULE_TITLE_RE }).first();
    await row.waitFor({ state: 'visible', timeout: 10_000 });
    await row.locator('a').first().click();
    await page.waitForTimeout(2000);
    await list.locator('table.tabs a, .tabs a, [id^="tbcl"]').filter({ hasText: /^(Settings|Einstellungen)$/ }).first().click();
    await page.waitForTimeout(2500);
    return frame(page, 'edit');
}

test.describe('MOL-10 — Help group in the Mollie module settings, panel label', () => {
    test('a "Help" group follows "Logging" and explains the contract states with their Mollie status', async ({ page }) => {
        const edit = await openMollieSettings(page);
        const headers = edit.locator('div.groupExp a.rc b');
        await expect(headers.first()).toBeVisible({ timeout: 15_000 });
        const titles = (await headers.allTextContents()).map((t) => t.trim());
        const logging = titles.findIndex((t) => LOGGING_RE.test(t));
        const help = titles.findIndex((t) => HELP_RE.test(t));
        expect(logging, `Logging group present in ${titles.join(' | ')}`).toBeGreaterThanOrEqual(0);
        expect(help, `Help group present in ${titles.join(' | ')}`).toBe(logging + 1);
        expect(help, 'Help is the last group').toBe(titles.length - 1);

        await headers.nth(help).click();
        await page.waitForTimeout(400);
        const helpGroup = edit.locator('div.groupExp').nth(help);
        const table = helpGroup.locator('table');
        await expect(table).toBeVisible();
        const text = (await table.textContent()) ?? '';
        expect(text).toMatch(LABEL_RE);
        expect(text).toMatch(/Meaning|Bedeutung/);
        for (const state of ['not_finished', 'pending', 'authorized', 'ready_to_commit', 'committed', 'fulfilled', 'cancelled', 'expired', 'failed']) {
            expect(text, `state ${state} explained`).toContain(state);
        }
        for (const mollie of ['open', 'paid', 'canceled']) {
            expect(text, `Mollie status ${mollie} mapped`).toContain(mollie);
        }
        expect(text, 'no raw translation key').not.toMatch(/MOLLIE_HELP/);
    });

    test('the order Payment tab labels the contract state "OXID Contract Status"', async ({ page }) => {
        const orderId = shopDbQuery("SELECT OXID FROM oxorder WHERE OXPAYMENTTYPE = 'oe_payments_mollie' ORDER BY OXORDERDATE DESC LIMIT 1")[0]?.[0];
        test.skip(!orderId, 'no Mollie order in the shop database');
        const login = new AdminLoginPage(page);
        await login.navigate();
        if (!(await login.isLoggedIn())) await login.login();
        // The OXID admin carries its session in the URL: a navigation without force_admin_sid + stoken
        // lands on the login form. Both are on the logged-in page's URL.
        const sid = page.url().match(/force_admin_sid=([^&]+)/)?.[1];
        const stoken = page.url().match(/stoken=([^&]+)/)?.[1];
        expect(sid, 'admin session id on the URL').toBeTruthy();
        await page.goto(`/admin/index.php?cl=PaymentAdmin&fnc=dispatchAction&oxid=${orderId}&force_admin_sid=${sid}&stoken=${stoken}&shp=1`);
        await page.waitForLoadState('domcontentloaded');
        const label = page.locator('th.pc-label').filter({ hasText: LABEL_RE });
        await expect(label, 'the contract state row is labelled OXID Contract Status').toHaveCount(1);
        await expect(page.locator('th.pc-label').filter({ hasText: /^(State|Status)$/ })).toHaveCount(0);
    });
});
