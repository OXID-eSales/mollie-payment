import { AdminBasePage } from './AdminBasePage';

export class AdminMollieOrderPage extends AdminBasePage {
    private readonly selectors = {
        molliePanel: '[data-testid*="mollie"], .mollie-panel, #mollie-panel',
        refundForm: '#mollieRefundForm, form[name="mollieRefundForm"]',
        refundAmountInput: 'input[name="refund_amount"]',
        refundDescriptionInput: 'input[name="refund_description"]',
        refundSubmitButton: 'input[type="submit"][value*="refund"], button:has-text("Refund")',
        capturedAmount: '[data-testid="captured-amount"]',
        refundableAmount: 'text=/refundable/i',
        transactionHistory: '[data-testid*="transaction"], .transaction-history',
        molliePaymentId: 'text=/Mollie payment ID|payment id/i',
        errorAlert: '.alert-danger, .pc-alert-danger',
    };

    async navigateToOrder(orderId: string): Promise<void> {
        await this.navigate(`/index.php?cl=order_overview&oxid=${orderId}`);
        await this.page.waitForLoadState('networkidle');
        await this.page.waitForTimeout(1000);
    }

    async openMollieTab(): Promise<void> {
        const mollieTab = this.page.locator('a:has-text("Mollie"), a:has-text("Payment"), a[href*="payment"]').first();
        if (await mollieTab.isVisible({ timeout: 5000 }).catch(() => false)) {
            await mollieTab.click();
            await this.page.waitForLoadState('networkidle');
            await this.page.waitForTimeout(1000);
        }
    }

    async isMolliePanelVisible(): Promise<boolean> {
        const panel = this.page.locator(this.selectors.molliePanel).first();
        return panel.isVisible({ timeout: 5000 }).catch(() => false);
    }

    async getCapturedAmount(): Promise<string | null> {
        const captured = this.page.locator(this.selectors.capturedAmount).first();
        if (await captured.isVisible({ timeout: 3000 }).catch(() => false)) {
            return captured.textContent();
        }
        return null;
    }

    async executePartialRefund(amount: string, description?: string): Promise<boolean> {
        const refundForm = this.page.locator(this.selectors.refundForm).first();
        if (!await refundForm.isVisible({ timeout: 3000 }).catch(() => false)) {
            console.log('  Refund form not visible');
            return false;
        }

        const amountInput = this.page.locator(this.selectors.refundAmountInput);
        await amountInput.fill(amount);

        if (description) {
            const descInput = this.page.locator(this.selectors.refundDescriptionInput);
            if (await descInput.isVisible({ timeout: 1000 }).catch(() => false)) {
                await descInput.fill(description);
            }
        }

        const submitBtn = this.page.locator(this.selectors.refundSubmitButton).first();
        if (!await submitBtn.isVisible({ timeout: 2000 }).catch(() => false)) {
            console.log('  Refund submit button not visible');
            return false;
        }

        await Promise.all([
            this.page.waitForLoadState('networkidle'),
            submitBtn.click(),
        ]);

        await this.page.waitForTimeout(2000);

        // Check for errors
        const errorAlert = this.page.locator(this.selectors.errorAlert);
        if (await errorAlert.isVisible({ timeout: 3000 }).catch(() => false)) {
            const errorText = await errorAlert.textContent();
            console.log(`  Refund error: ${errorText}`);
            return false;
        }

        console.log(`  Refund of ${amount} executed`);
        return true;
    }

    async getTransactionHistory(): Promise<string[]> {
        const history = this.page.locator(this.selectors.transactionHistory).first();
        if (!await history.isVisible({ timeout: 3000 }).catch(() => false)) {
            return [];
        }

        const rows = await history.locator('tr, .transaction-row').allTextContents();
        return rows;
    }

    async getMolliePaymentId(): Promise<string | null> {
        const paymentIdElement = this.page.locator(this.selectors.molliePaymentId).first();
        if (!await paymentIdElement.isVisible({ timeout: 3000 }).catch(() => false)) {
            return null;
        }

        const text = await paymentIdElement.textContent();
        const match = text?.match(/(tr_[a-zA-Z0-9]+)/);
        return match ? match[1] : null;
    }
}