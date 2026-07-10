import { Page } from '@playwright/test';

export abstract class BasePage {
    readonly page: Page;
    readonly baseURL: string;

    constructor(page: Page) {
        this.page = page;
        this.baseURL = process.env.MOLLIE_E2E_SHOP_URL || process.env.SHOP_URL || 'https://localhost.local';
    }

    async navigate(path: string = ''): Promise<void> {
        await this.page.goto(`${this.baseURL}${path}`);
    }

    async waitForPageLoad(): Promise<void> {
        await this.page.waitForLoadState('networkidle');
    }

    async acceptCookies(): Promise<void> {
        const cookieButton = this.page.locator(
            'button:has-text("Accept"), button:has-text("Akzeptieren"), .cookie-accept'
        );
        if (await cookieButton.isVisible({ timeout: 3000 }).catch(() => false)) {
            await cookieButton.click();
            await this.page.waitForTimeout(1000);
        }
    }
}