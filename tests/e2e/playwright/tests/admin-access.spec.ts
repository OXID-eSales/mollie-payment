/**
 * Simple admin access test
 */
import { test, expect } from '@playwright/test';

const SHOP_URL = process.env.MOLLIE_E2E_SHOP_URL || process.env.SHOP_URL || 'https://localhost.local';

test('admin access and login', async ({ page }) => {
    console.log('Testing admin access...');
    
    // Access admin login page
    await page.goto(`${SHOP_URL}/admin/index.php`);
    await page.waitForLoadState('networkidle');
    
    console.log('Login form visible:', await page.locator('input[name="user"]').isVisible());
    
    // Login
    await page.locator('input[name="user"]').fill('noreply@oxid-esales.com');
    await page.locator('input[name="pwd"]').fill('admin');
    await page.locator('input[type="submit"]').click();
    
    // Wait for redirect to admin_start
    await page.waitForURL(/admin_start/i, { timeout: 30000 });
    console.log('Logged in! URL:', page.url());
    
    await page.screenshot({ path: 'admin-logged-in.png' });
    
    // Verify we're in admin
    expect(page.url()).toContain('admin_start');
    console.log('✓ Admin login successful');
});