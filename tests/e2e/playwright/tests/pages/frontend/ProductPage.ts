import { Page } from '@playwright/test';
import { BasePage } from './BasePage';

export class ProductPage extends BasePage {
    private readonly selectors = {
        addToCartButton: '#toBasket',
        productTitle: 'h1',
    };

    async navigateToSunglasses(langId: 0 | 1 = 1): Promise<void> {
        const lang = langId === 1 ? '/en' : '';
        await this.navigate(`${lang}/Merchandise/Sunglasses/?lang=${langId}`);
        await this.waitForPageLoad();
    }

    async openFirstProduct(): Promise<void> {
        // Find product links - use force click for overlay elements
        const firstLink = this.page.locator(
            'a[href*="Ocean-Eyes.html"], a[href*="/Sunglasses/"][href*=".html"]'
        ).first();

        const href = await firstLink.getAttribute('href').catch(() => null);

        if (href) {
            await this.page.goto(href);
            await this.waitForPageLoad();
        }

        const title = this.page.locator(this.selectors.productTitle).first();
        await title.waitFor({ state: 'visible', timeout: 10000 });
    }

    async addToCart(): Promise<void> {
        const addBtn = this.page.locator(this.selectors.addToCartButton).first();
        await addBtn.waitFor({ state: 'visible', timeout: 10000 });

        await Promise.all([
            this.page.waitForLoadState('networkidle'),
            addBtn.click(),
        ]);

        console.log('  Added product to cart');
    }
}