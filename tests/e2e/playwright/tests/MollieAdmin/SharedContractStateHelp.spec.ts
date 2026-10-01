import { test, expect, Frame, Page } from '@playwright/test';
import { AdminLoginPage } from '../pages/admin/AdminLoginPage';
import { shopDbQuery } from '../../fixtures/shop-db';

/**
 * MOL-10 (shared Help) — payment-base's Settings tab ends with the generic two-column Help
 * (OXID Contract Status · Meaning); a provider's Settings tab (Stripe here, Mollie in
 * ConfigHelpSection.spec.ts) shows the same table with its own third column. Checked here, on the
 * shop that runs all three modules, because payment-base has no browser suite of its own.
 */
const HELP_RE = /^(Help|Hilfe)$/;
const LABEL_RE = /OXID Contract Status|OXID-Vertragsstatus/;

function frame(page: Page, name: string): Frame {
    const f = page.frame(name);
    if (!f) throw new Error(`${name} frame not found`);
    return f;
}

async function openModuleSettings(page: Page, title: RegExp): Promise<Frame> {
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
    const row = list.locator('table tr').filter({ hasText: title }).first();
    await row.waitFor({ state: 'visible', timeout: 10_000 });
    await row.locator('a').first().click();
    await page.waitForTimeout(2000);
    await list.locator('table.tabs a, .tabs a, [id^="tbcl"]').filter({ hasText: /^(Settings|Einstellungen)$/ }).first().click();
    await page.waitForTimeout(2500);
    return frame(page, 'edit');
}

async function expectHelpGroupLast(edit: Frame, columns: number, providerHeader: RegExp | null): Promise<void> {
    const headers = edit.locator('div.groupExp a.rc b');
    await expect(headers.first()).toBeVisible({ timeout: 15_000 });
    const titles = (await headers.allTextContents()).map((t) => t.trim());
    const help = titles.findIndex((t) => HELP_RE.test(t));
    expect(help, `Help group present and last in ${titles.join(' | ')}`).toBe(titles.length - 1);
    await headers.nth(help).click();
    await edit.page().waitForTimeout(400);
    const table = edit.locator('div.groupExp').nth(help).locator('table.pc-help-table');
    await expect(table).toBeVisible();
    await expect(table.locator('thead th')).toHaveCount(columns);
    const text = (await table.textContent()) ?? '';
    expect(text).toMatch(LABEL_RE);
    expect(text).toMatch(/Meaning|Bedeutung/);
    for (const state of ['not_finished', 'ready_to_commit', 'fulfilled', 'failed']) expect(text).toContain(state);
    expect(text, 'no raw key').not.toMatch(/PAYMENT_ADMIN_HELP|STRIPE_HELP|MOLLIE_HELP/);
    if (providerHeader) expect(text).toMatch(providerHeader);
}

test.describe('MOL-10 — shared contract-state Help across modules', () => {
    test('payment-base settings: two-column Help group', async ({ page }) => {
        const edit = await openModuleSettings(page, /OXID Payment Base/);
        await expectHelpGroupLast(edit, 2, null);
    });

    test('Stripe settings: Help group with the Stripe PaymentIntent column', async ({ page }) => {
        const edit = await openModuleSettings(page, /Stripe Wallet/);
        await expectHelpGroupLast(edit, 3, /Stripe PaymentIntent|Stripe-PaymentIntent/);
        const text = (await edit.locator('table.pc-help-table').textContent()) ?? '';
        expect(text).toContain('requires_capture');
        expect(text).toContain('succeeded');
    });

    test('Stripe order Payment tab: "OXID Contract Status" with the shared "?" layer and Stripe\'s column', async ({ page }) => {
        const orderId = shopDbQuery("SELECT OXID FROM oxorder WHERE OXPAYMENTTYPE LIKE 'oe_payments_stripe%' ORDER BY OXORDERDATE DESC LIMIT 1")[0]?.[0];
        test.skip(!orderId, 'no Stripe order in the shop database');
        const login = new AdminLoginPage(page);
        await login.navigate();
        if (!(await login.isLoggedIn())) await login.login();
        const sid = page.url().match(/force_admin_sid=([^&]+)/)?.[1];
        const stoken = page.url().match(/stoken=([^&]+)/)?.[1];
        await page.goto(`/admin/index.php?cl=PaymentAdmin&fnc=dispatchAction&oxid=${orderId}&force_admin_sid=${sid}&stoken=${stoken}&shp=1`);
        await page.waitForLoadState('domcontentloaded');
        const label = page.locator('td.s-label').filter({ hasText: LABEL_RE });
        await expect(label, 'Stripe\'s panel shows the contract state row').toHaveCount(1);
        await expect(page.locator('[data-testid="contract-state"]')).not.toHaveText('');
        const hint = label.locator('button[data-pc-help-toggle]');
        await hint.click();
        const layer = page.locator('#pc-help-stripe-contract-state');
        await expect(layer).toBeVisible();
        const text = (await layer.textContent()) ?? '';
        expect(text).toMatch(/Stripe PaymentIntent|Stripe-PaymentIntent/);
        expect(text).toContain('requires_capture');
        expect(text, 'no raw key').not.toMatch(/PAYMENT_ADMIN_HELP|STRIPE_HELP/);
        await page.keyboard.press('Escape');
        await expect(layer).toBeHidden();
    });
});
