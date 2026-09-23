import { Page, Frame, expect } from '@playwright/test';
import { AdminBasePage } from './AdminBasePage';

export interface MolliePaymentDetails {
    contractId: string | null;
    orderId: string | null;
    paymentType: string;
    transactionId: string;
    dashboardLink: string | null;
}

export class AdminMollieOrderPage extends AdminBasePage {
    private readonly selectors = {
        // Payment details from mollie_panel.html.twig
        molliePanel: '[data-testid="mollie-panel-card"]',
        contractIdCell: '[data-testid="contract-id"]',
        molliePaymentIdCell: '[data-testid="mollie-payment-id"]',
        capturedAmount: '[data-testid="captured-amount"]',
        refundedAmount: '[data-testid="refunded-amount"]',
        dashboardLink: 'a[data-testid="mollie-dashboard-link"]',

        // Refund form
        refundForm: '#mollieRefundForm',
        refundAmountInput: '#refund_amount',
        refundDescriptionInput: '#refund_description',
        refundReasonSelect: '#refund_reason',
        refundSubmitButton: 'input[data-testid="refund-submit"]',
        refundSuccessMessage: 'text=/Erstattung.*erfolgreich|refund.*successful/i',

        // Capture form
        captureForm: '#mollieCaptureForm',
        captureAmountInput: '#capture_amount',
        captureSubmitButton: 'input[data-testid="capture-submit"]',

        // Cancel-authorization form
        cancelForm: '#mollieCancelForm',
        cancelReasonSelect: '#cancel_reason',
        cancelSubmitButton: 'input[data-testid="cancel-submit"]',

        // No contract notice
        noContractNotice: '[data-testid="mollie-no-contract"]',

        // Transaction history
        transactionHistory: '[data-testid="mollie-transaction-history"]',

        // Addresses tab (core order_address.html.twig, tab id "tbclorder_address" —
        // see Sprint 2026-09-23/01, Story 1)
        billingLastNameInput: 'input[name="editval[oxorder__oxbilllname]"]',
        shippingLastNameInput: 'input[name="editval[oxorder__oxdellname]"]',
    };

    /**
     * Switches to the order's "Addresses"/"Adressen" tab (core `order_address` controller, tab
     * id `tbclorder_address`). Same tab-bar pattern as `AdminOrdersPage.openPaymentTab()` —
     * duplicated here rather than extracted, per the module's "extract on the third occurrence"
     * rule (Sprint 2026-09-23/01, Story 1).
     */
    async openAddressesTab(): Promise<void> {
        const listFrame = this.getListFrame();
        if (!listFrame) {
            throw new Error('List frame not found');
        }

        const addressesTab = listFrame
            .locator('table.tabs a, .tabs a, [id^="tbcl"]')
            .filter({ hasText: /^(Addresses|Adressen)$/ })
            .first();

        let clicked = false;
        if (await addressesTab.isVisible({ timeout: 3000 }).catch(() => false)) {
            await addressesTab.click();
            clicked = true;
        } else {
            const fallback = listFrame.locator('a').filter({ hasText: /^(Addresses|Adressen)$/ }).first();
            if (await fallback.isVisible({ timeout: 3000 }).catch(() => false)) {
                await fallback.click();
                clicked = true;
            }
        }

        if (!clicked) {
            throw new Error('Addresses tab link not found');
        }

        await this.page.waitForTimeout(2000);
    }

    /**
     * Reads the billing last name (`editval[oxorder__oxbilllname]`) from the Addresses tab.
     */
    async billingLastName(): Promise<string> {
        const editFrame = this.getEditFrame();
        if (!editFrame) return '';

        return (await editFrame.locator(this.selectors.billingLastNameInput).inputValue({ timeout: 5000 }).catch(() => '')).trim();
    }

    /**
     * Reads the shipping last name (`editval[oxorder__oxdellname]`) from the Addresses tab. Core
     * only fills this when a separate `oxaddress` row was selected as `deladrid` — empty here,
     * with a non-empty billing last name, is the ticket's defect (Sprint 2026-09-23/01, Story 1).
     */
    async shippingLastName(): Promise<string> {
        const editFrame = this.getEditFrame();
        if (!editFrame) return '';

        return (await editFrame.locator(this.selectors.shippingLastNameInput).inputValue({ timeout: 5000 }).catch(() => '')).trim();
    }

    /**
     * Get payment details from the Mollie panel.
     */
    async getMolliePaymentDetails(): Promise<MolliePaymentDetails | null> {
        const editFrame = this.getEditFrame();
        if (!editFrame) return null;

        // Quick wait for panel (max 5s)
        await editFrame.locator(this.selectors.molliePanel).waitFor({ timeout: 5000 }).catch(() => {});

        // Get contract ID
        let contractId: string | null = null;
        try {
            const contractIdText = await editFrame.locator(this.selectors.contractIdCell).textContent({ timeout: 3000 });
            contractId = contractIdText?.trim() === '—' ? null : (contractIdText?.trim() || null);
        } catch {}

        // Get payment ID - try code element, fallback to link
        let transactionId = '';
        try {
            const codeEl = editFrame.locator(this.selectors.molliePaymentIdCell);
            if (await codeEl.isVisible({ timeout: 1000 }).catch(() => false)) {
                transactionId = (await codeEl.textContent({ timeout: 2000 }))?.trim() || '';
            }
        } catch {}

        // Get dashboard link if visible
        let dashboardLink: string | null = null;
        try {
            const linkEl = editFrame.locator(this.selectors.dashboardLink);
            if (await linkEl.isVisible({ timeout: 1000 }).catch(() => false)) {
                dashboardLink = await linkEl.getAttribute('href', { timeout: 1000 }).catch(() => null);
            }
        } catch {}

        return {
            contractId,
            orderId: null,
            paymentType: 'Mollie',
            transactionId,
            dashboardLink,
        };
    }

    /**
     * Check if refund form is visible and refundable.
     */
    async isRefundButtonVisible(): Promise<boolean> {
        const editFrame = this.getEditFrame();
        if (!editFrame) return false;

        return editFrame.locator(this.selectors.refundSubmitButton).isVisible({ timeout: 3000 }).catch(() => false);
    }

    /**
     * Get the refundable amount from the form.
     */
    async getRefundableAmount(): Promise<number> {
        const editFrame = this.getEditFrame();
        if (!editFrame) return 0;

        const amountInput = editFrame.locator(this.selectors.refundAmountInput);
        const value = await amountInput.inputValue({ timeout: 3000 }).catch(() => '0');
        return parseFloat(value.replace(',', '.')) || 0;
    }

    /**
     * Execute a refund. When amount is provided, sets partial amount.
     */
    async executeRefund(reason: string = 'requested_by_customer', amount?: number, description?: string): Promise<boolean> {
        const editFrame = this.getEditFrame();
        if (!editFrame) return false;

        // Set amount if provided (partial refund)
        if (amount !== undefined) {
            const amountInput = editFrame.locator(this.selectors.refundAmountInput);
            if (await amountInput.isVisible({ timeout: 3000 }).catch(() => false)) {
                // Clear and fill to ensure the new value is set
                await amountInput.clear();
                await amountInput.fill(amount.toString());
                // Verify the value was set
                const currentValue = await amountInput.inputValue();
                console.log(`  Set refund amount: ${amount} (input shows: ${currentValue})`);
            }
        }

        // Set description if provided
        if (description) {
            const descInput = editFrame.locator(this.selectors.refundDescriptionInput);
            if (await descInput.isVisible({ timeout: 2000 }).catch(() => false)) {
                await descInput.fill(description);
            }
        }

        // Select reason
        const reasonSelect = editFrame.locator(this.selectors.refundReasonSelect);
        if (await reasonSelect.isVisible({ timeout: 3000 }).catch(() => false)) {
            await reasonSelect.selectOption({ value: reason }).catch(async () => {
                await reasonSelect.selectOption({ index: 1 }).catch(() => {});
            });
        }

        // Click refund button (dialog is handled at test level)
        const refundBtn = editFrame.locator(this.selectors.refundSubmitButton);
        if (await refundBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
            await refundBtn.click();
            await this.page.waitForLoadState('networkidle').catch(() => {});
            await this.page.waitForTimeout(2000);
            return true;
        }

        return false;
    }

    /**
     * Check if refund was successful (success message visible).
     */
    async wasRefundSuccessful(): Promise<boolean> {
        const editFrame = this.getEditFrame();
        if (!editFrame) return false;

        return editFrame.locator(this.selectors.refundSuccessMessage).isVisible({ timeout: 5000 }).catch(() => false);
    }

    /**
     * Check if order is fully refunded (no refund button visible).
     */
    async isOrderFullyRefunded(): Promise<boolean> {
        const refundVisible = await this.isRefundButtonVisible();
        const captureVisible = await this.isCaptureButtonVisible();
        return !refundVisible && !captureVisible;
    }

    /**
     * Check if capture button is visible.
     */
    async isCaptureButtonVisible(): Promise<boolean> {
        const editFrame = this.getEditFrame();
        if (!editFrame) return false;

        return editFrame.locator(this.selectors.captureSubmitButton).isVisible({ timeout: 3000 }).catch(() => false);
    }

    /**
     * Execute capture on the order.
     */
    async executeCapture(amount?: number, reason?: string): Promise<boolean> {
        const editFrame = this.getEditFrame();
        if (!editFrame) return false;

        if (amount !== undefined) {
            const amountInput = editFrame.locator(this.selectors.captureAmountInput);
            if (await amountInput.isVisible({ timeout: 3000 }).catch(() => false)) {
                await amountInput.fill(amount.toString());
            }
        }

        const captureBtn = editFrame.locator(this.selectors.captureSubmitButton);
        if (await captureBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
            await captureBtn.click();
            await this.page.waitForLoadState('networkidle').catch(() => {});
            await this.page.waitForTimeout(2000);
            return true;
        }

        return false;
    }

    /**
     * Check if the cancel-authorization button is visible (shown only for an authorized,
     * uncaptured two-step contract).
     */
    async isCancelButtonVisible(): Promise<boolean> {
        const editFrame = this.getEditFrame();
        if (!editFrame) return false;

        return editFrame.locator(this.selectors.cancelSubmitButton).isVisible({ timeout: 3000 }).catch(() => false);
    }

    /**
     * Read the capturable bound (the capture amount input defaults to it). 0 if not present.
     */
    async getCaptureableAmount(): Promise<number> {
        const editFrame = this.getEditFrame();
        if (!editFrame) return 0;

        const input = editFrame.locator(this.selectors.captureAmountInput);
        if (!(await input.isVisible({ timeout: 3000 }).catch(() => false))) return 0;
        const value = await input.inputValue().catch(() => '0');
        return parseFloat(value || '0') || 0;
    }

    /**
     * True when the panel is showing a validation-error alert.
     */
    async hasValidationError(): Promise<boolean> {
        const editFrame = this.getEditFrame();
        if (!editFrame) return false;

        return editFrame
            .locator('[data-testid="mollie-validation-errors"]')
            .isVisible({ timeout: 2000 })
            .catch(() => false);
    }

    /**
     * Cancel (void) the authorization. Mollie cancel is all-or-nothing — no amount.
     * The caller must accept the native confirm() dialog (page.on('dialog', d => d.accept())).
     */
    async executeCancel(reason: string = 'requested_by_customer'): Promise<boolean> {
        const editFrame = this.getEditFrame();
        if (!editFrame) return false;

        const reasonSelect = editFrame.locator(this.selectors.cancelReasonSelect);
        if (await reasonSelect.isVisible({ timeout: 3000 }).catch(() => false)) {
            await reasonSelect.selectOption({ value: reason }).catch(() => {});
        }

        const cancelBtn = editFrame.locator(this.selectors.cancelSubmitButton);
        if (await cancelBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
            await cancelBtn.click();
            await this.page.waitForLoadState('networkidle').catch(() => {});
            await this.page.waitForTimeout(2000);
            return true;
        }

        return false;
    }

    /**
     * Wait for Mollie panel content to load.
     */
    async waitForContentLoaded(): Promise<void> {
        const editFrame = this.getEditFrame();
        if (!editFrame) return;

        await editFrame.locator(this.selectors.molliePanel)
            .waitFor({ timeout: 15000 })
            .catch(() => {});
    }

    /**
     * Check if Mollie panel is visible (contract exists).
     */
    async isMolliePanelVisible(): Promise<boolean> {
        const editFrame = this.getEditFrame();
        if (!editFrame) return false;

        const panelVisible = await editFrame.locator(this.selectors.molliePanel).isVisible({ timeout: 5000 }).catch(() => false);
        const noContractVisible = await editFrame.locator(this.selectors.noContractNotice).isVisible({ timeout: 2000 }).catch(() => false);

        return panelVisible && !noContractVisible;
    }

    /**
     * Get the captured amount as a number.
     */
    async getCapturedAmount(): Promise<number> {
        const editFrame = this.getEditFrame();
        if (!editFrame) return 0;

        const text = await editFrame.locator(this.selectors.capturedAmount).textContent({ timeout: 3000 }).catch(() => '');
        const match = text?.match(/([\d.,]+)/);
        if (match) {
            return parseFloat(match[1].replace(',', '.')) || 0;
        }
        return 0;
    }

    /**
     * Get transaction history rows.
     */
    async getTransactionHistory(): Promise<string[]> {
        const editFrame = this.getEditFrame();
        if (!editFrame) return [];

        const history = editFrame.locator(this.selectors.transactionHistory);
        if (!await history.isVisible({ timeout: 3000 }).catch(() => false)) {
            return [];
        }

        const rows = await history.locator('tr[data-testid="mollie-transaction-row"]').allTextContents();
        return rows;
    }
}