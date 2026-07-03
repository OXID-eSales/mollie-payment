import { Page, expect } from '@playwright/test';

/**
 * Drops a product into the basket without depending on any specific catalog entry.
 * Relies on OXID's default demodata (mirrors the PayPal/Stripe sibling helpers) — adjust the
 * selectors here if the shop under test uses a custom theme.
 */
export async function addFirstFeaturedProductToBasket(page: Page): Promise<void> {
    await page.goto('/');
    const firstProductLink = page.locator('[data-testid="featured-product-link"], a.product-details').first();
    await firstProductLink.click();
    await page.getByRole('button', { name: /add to (cart|basket)/i }).click();
    await expect(page.locator('.minicart, .basket-item-count')).toBeVisible();
}

export async function goToCheckoutPayment(page: Page): Promise<void> {
    await page.goto('/index.php?cl=payment');
}

/**
 * Selects the Mollie payment option (see `MollieDefinitions::PAYMENT_ID`) and, if a specific
 * method was requested, picks it from the storefront method selector rendered by
 * `views/twig/frontend/mollie_methods.html.twig` (`data-testid="mollie-methods"`).
 */
export async function selectMolliePaymentMethod(page: Page, method?: string): Promise<void> {
    await page.locator('[data-testid="mollie-payment-option"] input[value="oe_payments_mollie"]').check();

    if (method) {
        await page.locator(`[data-testid="mollie-methods"] input[value="${method}"]`).check();
    }
}

export async function continueToOrderReview(page: Page): Promise<void> {
    await page.getByRole('button', { name: /continue|weiter/i }).click();
}

/**
 * Mollie's TEST-mode hosted checkout does not ask for real card/iDEAL credentials — it shows a
 * simple "Change payment state" screen with explicit outcome buttons. This mirrors that (buttons
 * seen on https://www.mollie.com/checkout/... in test mode: "Pay.", "Fail.", "Expire.", ...).
 */
export async function completeMollieTestPayment(page: Page, outcome: 'paid' | 'failed' = 'paid'): Promise<void> {
    await expect(page).toHaveURL(/mollie\.com\/checkout/i);

    const buttonLabel = outcome === 'paid' ? /^pay\.?$/i : /^fail\.?$/i;
    await page.getByRole('button', { name: buttonLabel }).click();
}

export async function loginShopAdmin(page: Page, adminUser: string, adminPassword: string): Promise<void> {
    await page.goto('/admin');
    await page.locator('input[name="user"]').fill(adminUser);
    await page.locator('input[name="pwd"]').fill(adminPassword);
    await page.getByRole('button', { name: /login|anmelden/i }).click();
    await expect(page).toHaveURL(/admin/);
}
