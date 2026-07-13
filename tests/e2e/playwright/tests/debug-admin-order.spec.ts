/**
 * Debug admin - check what's in the Payment tab
 */
import { test, expect, Page } from '@playwright/test';
import { AdminLoginPage } from './pages/admin/AdminLoginPage';

const SHOP_URL = process.env.MOLLIE_E2E_SHOP_URL || process.env.SHOP_URL || 'https://localhost.local';

test('debug payment tab content', async ({ page }) => {
    console.log('=== Login to admin ===');
    const adminLogin = new AdminLoginPage(page);
    await adminLogin.login();
    console.log('Logged in!');
    
    // Wait for frames to load
    await page.waitForTimeout(3000);
    
    // Navigate to orders
    const menuFrame = page.frame('adminnav') || page.frame('navigation');
    if (menuFrame) {
        console.log('Found menu frame');
        
        // Click Administer Orders
        const adminOrders = menuFrame.locator('a:has-text("Administer Orders")');
        if (await adminOrders.isVisible({ timeout: 2000 }).catch(() => false)) {
            await adminOrders.click();
            await page.waitForTimeout(1000);
        }
    }
    
    // Go to orders in base frame
    const baseFrame = page.frame('basefrm');
    if (baseFrame) {
        console.log('Found base frame');
        const ordersLink = baseFrame.locator('a:has-text("Orders")').first();
        if (await ordersLink.isVisible({ timeout: 2000 }).catch(() => false)) {
            await ordersLink.click();
            await page.waitForTimeout(2000);
        }
    }
    
    // Now we should be in list frame
    const listFrame = page.frame('list');
    if (listFrame) {
        console.log('Found list frame');
        
        // Click on first order
        const firstOrder = listFrame.locator('a:has-text("215")').first();
        if (await firstOrder.isVisible({ timeout: 2000 }).catch(() => false)) {
            await firstOrder.click();
            await page.waitForTimeout(2000);
            console.log('Clicked order 215');
        }
        
        // Now look for Payment tab in the new list frame
        const newListFrame = page.frame('list');
        if (newListFrame) {
            // Get all links in the list frame
            const allLinks = await newListFrame.locator('a').allTextContents();
            console.log('All links in list frame:', allLinks.slice(0, 30));
            
            // Look for Payment tab
            const paymentTab = newListFrame.locator('a').filter({ hasText: /Payment|Zahlung/ });
            const paymentTabs = await paymentTab.all();
            console.log(`Found ${paymentTabs.length} Payment-related links`);
            
            for (let i = 0; i < paymentTabs.length; i++) {
                const txt = await paymentTabs[i].textContent();
                const href = await paymentTabs[i].getAttribute('href');
                console.log(`  ${i}: "${txt}" -> ${href}`);
            }
            
            // Click Payment tab
            if (paymentTabs.length > 0) {
                await paymentTabs[0].click();
                await page.waitForTimeout(3000);
                console.log('Clicked Payment tab');
                
                // Get content after clicking
                const afterClickFrame = page.frame('list');
                if (afterClickFrame) {
                    const content = await afterClickFrame.content();
                    console.log('Payment tab content (first 3000):');
                    console.log(content.substring(0, 3000));
                    
                    // Look for Mollie content
                    if (content.toLowerCase().includes('mollie')) {
                        console.log('✓ Mollie content found!');
                    } else {
                        console.log('✗ No Mollie content in Payment tab');
                    }
                }
            }
        }
    }
    
    await page.screenshot({ path: 'admin-payment-tab-debug.png', fullPage: true });
});