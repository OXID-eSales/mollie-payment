import { Page } from '@playwright/test';
import { BasePage } from './BasePage';

export class CheckoutPage extends BasePage {
    private readonly selectors = {
        molliePaymentRadio: 'input[type="radio"][value="oe_payments_mollie"]',
        continueButton: 'button:has-text("Next"), button:has-text("Weiter"), button.btn-highlight.btn-lg',
        placeOrderButton: 'button:has-text("Zahlungspflichtig bestellen"), button:has-text("Place order"), button:has-text("Order now")',
        agbCheckbox: '#checkAgbTop, #checkAgb',
    };

    async selectMolliePayment(): Promise<void> {
        const mollieRadio = this.page.locator(this.selectors.molliePaymentRadio);
        if (await mollieRadio.isVisible({ timeout: 3000 }).catch(() => false)) {
            await mollieRadio.check();
            await this.page.waitForTimeout(500);
            console.log('  Selected Mollie payment');
        }
    }

    async continueToNextStep(): Promise<void> {
        const continueBtn = this.page.locator(this.selectors.continueButton).first();
        if (await continueBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
            await Promise.all([
                this.page.waitForLoadState('networkidle'),
                continueBtn.click(),
            ]);
            await this.page.waitForTimeout(500);
        }
    }

    async acceptAGB(): Promise<void> {
        const agbCheckbox = this.page.locator(this.selectors.agbCheckbox);
        if (await agbCheckbox.count() > 0) {
            const checked = await agbCheckbox.isChecked().catch(() => false);
            if (!checked) {
                await agbCheckbox.check();
                await this.page.waitForTimeout(300);
            }
        }
    }

    async placeOrder(): Promise<void> {
        const placeOrderBtn = this.page.locator(this.selectors.placeOrderButton).first();
        await placeOrderBtn.waitFor({ state: 'visible', timeout: 10000 });

        // Check for Mollie error before clicking
        const errorBefore = await this.page.locator('text=/MOLLIE/i').isVisible({ timeout: 1000 }).catch(() => false);
        if (errorBefore) {
            const errorText = await this.page.locator('.alert-danger, [role="alert"]').first().textContent().catch(() => '');
            console.log(`  Mollie error before click: ${errorText}`);
        }

        await Promise.all([
            this.page.waitForURL(/mollie\.com|cl=user/, { timeout: 30000 }),
            placeOrderBtn.click(),
        ]);

        // Check URL - if back to cl=user, Mollie redirect failed
        if (this.page.url().includes('cl=user')) {
            const body = await this.page.locator('body').innerText();
            if (body.includes('MOLLIE_CHECKOUT_UNAVAILABLE')) {
                throw new Error('Mollie checkout unavailable - API key may not be configured or module not activated');
            }
            throw new Error('Mollie redirect failed - back to user step');
        }

        console.log('  Clicked place order - redirecting to Mollie');
    }

    async navigateToPaymentStep(langId: 0 | 1 = 1): Promise<void> {
        await this.navigate(`/index.php?cl=user&lang=${langId}`);
        await this.waitForPageLoad();

        // Click continue to go to payment
        await this.continueToNextStep();
    }

    async completeCheckoutWithMollie(): Promise<void> {
        // Navigate to payment step
        await this.navigateToPaymentStep(1);

        // Select Mollie
        await this.selectMolliePayment();

        // Continue to order step
        await this.continueToNextStep();
        await this.page.waitForTimeout(1000);

        // Accept AGB if present
        await this.acceptAGB();

        // Place order
        await this.placeOrder();
    }
}