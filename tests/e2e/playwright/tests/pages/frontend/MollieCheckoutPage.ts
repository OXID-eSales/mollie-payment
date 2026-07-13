import { Page, Locator, expect } from '@playwright/test';

/**
 * Mollie Hosted Checkout page (test mode).
 *
 * After the shop redirects to Mollie, we:
 * 1. Select a payment method (PayPal, iDEAL, card, etc.)
 * 2. On the test-mode screen, select the payment outcome (Paid/Failed/Pending/Canceled)
 * 3. Click Continue to complete
 */
export class MollieCheckoutPage {
    readonly page: Page;

    constructor(page: Page) {
        this.page = page;
    }

    /**
     * Wait for Mollie hosted checkout URL and select a payment method.
     */
    async waitForMollieCheckout(): Promise<void> {
        await expect(this.page).toHaveURL(/mollie\.com\/checkout/i, { timeout: 30_000 });
        // Wait for the method buttons to appear
        await this.page.waitForLoadState('domcontentloaded');
    }

    /**
     * Select a payment method by name (PayPal, iDEAL, creditcard, etc.)
     */
    async selectPaymentMethod(method: string = 'paypal'): Promise<void> {
        const methodBtn = this.page.getByRole('button', { name: new RegExp(`^${method}$`, 'i') });
        await methodBtn.waitFor({ state: 'visible', timeout: 15_000 });
        await methodBtn.click();
        await this.page.waitForLoadState('domcontentloaded');
    }

    /**
     * On Mollie's test-mode screen, select the payment status and continue.
     */
    async completeTestPayment(outcome: 'Paid' | 'Failed' | 'Pending' | 'Canceled' = 'Paid'): Promise<void> {
        // Wait for test-mode URL
        await expect(this.page).toHaveURL(/mollie\.com\/checkout\/test-mode/i, { timeout: 15_000 });

        // Select the status radio
        const statusOption = this.page.getByText(outcome, { exact: true });
        await statusOption.waitFor({ state: 'visible', timeout: 10_000 });
        await statusOption.click();

        // Click Continue
        const continueBtn = this.page.getByRole('button', { name: /continue/i });
        await continueBtn.waitFor({ state: 'visible', timeout: 5_000 });
        await continueBtn.click();
    }

    /**
     * Full flow: wait for checkout, select method, complete with status.
     */
    async completePayment(method: string = 'paypal', outcome: 'Paid' | 'Failed' = 'Paid'): Promise<void> {
        await this.waitForMollieCheckout();
        await this.selectPaymentMethod(method);
        await this.completeTestPayment(outcome);
    }

    /**
     * Wait for redirect back to shop.
     */
    async waitForRedirectBack(shopUrl: string): Promise<void> {
        const escaped = shopUrl.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        await this.page.waitForURL(new RegExp(escaped), { timeout: 60_000 });
    }
}