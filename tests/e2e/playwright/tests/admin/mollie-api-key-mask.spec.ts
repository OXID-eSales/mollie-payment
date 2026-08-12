import { test, expect, Frame, Page } from '@playwright/test';
import { AdminLoginPage } from '../pages/admin/AdminLoginPage';

/**
 * Sprint 10 — the API keys on the module Settings tab must render masked, with a working reveal
 * toggle.
 *
 * The unit drift-guard (`tests/Unit/Admin/ModuleConfigSecretMaskingTest.php`) asserts the template
 * *source*; it cannot see whether OXID actually resolves the override. That matters here more than
 * usual, because several modules override `admin_twig/module_config.html.twig` in this shop — Mollie,
 * Stripe, PayPal and one-page-checkout — so the mask working depends on the whole template chain
 * resolving and every override delegating with `{{ parent() }}`. Only a browser can confirm that.
 *
 * Ported from Stripe's `stripe-api-key-mask.spec.ts`, repathed to Mollie's shallower e2e layout and
 * to Mollie's own admin fixtures.
 */

const MODULE_TITLE_RE = /Mollie Payment/;

// The two settings in MollieDefinitions::SECRET_MODULE_SETTINGS. Both are editable (Mollie has no
// Connect flow, so there are no readonly token fields as in Stripe).
const SENSITIVE_FIELDS = ['sMollieTestKey', 'sMollieLiveKey'];

// Group headers the fields live under, from the module lang file. Collapsed groups hide their rows.
const CREDENTIAL_GROUPS = [/Test credentials/i, /Live credentials/i, /Test-Zugangsdaten/i, /Live-Zugangsdaten/i];

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
        if (baseFrame) {
            await baseFrame.locator('a:has-text("Modules"), a:has-text("Module")').first().click();
        }
    }
    await page.waitForTimeout(2000);
}

async function selectMollieModuleRow(page: Page): Promise<void> {
    const list = getListFrame(page);
    const row = list.locator('table tr').filter({ hasText: MODULE_TITLE_RE }).first();
    await row.waitFor({ state: 'visible', timeout: 10_000 });
    await row.locator('a').first().click();
    await page.waitForTimeout(2000);
}

async function openSettingsTab(page: Page): Promise<void> {
    const list = getListFrame(page);
    const tab = list
        .locator('table.tabs a, .tabs a, [id^="tbcl"]')
        .filter({ hasText: /^(Settings|Einstellungen)$/ })
        .first();
    await tab.waitFor({ state: 'visible', timeout: 5000 });
    await tab.click();
    await page.waitForTimeout(2500);
}

/** Logs in, walks to Mollie's Settings tab, expands the credential groups, returns the edit frame. */
async function openMollieSettingsForm(page: Page): Promise<Frame> {
    const adminLogin = new AdminLoginPage(page);
    await adminLogin.navigate();
    if (!(await adminLogin.isLoggedIn())) {
        await adminLogin.login();
    }
    expect(await adminLogin.isLoggedIn()).toBe(true);

    await openExtensionsModules(page);
    await selectMollieModuleRow(page);
    await openSettingsTab(page);

    const edit = getEditFrame(page);

    // Expand the collapsible groups holding the keys — collapsed rows are not interactable.
    for (const group of CREDENTIAL_GROUPS) {
        const header = edit.getByText(group).first();
        if (await header.isVisible({ timeout: 1500 }).catch(() => false)) {
            await header.click();
            await page.waitForTimeout(400);
        }
    }

    await edit
        .locator(`input[name="confstrs[${SENSITIVE_FIELDS[0]}]"]`)
        .first()
        .waitFor({ state: 'attached', timeout: 15_000 });

    return edit;
}

function toggleFor(edit: Frame, field: string) {
    return edit
        .locator(
            `input[name="confstrs[${field}]"] ~ button.mollie-key-toggle, ` +
            `input[name="confstrs[${field}]"] + button.mollie-key-toggle`,
        )
        .first();
}

test.describe('Sprint 10: Mollie API keys are masked with a reveal toggle', () => {
    test('both API keys render as type="password" by default', async ({ page }) => {
        const edit = await openMollieSettingsForm(page);

        for (const field of SENSITIVE_FIELDS) {
            const input = edit.locator(`input[name="confstrs[${field}]"]`).first();
            await input.waitFor({ state: 'attached', timeout: 5000 });
            await expect(input, `${field} must be masked on load`).toHaveAttribute('type', 'password');
        }
    });

    test('each key has an adjacent toggle with aria-pressed and a non-empty aria-label', async ({ page }) => {
        const edit = await openMollieSettingsForm(page);

        for (const field of SENSITIVE_FIELDS) {
            const toggle = toggleFor(edit, field);
            await expect(toggle, `${field}: toggle must sit next to the input`).toBeAttached();
            await expect(toggle, `${field}: starts in the masked state`).toHaveAttribute('aria-pressed', 'false');

            const ariaLabel = await toggle.getAttribute('aria-label');
            expect(ariaLabel, `${field}: toggle needs an aria-label`).toBeTruthy();
            expect(ariaLabel!.trim().length, `${field}: aria-label must not be blank`).toBeGreaterThan(0);
            expect(ariaLabel, `${field}: aria-label must be translated, not a raw ident`)
                .not.toContain('MOLLIE_REVEAL_API_KEY');
        }
    });

    test('clicking the toggle reveals the key and clicking again re-masks it', async ({ page }) => {
        const edit = await openMollieSettingsForm(page);

        const field = 'sMollieTestKey';
        const input = edit.locator(`input[name="confstrs[${field}]"]`).first();
        const toggle = toggleFor(edit, field);

        await expect(input).toHaveAttribute('type', 'password');
        const maskedLabel = await toggle.getAttribute('aria-label');

        await toggle.click();
        await expect(input, 'revealed').toHaveAttribute('type', 'text');
        await expect(toggle).toHaveAttribute('aria-pressed', 'true');
        expect(await toggle.getAttribute('aria-label'), 'label must change when revealed').not.toBe(maskedLabel);

        await toggle.click();
        await expect(input, 're-masked').toHaveAttribute('type', 'password');
        await expect(toggle).toHaveAttribute('aria-pressed', 'false');
        await expect(toggle).toHaveAttribute('aria-label', maskedLabel!);
    });

    test('the toggle is keyboard-operable via Enter and Space', async ({ page }) => {
        const edit = await openMollieSettingsForm(page);

        const field = 'sMollieLiveKey';
        const input = edit.locator(`input[name="confstrs[${field}]"]`).first();
        const toggle = toggleFor(edit, field);

        await expect(input).toHaveAttribute('type', 'password');

        await toggle.focus();
        await toggle.press('Enter');
        await expect(input, 'Enter reveals').toHaveAttribute('type', 'text');

        await toggle.press(' ');
        await expect(input, 'Space re-masks').toHaveAttribute('type', 'password');
    });

    test('the key value survives a reveal/re-mask cycle unchanged', async ({ page }) => {
        const edit = await openMollieSettingsForm(page);

        // The toggle must only touch `type`, never `value` — otherwise revealing a key before saving
        // would silently discard an unsaved edit.
        const field = 'sMollieTestKey';
        const input = edit.locator(`input[name="confstrs[${field}]"]`).first();
        const toggle = toggleFor(edit, field);

        const original = await input.inputValue();

        await toggle.click();
        await expect(input).toHaveAttribute('type', 'text');
        expect(await input.inputValue(), 'value preserved on reveal').toBe(original);

        await toggle.click();
        await expect(input).toHaveAttribute('type', 'password');
        expect(await input.inputValue(), 'value preserved on re-mask').toBe(original);
    });

    test('non-secret settings still render — the override delegates to the rest of the chain', async ({ page }) => {
        const edit = await openMollieSettingsForm(page);

        // If `{{ parent() }}` were missing, these rows would vanish along with every other module's.
        for (const field of ['sMollieMode', 'sMollieCaptureMode', 'sMollieWebhookUrl', 'sMollieLogLevel']) {
            await expect(
                edit.locator(`[name="confselects[${field}]"], [name="confstrs[${field}]"]`).first(),
                `${field} must still render`,
            ).toBeAttached();
        }

        // And the public profile id must stay visible, not masked (it ships to the browser anyway).
        const profileId = edit.locator('input[name="confstrs[sMollieProfileId]"]').first();
        await expect(profileId).toBeAttached();
        await expect(profileId, 'sMollieProfileId is public by design').toHaveAttribute('type', 'text');
    });
});
