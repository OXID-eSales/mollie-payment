import { Page, Locator, expect } from '@playwright/test';

const EMAIL = process.env.TEST_USER_EMAIL || 'playwright.user@oxid-esales.dev';
const PW = process.env.TEST_USER_PASSWORD || 'useruser';

/**
 * A product-detail URL from the standard OXID demodata (Apex theme). Checkout requires a
 * non-empty basket; this drops one known article in without depending on the homepage slider
 * (whose overlay links are not reliably clickable headless).
 */
const DEMO_PRODUCT_URL =
    process.env.MOLLIE_E2E_PRODUCT_URL || '/Merchandise/Sonnenbrillen/Ocean-Eyes.html';

/**
 * Logs the storefront customer in via the account page. `cl=payment` bounces to the login/user
 * step for anonymous sessions, so every checkout spec must call this first.
 */
export async function loginStorefront(page: Page): Promise<void> {
    await page.goto('/index.php?cl=account');
    const loginForm = page.locator('form').filter({ has: page.locator('input[name="lgn_pwd"]:visible') }).first();
    await loginForm.locator('input[name="lgn_usr"]:visible').first().fill(EMAIL);
    await loginForm.locator('input[name="lgn_pwd"]:visible').first().fill(PW);
    await loginForm.locator('button[type="submit"], input[type="submit"]').first().click();
    await page.waitForLoadState('domcontentloaded');
}

/**
 * Drops a known demodata product into the basket from its detail page.
 */
export async function addFirstFeaturedProductToBasket(page: Page): Promise<void> {
    await page.goto(DEMO_PRODUCT_URL);
    await page.waitForLoadState('domcontentloaded');

    const button = page
        .getByRole('button', { name: /In den Warenkorb|add to (cart|basket)/i })
        .first();

    if (await button.isVisible({ timeout: 5000 }).catch(() => false)) {
        await button.click();
        await page.waitForLoadState('domcontentloaded');
        return;
    }

    // OPC replacement mode (shouldReplaceMinibasket — always on while OPC is enabled in
    // v1.6.6x): the standard Add-to-Cart button is gone and only OPC's Buy-Now button remains.
    // That button adds the product itself and opens the checkout modal; use it, then close
    // the modal so callers that walk the standard checkout start from a plain page.
    const buyNow = page.locator('[data-action*="buy-now#prepareBuyNow"][data-buy-now-product-id-param]').first();
    if (await buyNow.isVisible({ timeout: 2000 }).catch(() => false)) {
        await page.waitForFunction(() => !!(window as any).OnepageCheckout?.stimulus, null, { timeout: 15_000 }).catch(() => {});
        await buyNow.click({ force: true });
        const modal = page.locator('#buyNowCheckoutModal');
        await modal.waitFor({ state: 'visible', timeout: 20_000 }).catch(() => {});
        // The basket badge inside the modal header reflects the add; wait for it to leave 0.
        await page.waitForFunction(() => {
            const badge = document.querySelector('#buyNowCheckoutModal .badge, #buyNowCheckoutModal [class*="badge"]');
            return !!badge && /[1-9]/.test(badge.textContent || '');
        }, null, { timeout: 15_000 }).catch(() => {});
        await page.keyboard.press('Escape').catch(() => {});
        await page.waitForTimeout(800);
        return;
    }

    // Fallback: submit the page's own tobasket form. The button label is
    // language- and theme-dependent, and DEMO_PRODUCT_URL is a German SEO path
    // that only resolves on one shop — on any other the getByRole above waits
    // out the whole test timeout for a control that was never rendered. The
    // form carries the right `aid` and `stoken` for THIS shop.
    //
    // OPC "replacement mode" (shouldReplaceMinibasket) drops the standard
    // Add-to-Cart button entirely and renders only its Buy-Now button, so the
    // page holds several tobasket forms (related-product widgets) but none for
    // the main product under a button. Pick the form whose `aid` is the main
    // product — the Buy-Now button carries that id — before falling back to
    // the first tobasket form on the page.
    const submitted = await page.evaluate(() => {
        const mainId = document.querySelector('[data-buy-now-product-id-param]')?.getAttribute('data-buy-now-product-id-param');
        const forms = Array.from(document.querySelectorAll('form')).filter((f) => {
            const fnc = f.querySelector('input[name="fnc"]') as HTMLInputElement | null;
            return fnc?.value === 'tobasket';
        });
        const form = forms.find((f) => (f.querySelector('input[name="aid"]') as HTMLInputElement | null)?.value === mainId) || forms[0];
        if (!form) return false;
        (form as HTMLFormElement).submit();
        return true;
    });

    if (!submitted) {
        throw new Error(
            `No add-to-basket control and no tobasket form at ${DEMO_PRODUCT_URL}. ` +
                'Set MOLLIE_E2E_PRODUCT_URL to a product-detail URL that exists on the shop ' +
                'under test (e.g. /index.php?cl=details&anid=<oxid>).',
        );
    }
    await page.waitForLoadState('domcontentloaded');
}

/**
 * Walks the standard OXID checkout (basket -> user -> payment). The Apex theme advances with a
 * "Weiter"/"Continue"/"Next" button; we then land on the payment-method step.
 */
export async function goToCheckoutPayment(page: Page): Promise<void> {
    await page.goto('/index.php?cl=user');
    await page.waitForLoadState('domcontentloaded');
    await page.getByRole('button', { name: /weiter|continue|next/i }).first().click();
    await page.waitForLoadState('domcontentloaded');
    if (!/cl=payment/.test(page.url())) {
        await page.goto('/index.php?cl=payment');
        await page.waitForLoadState('domcontentloaded');
    }
}

/**
 * Selects the Mollie payment option. Mollie is a plain OXID payment radio
 * (`paymentid=oe_payments_mollie`); there is no storefront sub-method selector — the individual
 * methods (iDEAL, card, PayPal, …) are chosen on Mollie's own hosted checkout page. The `method`
 * argument is therefore accepted for call-site readability but is a no-op here.
 */
export async function selectMolliePaymentMethod(page: Page, _method?: string): Promise<void> {
    // payment-base skips the payment step when the customer is offered exactly one payment
    // method and one delivery set: cl=payment redirects straight to cl=order and there is no
    // radio to pick. Mollie is then already the method.
    if (/cl=order/.test(page.url())) {
        return;
    }
    await page.locator('input[name="paymentid"][value="oe_payments_mollie"]').check();
}

/**
 * Advances from the payment step to the order-review step (no-op when the step was skipped).
 */
export async function continueToOrderReview(page: Page): Promise<void> {
    if (/cl=order/.test(page.url())) {
        return;
    }
    await page.getByRole('button', { name: /weiter|continue|next/i }).first().click();
    await page.waitForLoadState('domcontentloaded');
}

/**
 * Ticks the AGB / Terms-and-Conditions checkbox on the order-review step (rendered only when
 * blConfirmAGB is active). MollieOrderController::execute() enforces the core terms validation
 * server-side — "Order now" without it re-renders the order step with READ_AND_CONFIRM_TERMS
 * (see AgbRequiredBlocksCheckout.spec.ts). No-op when the shop doesn't render the checkbox.
 */
export async function acceptTermsAndConditions(page: Page): Promise<void> {
    const agb = page.locator('#checkAgbTop, input[name="ord_agb"][type="checkbox"]').first();
    if (await agb.count()) {
        await agb.check({ force: true });
    }
}

/**
 * On inline-components shops the order page lists the Mollie methods; the CARD radio hands
 * submit to the Components JS (client-side tokenization), which never POSTs the form. Specs
 * that need the server-side `cl=order&fnc=execute` path pick a redirect method instead —
 * PayPal preferred (its Mollie test flow is the simple status page), else any non-card.
 *
 * Returns 'none' (classic redirect flow, no selector rendered), 'picked' (redirect method
 * selected), or 'card-only' (only card offered — callers should test.skip: the guard under
 * test cannot be reached through the Components JS).
 */
export async function pickRedirectMollieMethod(page: Page): Promise<'none' | 'picked' | 'card-only'> {
    const radios = page.locator('input[name="mollieMethod"]');
    if ((await radios.count()) === 0) {
        return 'none';
    }

    const paypal = page.locator('input[name="mollieMethod"][value="paypal"]').first();
    const nonCard = page.locator('input[name="mollieMethod"]:not([value="creditcard"])').first();
    if ((await nonCard.count()) === 0) {
        return 'card-only';
    }

    await ((await paypal.count()) ? paypal : nonCard).check({ force: true });

    return 'picked';
}

/**
 * Completes payment on Mollie's TEST-mode hosted checkout.
 *
 * Test mode first shows a method-selection page (the methods enabled in the merchant's Mollie
 * dashboard). We pick PayPal, a redirect-style method whose test page is the simple
 * "select the final payment status" screen (radios Pending/Paid/Failed/Canceled/Expired + a
 * "Continue" button) — as opposed to the card method, which renders a full card-entry form.
 */
export async function completeMollieTestPayment(page: Page, outcome: 'paid' | 'failed' = 'paid'): Promise<void> {
    await expect(page).toHaveURL(/mollie\.com\/checkout/i, { timeout: 30_000 });

    // Method-selection page -> PayPal. When a specific method was already forced (the OPC inline
    // widget sends method=paypal), Mollie skips its method-selection page and lands directly on the
    // test-mode status screen, so the PayPal button is absent — click it only if it is shown.
    // Mollie labels the method tiles "<icon alt> <name>" - "PayPal PayPal" on the classic
    // (no preselected method) page - so the match allows the doubled word.
    const paypalBtn = page.getByRole('button', { name: /^paypal(\s+paypal)?$/i }).first();
    if (await paypalBtn.isVisible({ timeout: 5_000 }).catch(() => false)) {
        await paypalBtn.click();
    }
    await page.waitForURL(/mollie\.com\/checkout\/test-mode/i, { timeout: 30_000 }).catch(() => {});

    // Test-mode status page -> pick the outcome, then continue.
    const status = outcome === 'paid' ? 'Paid' : 'Failed';
    await page.getByText(status, { exact: true }).click();
    await page.getByRole('button', { name: /continue/i }).click();
}

/**
 * Opens the one-page-checkout modal from the basket.
 *
 * Prefers OPC's minibasket trigger (`buy-now#openCheckoutFromBasket`): a product page also
 * carries one `buy-now#prepareBuyNow` button per listed product, and a comma-joined selector
 * with `.first()` picks whichever comes first in the DOM — on the demo product page that is a
 * per-product button whose click leaves `#buyNowCheckoutModal` hidden. Falls back to the other
 * triggers only when no basket trigger is rendered.
 */
export async function openOpcCheckoutModal(page: Page): Promise<Locator> {
    // The trigger is wired by OPC's Stimulus app; a click before that app has connected (the
    // add-to-basket navigation resolves at domcontentloaded, scripts still loading) is lost.
    await page.waitForLoadState('networkidle').catch(() => {});
    await page.waitForFunction(() => !!(window as any).OnepageCheckout?.stimulus, null, { timeout: 15_000 }).catch(() => {});

    const basketTrigger = page.locator('[data-action*="buy-now#openCheckoutFromBasket"]').first();
    const trigger = (await basketTrigger.count())
        ? basketTrigger
        : page.locator('[data-action*="buy-now#prepareBuyNow"], .onepage-buy-now').first();
    const modal = page.locator('#buyNowCheckoutModal');

    for (let attempt = 0; attempt < 3; attempt++) {
        await trigger.scrollIntoViewIfNeeded().catch(() => {});
        await trigger.click({ force: true });
        if (await modal.isVisible({ timeout: 7_000 }).catch(() => false)) {
            return modal;
        }
    }
    await expect(modal).toBeVisible({ timeout: 5_000 });
    return modal;
}

/**
 * True when OPC has folded the `payment-execution` section (the only place a provider footer
 * renders in iframe mode) into its "phantom" state. OPC does this for a single available payment
 * method (OPC-208 single-method fold, `PaymentMethodController`); in iframe mode the picker lives
 * INSIDE payment-execution, so the fold hides the provider footer with it and nothing on the modal
 * can start a payment. Measured on daniil.oxiddev.de 2026-09-21 (Mollie the only method, iframe
 * flag on). Specs that need the footer visible skip loudly on this instead of failing on OPC.
 */
export async function opcPaymentSectionFolded(modal: Locator): Promise<boolean> {
    const item = modal.locator('#accordion-collapse-payment-execution').locator('xpath=ancestor::*[contains(@class,"accordion-item")][1]');
    if (!(await item.count())) {
        return false;
    }
    return item.evaluate((el) => (el as HTMLElement).hidden || el.classList.contains('opc-section--phantom'));
}

/**
 * Waits until OPC has settled the payment section after the modal opened: either the payment
 * select is usable ('select', 2+ methods) or the section has been folded ('folded', single
 * method under payment-base auto-assign — the select stays disabled forever in that state).
 */
/**
 * 'select'  — 2+ methods, the select is usable;
 * 'auto'    — single method: OPC folded only the picker (select disabled, method auto-assigned)
 *             and the section with the provider footer stays visible — the fixed OPC behaviour;
 * 'folded'  — single method and OPC folded the whole payment-execution section (OPC defect).
 */
export type OpcPaymentState = 'select' | 'auto' | 'folded';

export async function waitForOpcPaymentState(modal: Locator): Promise<OpcPaymentState> {
    const select = modal.locator('#paymentMethodSelect');
    const picker = modal.locator('.opc-payment-method-picker').first();
    const pickerFolded = async (): Promise<boolean> =>
        (await picker.count()) > 0 && (await picker.evaluate((el) => (el as HTMLElement).hidden || el.classList.contains('opc-section--phantom')));
    const deadline = Date.now() + 30_000;
    while (Date.now() < deadline) {
        if (await opcPaymentSectionFolded(modal)) {
            return 'folded';
        }
        if (await pickerFolded()) {
            return 'auto';
        }
        if ((await select.count()) && (await select.isEnabled().catch(() => false))) {
            // The select is enabled for a moment after the payment list loads and only THEN
            // folded for a single method — let that decision land before answering: poll a
            // few seconds for the fold, and treat a select that went disabled meanwhile as
            // the single-method auto-assignment too.
            const settleUntil = Date.now() + 6_000;
            while (Date.now() < settleUntil) {
                if (await opcPaymentSectionFolded(modal)) {
                    return 'folded';
                }
                if ((await pickerFolded()) || !(await select.isEnabled().catch(() => false))) {
                    return 'auto';
                }
                await modal.page().waitForTimeout(500);
            }
            return 'select';
        }
        await modal.page().waitForTimeout(500);
    }
    if (await opcPaymentSectionFolded(modal)) {
        return 'folded';
    }
    return (await pickerFolded()) ? 'auto' : 'select';
}

/**
 * Selects Mollie in the OPC modal when a selection is offered, and reports the settled state.
 * In the single-method case OPC disables the select and auto-assigns the method — sometimes a
 * beat AFTER the state check saw it enabled — so a failed selection on a now-disabled select is
 * read as 'auto', not as an error.
 */
export async function chooseMollieInOpcModal(modal: Locator): Promise<OpcPaymentState> {
    const state = await waitForOpcPaymentState(modal);
    if (state !== 'select') {
        return state;
    }
    const select = modal.locator('#paymentMethodSelect');
    try {
        await select.selectOption('oe_payments_mollie', { force: true, timeout: 5_000 });
        await select.dispatchEvent('change');
        return 'select';
    } catch {
        return (await select.isDisabled().catch(() => false)) ? 'auto' : 'select';
    }
}

export const OPC_FOLD_SKIP = 'OPC folds the payment-execution section for a single payment method in iframe mode (OPC defect, see opcPaymentSectionFolded) — the provider footer is hidden, the payment leg cannot be driven';

/**
 * Ticks OPC's consent checkboxes. They live in the (collapsed) consents accordion section as
 * `#confirmTermsAccordion` / `#confirmPrivacyAccordion` / `#confirmWithdrawalAccordion`; the old
 * `#confirmTermsCheckout` / `#confirmPrivacyCheckout` ids are gone. Ticked at DOM level because
 * the section is folded, then the provider's submit button is awaited: checkout-footer-manager
 * keeps every button inside `#dynamic-footer-content` disabled until the checkout validates.
 */
export async function tickOpcConsents(modal: Locator): Promise<void> {
    const boxes = modal.locator(
        '#confirmTermsAccordion, #confirmPrivacyAccordion, #confirmWithdrawalAccordion, #confirmTermsCheckout, #confirmPrivacyCheckout',
    );
    if (await boxes.count()) {
        await boxes.evaluateAll((els) => els.forEach((e) => {
            const cb = e as HTMLInputElement;
            if (!cb.checked) { cb.checked = true; cb.dispatchEvent(new Event('input', { bubbles: true })); cb.dispatchEvent(new Event('change', { bubbles: true })); }
        }));
    }
    const submit = modal.locator('#dynamic-footer-content button, [data-mollie-checkout-footer-target="submitButton"]').first();
    if (await submit.count()) {
        await expect(submit, 'provider submit enabled once the checkout validates').toBeEnabled({ timeout: 20_000 });
    }
}

/**
 * Picks a Mollie method radio at DOM level. Inside the OPC modal the provider region is `inert`
 * until the checkout validates (section-lock), so a real click "does not change its state";
 * setting `checked` and dispatching `change` reaches the footer controller all the same.
 */
export async function pickMollieMethodRadio(radio: Locator): Promise<void> {
    await radio.evaluate((el) => {
        const r = el as HTMLInputElement;
        r.checked = true;
        r.dispatchEvent(new Event('change', { bubbles: true }));
    });
}

/**
 * Submit the OPC modal for Mollie, whichever footer is active — robust to the payment-base
 * "Use iframe instead of checkout button" flag:
 *  - flag ON  → the Mollie inline footer widget (pick a redirect method, click its own submit;
 *               the method rides through OPC's processCheckout into Mollie's create-payment)
 *  - flag OFF → the generic default-checkout-footer redirect button
 * Consent checkboxes are ticked either way. Both paths hand off to Mollie's hosted checkout.
 */
export async function submitOpcMollieFooter(modal: Locator): Promise<void> {
    await tickOpcConsents(modal);

    const inlineFooter = modal.locator('[data-controller~="mollie-checkout-footer"]');
    if (await inlineFooter.count()) {
        // Prefer PayPal (its Mollie test flow is the simple status page completeMollieTestPayment
        // drives); fall back to any non-card method, then any. Never card (needs a live token).
        const paypal = modal.locator('input[name="mollieMethod"][value="paypal"]').first();
        const nonCard = modal.locator('input[name="mollieMethod"]:not([value="creditcard"])').first();
        const method = (await paypal.count())
            ? paypal
            : ((await nonCard.count()) ? nonCard : modal.locator('input[name="mollieMethod"]').first());
        await pickMollieMethodRadio(method);
        await modal.locator('[data-mollie-checkout-footer-target="submitButton"]').first().click();
        return;
    }

    await modal
        .locator('[data-action*="default-checkout-footer#processPayment"], [data-default-checkout-footer-target="submitButton"]')
        .first()
        .click();
}

export async function loginShopAdmin(page: Page, adminUser: string, adminPassword: string): Promise<void> {
    await page.goto('/admin');
    await page.locator('input[name="user"]').fill(adminUser);
    await page.locator('input[name="pwd"]').fill(adminPassword);
    await page.getByRole('button', { name: /login|anmelden/i }).click();
    await expect(page).toHaveURL(/admin/);
}
