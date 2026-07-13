import { Page, Frame } from '@playwright/test';

export abstract class AdminBasePage {
    readonly page: Page;
    readonly adminUrl: string;

    constructor(page: Page) {
        this.page = page;
        // Derive admin URL from shop URL
        const shopUrl = process.env.MOLLIE_E2E_SHOP_URL || process.env.SHOP_URL || 'https://daniil.oxiddev.de';
        this.adminUrl = `${shopUrl}/admin/`;
    }

    async navigate(): Promise<void> {
        await this.page.goto(this.adminUrl);
        await this.page.waitForLoadState('networkidle');
    }

    async waitForFrames(): Promise<void> {
        await this.page.waitForTimeout(3000);
    }

    getMenuFrame(): Frame | null {
        return this.page.frame('adminnav') || this.page.frame('navigation');
    }

    getBaseFrame(): Frame | null {
        return this.page.frame('basefrm');
    }

    getListFrame(): Frame | null {
        return this.page.frame('list');
    }

    getEditFrame(): Frame | null {
        return this.page.frame('edit');
    }

    getAllFrameNames(): string[] {
        return this.page.frames().map(f => f.name() || 'main');
    }
}