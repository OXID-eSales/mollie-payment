import { AdminBasePage } from './AdminBasePage';

export interface AdminCredentials {
    email: string;
    password: string;
}

export const DEFAULT_ADMIN_CREDENTIALS: AdminCredentials = {
    email: 'noreply@oxid-esales.com',
    password: 'admin',
};

export class AdminLoginPage extends AdminBasePage {
    private readonly selectors = {
        userInput: 'input[name="user"]',
        passwordInput: 'input[name="pwd"]',
        submitButton: 'input[type="submit"]',
    };

    async login(credentials: AdminCredentials = DEFAULT_ADMIN_CREDENTIALS): Promise<void> {
        await this.page.locator(this.selectors.userInput).fill(credentials.email);
        await this.page.locator(this.selectors.passwordInput).fill(credentials.password);
        await this.page.locator(this.selectors.submitButton).click();
        await this.page.waitForLoadState('networkidle');

        // Staging mode shows "Start OXID eShop Admin" button - click it to enter admin
        const startAdminBtn = this.page.locator('button:has-text("Start OXID eShop Admin")');
        if (await startAdminBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
            await startAdminBtn.click();
            await this.page.waitForLoadState('networkidle');
            await this.waitForFrames();
        } else {
            await this.waitForFrames();
        }
    }

    async isLoggedIn(): Promise<boolean> {
        const menuFrame = this.getMenuFrame();
        return menuFrame !== null;
    }
}