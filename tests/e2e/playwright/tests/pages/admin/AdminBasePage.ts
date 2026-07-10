import { Page } from '@playwright/test';

export abstract class AdminBasePage {
    protected readonly page: Page;
    protected readonly baseURL: string;

    constructor(page: Page) {
        this.page = page;
        const shopUrl = process.env.MOLLIE_E2E_SHOP_URL || process.env.SHOP_URL || 'https://localhost.local';
        this.baseURL = `${shopUrl}/admin/index.php`;
    }

    async navigate(path: string = ''): Promise<void> {
        await this.page.goto(`${this.baseURL}${path}`);
    }

    async waitForPageLoad(): Promise<void> {
        await this.page.waitForLoadState('networkidle');
    }

    getMenuFrame() {
        return this.page.frameLocator('frame[name="menu"]').first();
    }
}