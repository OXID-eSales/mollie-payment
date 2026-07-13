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
        loggedInIndicator: 'h1:has-text("Hello"), a:has-text("Logout"), a:has-text("Abmelden"), [href*="logout"]',
        cookieAccept: 'button:has-text("Accept"), button:has-text("Akzeptieren"), .cookie-accept',
    };

    private async mustBeVisible(locator: ReturnType<Page['locator']>, message: string, timeout = 10000) {
        try {
            await locator.waitFor({ state: 'visible', timeout });
        } catch {
            throw new Error(message);
        }
    }

    async navigateToLogin(): Promise<void> {
        await this.navigate('/index.php?cl=account');
        await this.waitForPageLoad();

        const emailInput = this.page.locator(this.selectors.emailInput);
        const loggedIn = await this.isLoggedIn();
        const loginVisible = await emailInput.isVisible({ timeout: 2000 }).catch(() => false);

        if (!loginVisible && !loggedIn) {
            throw new Error(`Login page did not show form and user not logged in. URL: ${this.page.url()}`);
        }
    }

    async acceptCookies(): Promise<void> {
        const btn = this.page.locator(this.selectors.cookieAccept).first();
        if (await btn.isVisible({ timeout: 2000 }).catch(() => false)) {
            await btn.click().catch(() => {});
            await this.page.waitForTimeout(300);
        }
    }

    async login(credentials: UserCredentials = TEST_USER): Promise<void> {
        console.log(`  Attempting login with email: ${credentials.email}`);

        if (await this.isLoggedIn()) {
            console.log('  Already logged in');
            return;
        }

        const emailInput = this.page.locator(this.selectors.emailInput);
        await this.mustBeVisible(emailInput, 'Login form not visible');

        await emailInput.fill(credentials.email);
        await this.page.locator(this.selectors.passwordInput).fill(credentials.password);

        const loginBtn = this.page.locator(this.selectors.loginButton);
        await this.mustBeVisible(loginBtn, 'Login button not visible');

        const fromUrl = this.page.url();
        await Promise.all([
            this.page.waitForURL((u) => u.toString() !== fromUrl, { timeout: 10000 }).catch(() => null),
            loginBtn.click(),
        ]);

        await this.waitForPageLoad();
        await this.page.waitForTimeout(500);

        const loggedIn = await this.isLoggedIn();
        const loginStillVisible = await emailInput.isVisible({ timeout: 1500 }).catch(() => false);

        if (!loggedIn && loginStillVisible) {
            throw new Error(`Login failed. URL: ${this.page.url()}`);
        }
    }

    async isLoggedIn(): Promise<boolean> {
        const indicator = this.page.locator(this.selectors.loggedInIndicator).first();
        return indicator.isVisible({ timeout: 2000 }).catch(() => false);
    }
}