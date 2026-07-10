import { AdminBasePage } from './AdminBasePage';

export interface AdminCredentials {
    email: string;
    password: string;
}

export const DEFAULT_ADMIN_CREDENTIALS: AdminCredentials = {
    email: process.env.TEST_ADMIN_USER || 'noreply@oxid-esales.com',
    password: process.env.TEST_ADMIN_PASSWORD || 'admin',
};

export class AdminLoginPage extends AdminBasePage {
    private readonly selectors = {
        userInput: 'input[name="user"]',
        passwordInput: 'input[name="pwd"]',
        submitButton: 'input[type="submit"]',
    };

    async login(credentials: AdminCredentials = DEFAULT_ADMIN_CREDENTIALS): Promise<void> {
        // Navigate directly to admin login with a fresh page
        await this.page.goto(this.baseURL);
        await this.page.waitForLoadState('networkidle');
        
        // Check if we're already logged in
        const menuFrame = this.getMenuFrame();
        if (menuFrame) {
            const isVisible = await menuFrame.locator('body').isVisible().catch(() => false);
            if (isVisible) {
                console.log('  Already logged in to admin');
                return;
            }
        }
        
        // If login form is visible, fill it
        const userInput = this.page.locator(this.selectors.userInput);
        const loginVisible = await userInput.isVisible({ timeout: 5000 }).catch(() => false);
        
        if (loginVisible) {
            await userInput.fill(credentials.email);
            await this.page.locator(this.selectors.passwordInput).fill(credentials.password);
            await this.page.locator(this.selectors.submitButton).click();
            await this.page.waitForLoadState('networkidle');
            await this.page.waitForTimeout(2000);
            console.log('  Logged in to admin');
        } else {
            console.log('  Admin login form not visible, might be staging blocked');
            throw new Error('Cannot access admin panel - staging mode may be blocking access');
        }
    }

    async isLoggedIn(): Promise<boolean> {
        const menuFrame = this.getMenuFrame();
        if (!menuFrame) return false;
        return menuFrame.locator('body').isVisible().catch(() => false);
    }
}