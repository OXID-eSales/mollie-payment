import { Page } from '@playwright/test';
import { BasePage } from './BasePage';

export interface UserCredentials {
    email: string;
    password: string;
}

export const TEST_USER: UserCredentials = {
    email: process.env.TEST_USER_EMAIL || 'playwright.user@oxid-esales.dev',
    password: process.env.TEST_USER_PASSWORD || 'useruser',
};

export class LoginPage extends BasePage {
    private readonly selectors = {
        emailInput: '#loginUser',
        passwordInput: '#loginPwd',
        loginButton: '#loginButton',
        loggedInIndicator: 'a:has-text("Logout"), a:has-text("Abmelden")',
    };

    async navigateToLogin(): Promise<void> {
        await this.navigate('/index.php?cl=account');
        await this.waitForPageLoad();
    }

    async login(credentials: UserCredentials = TEST_USER): Promise<void> {
        console.log(`  Attempting login with email: ${credentials.email}`);

        if (await this.isLoggedIn()) {
            console.log('  Already logged in');
            return;
        }

        const emailInput = this.page.locator(this.selectors.emailInput);
        await emailInput.waitFor({ state: 'visible', timeout: 10000 });

        await emailInput.fill(credentials.email);
        await this.page.locator(this.selectors.passwordInput).fill(credentials.password);

        const loginBtn = this.page.locator(this.selectors.loginButton);
        await loginBtn.click();

        await this.page.waitForLoadState('networkidle');
        await this.page.waitForTimeout(1000);
    }

    async isLoggedIn(): Promise<boolean> {
        const loggedInIndicator = this.page.locator(this.selectors.loggedInIndicator).first();
        return loggedInIndicator.isVisible({ timeout: 2000 }).catch(() => false);
    }
}