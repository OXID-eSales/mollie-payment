import { defineConfig, devices } from '@playwright/test';
import dotenv from 'dotenv';
import * as path from 'node:path';

dotenv.config({ path: path.resolve(__dirname, '.env') });

// Sandbox-only E2E config, mirroring the Stripe/PayPal siblings' pattern. All URLs +
// credentials come from env so neither the CI runner nor a local developer leaks live data
// into a shared config file. See README.md — this project is CI/manual-run-only in this
// environment (no Mollie sandbox credentials are provisioned here).
// @ts-ignore
const SHOP_URL = process.env.SHOP_URL || process.env.MOLLIE_E2E_SHOP_URL || 'http://localhost.local';

// @ts-ignore
export default defineConfig({
    testDir: './tests',
    timeout: 60_000,
    expect: { timeout: 10_000 },
    fullyParallel: false, // checkout + admin actions mutate shared order/OXPAID state
    workers: 1,
    retries: process.env.CI ? 1 : 0,
    reporter: [['list'], ['html', { open: 'never' }]],
    use: {
        baseURL: SHOP_URL,
        screenshot: 'only-on-failure',
        trace: 'retain-on-failure',
        video: 'retain-on-failure',
        viewport: { width: 1400, height: 900 },
    },
    projects: [
        {
            name: 'mollie-standard',
            testMatch: 'tests/MollieStandard/**/*.spec.ts',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
});
