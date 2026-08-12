import { test, expect, Frame, Page } from '@playwright/test';
import { AdminLoginPage } from '../pages/admin/AdminLoginPage';

/**
 * Sprint 10 — the Mollie API keys must render masked on the module settings tab.
 *
 * Scope, stated honestly: the value still travels to the browser inside `value=""`. This
 * mitigates shoulder-surfing, screen sharing and screenshots — not devtools. What this spec
 * guards is that the Twig chain actually resolves Mollie's `module_config.html.twig` override
 * in the real admin, since four modules override that same template in this shop and a chain
 * or asset-path regression would silently drop the masking back to clear text.
 */

const MODULE_TITLE_RE = /Mollie Payment/;

// Must stay in sync with MollieDefinitions::SECRET_MODULE_SETTINGS — the PHP-side drift guard
// is tests/Unit/Admin/ModuleConfigSecretMaskingTest.php.
const SENSITIVE_FIELDS = ['sMollieTestKey', 'sMollieLiveKey'];

// The two collapsible group headers the credential settings live under. They are collapsed on
// load, so the inputs are attached but not visible until these are clicked.
const CREDENTIAL_GROUPS = [/Test credentials|Test-Zugangsdaten/i, /Live credentials|Live-Zugangsdaten/i];

function toggleFor(edit: Frame, name: string) {
    return edit
        .locator(
            `input[name="confstrs[${name}]"] ~ button.mollie-key-toggle, ` +
            `input[name="confstrs[${name}]"] + button.mollie-key-toggle`,
        )
        .first();
}

function inputFor(edit: Frame, name: string) {
    return edit.locator(`input[name="confstrs[${name}]"]`).first();
}

function getMenuFrame(page: Page): Frame {
    const frame = page.frame('adminnav') || page.frame('navigation');
    if (!frame) throw new Error('navigation frame not found');
    return frame;
}

function getListFrame(page: Page): Frame {
    const frame = page.frame('list');
    if (!frame) throw new Error('list frame not found');
    return frame;
}

function getEditFrame(page: Page): Frame {
    const frame = page.frame('edit');
    if (!frame) throw new Error('edit frame not found');
    return frame;
}

async function openExtensionsModules(page: Page): Promise<void> {
    const menu = getMenuFrame(page);
    await menu.locator('a:has-text("Extensions"), a:has-text("Erweiterungen")').first().click();
    await page.waitForTimeout(800);

    const modulesLink = menu.locator('a:has-text("Modules"), a:has-text("Module")').first();
    if (await modulesLink.isVisible({ timeout: 3000 }).catch(() => false)) {
        await modulesLink.click();
    } else {
        const baseFrame = page.frame('basefrm');
        const inContent = baseFrame?.locator('a:has-text("Modules"), a:has-text("Module")').first();
        if (inContent && await inContent.isVisible({ timeout: 3000 }).catch(() => false)) {
            await inContent.click();
        }
    }
    await page.waitForTimeout(2000);
}

async function selectMollieRow(page: Page): Promise<void> {
    const list = getListFrame(page);
    const row = list.locator('table tr').filter({ hasText: MODULE_TITLE_RE }).first();
    await row.waitFor({ state: 'visible', timeout: 10_000 });
    await row.locator('a').first().click();
    await page.waitForTimeout(2000);
}

async function openSettingsTab(page: Page): Promise<void> {
    const list = getListFrame(page);
    const settingsTab = list
        .locator('table.tabs a, .tabs a, [id^="tbcl"]')
        .filter({ hasText: /^(Settings|Einstellungen)$/ })
        .first();
    await settingsTab.waitFor({ state: 'visible', timeout: 5000 });
    await settingsTab.click();
    await page.waitForTimeout(2500);
}

/**
 * Admin login -> Extensions -> Modules -> Mollie Payment -> Settings, with the credential
 * groups expanded. Returns the `edit` frame the settings form lives in.
 */
async function openMollieSettingsForm(page: Page): Promise<Frame> {
    const adminLogin = new AdminLoginPage(page);
    await adminLogin.navigate();
    if (!await adminLogin.isLoggedIn()) {
        await adminLogin.login();
    }
    expect(await adminLogin.isLoggedIn(), 'admin login failed').toBe(true);

    await openExtensionsModules(page);
    await selectMollieRow(page);
    await openSettingsTab(page);

    const edit = getEditFrame(page);
    await inputFor(edit, SENSITIVE_FIELDS[0]).waitFor({ state: 'attached', timeout: 15_000 });

    for (const group of CREDENTIAL_GROUPS) {
        const header = edit.getByText(group).first();
        if (await header.isVisible({ timeout: 3000 }).catch(() => false)) {
            await header.click();
            await page.waitForTimeout(400);
        }
    }

    return edit;
}

test.describe('Sprint 10: Mollie API keys are masked behind a reveal toggle', () => {

    test('every credential field renders as type="password" on load', async ({ page }) => {
        const edit = await openMollieSettingsForm(page);

        for (const name of SENSITIVE_FIELDS) {
            const input = inputFor(edit, name);
            await input.waitFor({ state: 'attached', timeout: 5000 });
            await expect(input, `${name}: must be type=password by default`)
                .toHaveAttribute('type', 'password');
        }
    });

    test('every credential field has an adjacent, labelled toggle button', async ({ page }) => {
        const edit = await openMollieSettingsForm(page);

        for (const name of SENSITIVE_FIELDS) {
            await inputFor(edit, name).waitFor({ state: 'attached', timeout: 5000 });
            const toggle = toggleFor(edit, name);

            await expect(toggle, `${name}: toggle must sit next to the input`).toBeAttached();
            await expect(toggle, `${name}: toggle starts in the masked state`)
                .toHaveAttribute('aria-pressed', 'false');

            const ariaLabel = await toggle.getAttribute('aria-label');
            expect(ariaLabel, `${name}: toggle needs an accessible name`).toBeTruthy();
            expect((ariaLabel ?? '').length, `${name}: aria-label must be non-empty`).toBeGreaterThan(0);
        }
    });

    test('clicking the toggle reveals the key, clicking again re-masks it', async ({ page }) => {
        const edit = await openMollieSettingsForm(page);

        const name = 'sMollieTestKey';
        const input = inputFor(edit, name);
        await input.waitFor({ state: 'attached', timeout: 5000 });
        const toggle = toggleFor(edit, name);

        await expect(input).toHaveAttribute('type', 'password');
        const labelMasked = await toggle.getAttribute('aria-label');

        await toggle.click();
        await expect(input, 'reveal: input becomes type=text').toHaveAttribute('type', 'text');
        await expect(toggle, 'reveal: aria-pressed=true').toHaveAttribute('aria-pressed', 'true');
        expect(await toggle.getAttribute('aria-label'), 'reveal: aria-label swaps')
            .not.toBe(labelMasked);

        await toggle.click();
        await expect(input, 're-mask: input back to type=password').toHaveAttribute('type', 'password');
        await expect(toggle, 're-mask: aria-pressed=false').toHaveAttribute('aria-pressed', 'false');
        await expect(toggle, 're-mask: original aria-label restored')
            .toHaveAttribute('aria-label', labelMasked!);
    });

    test('toggle is keyboard-operable via Enter and Space', async ({ page }) => {
        // A native <button> answers both for free — this asserts we did not break that by
        // hand-rolling key handling or swapping in a non-button element.
        const edit = await openMollieSettingsForm(page);

        const name = 'sMollieLiveKey';
        const input = inputFor(edit, name);
        await input.waitFor({ state: 'attached', timeout: 5000 });
        const toggle = toggleFor(edit, name);

        await expect(input).toHaveAttribute('type', 'password');

        await toggle.focus();
        await toggle.press('Enter');
        await expect(input, 'Enter reveals').toHaveAttribute('type', 'text');

        await toggle.press('Space');
        await expect(input, 'Space re-masks').toHaveAttribute('type', 'password');
    });

    test('the key value survives a reveal/re-mask cycle unchanged', async ({ page }) => {
        // The toggle must touch `type` only. Rewriting `value` would silently drop an unsaved
        // edit when the admin reveals the field before saving.
        const edit = await openMollieSettingsForm(page);

        const name = 'sMollieTestKey';
        const input = inputFor(edit, name);
        await input.waitFor({ state: 'attached', timeout: 5000 });
        const toggle = toggleFor(edit, name);

        const originalValue = await input.inputValue();

        await toggle.click();
        await expect(input).toHaveAttribute('type', 'text');
        expect(await input.inputValue(), 'value preserved on reveal').toBe(originalValue);

        await toggle.click();
        await expect(input).toHaveAttribute('type', 'password');
        expect(await input.inputValue(), 'value preserved on re-mask').toBe(originalValue);
    });
});
