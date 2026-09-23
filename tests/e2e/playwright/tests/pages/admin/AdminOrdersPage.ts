import { AdminBasePage } from './AdminBasePage';

export class AdminOrdersPage extends AdminBasePage {
    private readonly selectors = {
        administerOrdersLink: 'a:has-text("Administer Orders")',
        ordersLink: 'a:has-text("Orders")',
        paymentTab: 'a:has-text("Payment"), a:has-text("Zahlung")',
        orderRowWithName: (name: string) => `table tr:has-text("${name}")`,
    };

    async navigateToOrders(): Promise<void> {
        const menuFrame = this.getMenuFrame();
        if (!menuFrame) {
            throw new Error('Menu frame not found');
        }

        // The current twig-admin-theme (OXID 7.4 Enterprise) uses an accordion sidebar: the
        // top-level category and its single submenu item share the same label — "Orders" (EN)
        // or "Bestellungen" (DE), i.e. "mxdisplayorders", not "mxorders"/"Administer Orders".
        // The first click expands the category (submenu items are hidden, so only the category
        // link is in the accessibility tree beforehand); a second click on the now-visible
        // submenu link (same label) navigates to the order list. Depending on prior sidebar
        // state the category can already be expanded, in which case the first click navigates
        // directly. Verified 2026-09-23 (Sprint 2026-09-23/01, Story 1) against both locales.
        const ordersEntries = menuFrame.getByRole('link', { name: /^(Administer Orders|Orders|Bestellungen)$/i });
        if (await ordersEntries.first().isVisible({ timeout: 10000 }).catch(() => false)) {
            await ordersEntries.first().click();
            await this.page.waitForTimeout(1500);

            if (!this.getListFrame()) {
                const submenuEntry = ordersEntries.last();
                if (await submenuEntry.isVisible({ timeout: 5000 }).catch(() => false)) {
                    await submenuEntry.click();
                    await this.page.waitForTimeout(2000);
                }
            }

            if (this.getListFrame()) {
                return;
            }
        }

        // Classic admin skin fallback: expand "Administer Orders", then click "Orders" in
        // the basefrm content area.
        const adminOrdersLink = menuFrame.locator(this.selectors.administerOrdersLink);
        await adminOrdersLink.click();
        await this.page.waitForTimeout(1000);
        await adminOrdersLink.click().catch(() => {});
        await this.page.waitForTimeout(1000);

        const baseFrame = this.getBaseFrame();
        if (baseFrame) {
            const ordersInContent = baseFrame.locator(this.selectors.ordersLink).first();
            if (await ordersInContent.isVisible({ timeout: 3000 }).catch(() => false)) {
                await ordersInContent.click();
                await this.page.waitForTimeout(3000);
            }
        }

        await this.page.waitForTimeout(2000);
    }

    async selectOrderByCustomerName(customerName: string = 'admin@oxid-esales.com'): Promise<void> {
        const listFrame = this.getListFrame();
        if (!listFrame) {
            throw new Error('List frame not found');
        }

        const orderRow = listFrame.locator(this.selectors.orderRowWithName(customerName)).first();
        if (await orderRow.isVisible({ timeout: 3000 }).catch(() => false)) {
            const orderLink = orderRow.locator('a').first();
            await orderLink.click();
            await this.page.waitForTimeout(2000);
        } else {
            // Fallback: click first order
            const firstOrderLink = listFrame.locator('a:has-text("2025-"), a:has-text("2026-")').first();
            if (await firstOrderLink.isVisible({ timeout: 3000 }).catch(() => false)) {
                await firstOrderLink.click();
                await this.page.waitForTimeout(2000);
            }
        }
    }

    /**
     * Select an order from the list by its exact order number (unique). Falls back to a
     * substring match if no exact-text anchor is found.
     */
    async selectOrderByNumber(orderNumber: string): Promise<boolean> {
        const listFrame = this.getListFrame();
        if (!listFrame) {
            throw new Error('List frame not found');
        }

        const exact = listFrame.locator('a', { hasText: new RegExp(`^\\s*${orderNumber}\\s*$`) }).first();
        if (await exact.isVisible({ timeout: 3000 }).catch(() => false)) {
            await exact.click();
            await this.page.waitForTimeout(2000);
            return true;
        }

        const row = listFrame.locator(`table tr:has-text("${orderNumber}")`).first();
        if (await row.isVisible({ timeout: 3000 }).catch(() => false)) {
            await row.locator('a').first().click();
            await this.page.waitForTimeout(2000);
            return true;
        }

        return false;
    }

    async openPaymentTab(): Promise<void> {
        const listFrame = this.getListFrame();
        if (!listFrame) {
            throw new Error('List frame not found');
        }

        const paymentTab = listFrame
            .locator('table.tabs a, .tabs a, [id^="tbcl"]')
            .filter({ hasText: /^(Payment|Zahlung)$/ })
            .first();

        let clicked = false;
        if (await paymentTab.isVisible({ timeout: 3000 }).catch(() => false)) {
            await paymentTab.click();
            clicked = true;
        } else {
            const fallback = listFrame.locator('a').filter({ hasText: /^(Payment|Zahlung)$/ }).first();
            if (await fallback.isVisible({ timeout: 3000 }).catch(() => false)) {
                await fallback.click();
                clicked = true;
            }
        }

        if (!clicked) {
            throw new Error('Payment tab link not found');
        }

        await this.page.waitForTimeout(3000);
    }
}