import { Page } from '@playwright/test';
import { BasePage } from './BasePage';

export class ProductPage extends BasePage {
    private readonly selectors = {
        addToCartButton: '#toBasket, button:has-text("To cart"), button:has-text("In den Warenkorb")',
        variantSelect: 'select',
        productTitle: 'h1',
    };

    private async mustBeVisible(locator: ReturnType<Page['locator']>, message: string, timeout = 10000) {
        try {
            await locator.waitFor({ state: 'visible', timeout });
        } catch {
            throw new Error(message);
        }
    }

    async navigateToProduct(path: string): Promise<void> {
        await this.navigate(path);
        await this.waitForPageLoad();

        const title = this.page.locator(this.selectors.productTitle).first();
        await this.mustBeVisible(title, 'Product page did not load (h1 missing)');
    }

    async selectVariantIfAvailable(): Promise<void> {
        const variantSelect = this.page.locator(this.selectors.variantSelect).first();
        if (await variantSelect.isVisible({ timeout: 1500 }).catch(() => false)) {
            const options = await variantSelect.locator('option').count().catch(() => 0);
            if (options >= 2) {
                await variantSelect.selectOption({ index: 1 });
                await this.page.waitForTimeout(300);
            }
        }
    }

    async addToCart(): Promise<void> {
        const addBtn = this.page.locator(this.selectors.addToCartButton).first();
        await this.mustBeVisible(addBtn, 'Add to cart button not visible');

        await Promise.all([
            this.page.waitForLoadState('networkidle'),
            addBtn.click(),
        ]);

        await this.page.waitForTimeout(500);
    }
}