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

    // Fallback: submit the page's own tobasket form. The button label is
    // language- and theme-dependent, and DEMO_PRODUCT_URL is a German SEO path
    // that only resolves on one shop — on any other the getByRole above waits
    // out the whole test timeout for a control that was never rendered. The
    // form carries the right `aid` and `stoken` for THIS shop.
    const submitted = await page.evaluate(() => {
        const form = Array.from(document.querySelectorAll('form')).find((f) => {
            const fnc = f.querySelector('input[name="fnc"]') as HTMLInputElement | null;
            return fnc?.value === 'tobasket';
        });
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
    await page.locator('input[name="paymentid"][value="oe_payments_mollie"]').check();
}

/**
 * Advances from the payment step to the order-review step.
 */
export async function continueToOrderReview(page: Page): Promise<void> {
    await page.getByRole('button', { name: /weiter|continue|next/i }).first().click();
    await page.waitForLoadState('domcontentloaded');
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
    const paypalBtn = page.getByRole('button', { name: /^paypal$/i }).first();
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
 * Submit the OPC modal for Mollie, whichever footer is active — robust to the payment-base
 * "Use iframe instead of checkout button" flag:
 *  - flag ON  → the Mollie inline footer widget (pick a redirect method, click its own submit;
 *               the method rides through OPC's processCheckout into Mollie's create-payment)
 *  - flag OFF → the generic default-checkout-footer redirect button
 * Consent checkboxes are ticked either way. Both paths hand off to Mollie's hosted checkout.
 */
export async function submitOpcMollieFooter(modal: Locator): Promise<void> {
    for (const id of ['#confirmTermsCheckout', '#confirmPrivacyCheckout']) {
        const cb = modal.locator(id);
        if (await cb.count()) {
            await cb.check({ force: true }).catch(() => {});
        }
    }

    const inlineFooter = modal.locator('[data-controller~="mollie-checkout-footer"]');
    if (await inlineFooter.count()) {
        // Prefer PayPal (its Mollie test flow is the simple status page completeMollieTestPayment
        // drives); fall back to any non-card method, then any. Never card (needs a live token).
        const paypal = modal.locator('input[name="mollieMethod"][value="paypal"]').first();
        const nonCard = modal.locator('input[name="mollieMethod"]:not([value="creditcard"])').first();
        const method = (await paypal.count())
            ? paypal
            : ((await nonCard.count()) ? nonCard : modal.locator('input[name="mollieMethod"]').first());
        await method.check({ force: true }).catch(() => {});
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
