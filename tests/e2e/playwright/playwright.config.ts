import { defineConfig, devices } from '@playwright/test';
import dotenv from 'dotenv';
import * as path from 'node:path';

dotenv.config({ path: path.resolve(__dirname, '.env') });

// Shop URL from env
// @ts-ignore
const SHOP_URL = process.env.MOLLIE_E2E_SHOP_URL || process.env.SHOP_URL || 'http://localhost.local';

export default defineConfig({
    testDir: './tests',
    timeout: 180_000,
    expect: { timeout: 10_000 },
    fullyParallel: false,
    workers: 1,
    retries: 0,
    reporter: [
        ['list'],
        ['html', { open: 'never', outputFolder: 'playwright-report/html' }],
    ],
    use: {
        baseURL: SHOP_URL,
        screenshot: 'only-on-failure',
        trace: 'retain-on-failure',
        video: 'retain-on-failure',
        viewport: { width: 1400, height: 900 },
    },
    projects: [
        {
            name: 'mollie-checkout',
            testMatch: 'tests/checkout/mollie-checkout.spec.ts',
            use: { ...devices['Desktop Chrome'] },
        },
        {
            name: 'mollie-admin-refund',
            testMatch: 'tests/admin/mollie-admin-refund.spec.ts',
            use: { ...devices['Desktop Chrome'] },
        },
        {
            name: 'mollie-all',
            testMatch: ['tests/checkout/*.spec.ts', 'tests/admin/*.spec.ts'],
            use: { ...devices['Desktop Chrome'] },
        },
        {
            name: 'mollie-opc',
            testMatch: 'tests/MollieOpc/*.spec.ts',
            use: { ...devices['Desktop Chrome'] },
        },
        {
            name: 'mollie-standard',
            testMatch: 'tests/MollieStandard/*.spec.ts',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
});