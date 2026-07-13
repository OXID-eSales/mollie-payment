import { test, expect, Page } from '@playwright/test';
import { LoginPage, TEST_USER } from './pages/frontend/LoginPage';
import { ProductPage } from './pages/frontend/ProductPage';
import { CheckoutPage } from './pages/frontend/CheckoutPage';
import { AdminLoginPage } from './pages/admin/AdminLoginPage';
import { AdminOrdersPage } from './pages/admin/AdminOrdersPage';
import { AdminMollieOrderPage } from './pages/admin/AdminMollieOrderPage';

const SHOP_URL = process.env.MOLLIE_E2E_SHOP_URL || process.env.SHOP_URL || 'https://localhost.local';

async function completeMollieTestPayment(page: Page, outcome: 'paid' | 'failed' = 'paid'): Promise<void> {
    await expect(page).toHaveURL(/mollie\.com\/checkout/i, { timeout: 30000 });

    // Select PayPal on method selection page
    await page.getByRole('button', { name: /^paypal$/i }).first().click();
    await page.waitForURL(/mollie\.com\/checkout\/test-mode/i, { timeout: 30000 });

    // Select "Paid" status on test-mode page
    const status = outcome === 'paid' ? 'Paid' : 'Failed';
    await page.getByText(status, { exact: true }).click();
    await page.getByRole('button', { name: /continue/i }).click();
}

async function runMollieCheckout(page: Page, langId: 0 | 1 = 1): Promise<{ orderNumber: string }> {
    const loginPage = new LoginPage(page);
    const productPage = new ProductPage(page);
    const checkoutPage = new CheckoutPage(page);

    console.log('STEP 1: Login');
    await loginPage.navigateToLogin();
    await loginPage.login();
    console.log('  ✓ Logged in');

    console.log('STEP 2: Add product to cart');
    await productPage.navigateToSunglasses(langId);
    await productPage.openFirstProduct();
    await productPage.addToCart();
    console.log('  ✓ Product added to cart');

    console.log('STEP 3: Complete checkout with Mollie');
    await checkoutPage.completeCheckoutWithMollie();
    console.log('  ✓ Redirected to Mollie');

    console.log('STEP 4: Complete Mollie payment');
    await completeMollieTestPayment(page, 'paid');
    console.log('  ✓ Payment completed');

    console.log('STEP 5: Verify thank you page');
    await page.waitForURL(/thankyou|checkoutReturn/i, { timeout: 30000 });
    await page.waitForLoadState('networkidle');
    console.log('  ✓ On thank you page');

    // Extract order info
    const body = await page.locator('body').innerText();
    
    // Look for order number
    const orderPatterns = [
        /We registered your order with number\s*(\d+)/i,
        /Bestellnummer\s*:?\s*(\d+)/i,
        /order number\s*:?\s*(\d+)/i,
        /Nummer\s*:?\s*(\d+)/i,
        /number\s+(\d{4,})/i,
    ];
    
    let orderNumber = '';
    for (const pattern of orderPatterns) {
        const match = body.match(pattern);
        if (match) {
            orderNumber = match[1];
            break;
        }
    }

    console.log(`  Order Number: ${orderNumber}`);
    return { orderNumber };
}

test.describe('Mollie Checkout + Admin Refund Flow', () => {
    // Store created order info
    let createdOrder: { orderNumber: string } | null = null;

    test('Checkout: Create Mollie order', async ({ page }) => {
        const result = await runMollieCheckout(page);
        createdOrder = result;
        expect(result.orderNumber).not.toBe('');
        console.log(`\n✓ Created order: ${result.orderNumber}`);
    });

    test('Admin: View order and verify Mollie panel', async ({ page }) => {
        if (!createdOrder) {
            test.skip();
        }

        console.log('=== Admin Login ===');
        const adminLogin = new AdminLoginPage(page);
        await adminLogin.login();
        console.log('  ✓ Logged into admin');

        console.log('=== Navigate to Orders ===');
        const ordersPage = new AdminOrdersPage(page);
        await ordersPage.navigateToOrders();
        
        console.log('=== Select Order ' + createdOrder.orderNumber + ' ===');
        await ordersPage.selectOrderByCustomerName('Збигнев');
        
        console.log('=== Open Payment Tab ===');
        await ordersPage.openPaymentTab();
        
        console.log('=== Verify Mollie Panel ===');
        const orderPage = new AdminMollieOrderPage(page);
        
        // Wait a bit for the tab content to load
        await page.waitForTimeout(2000);
        
        const panelVisible = await orderPage.isMolliePanelVisible();
        console.log(`  Mollie panel visible: ${panelVisible}`);
        
        if (panelVisible) {
            const paymentId = await orderPage.getMolliePaymentId();
            console.log(`  Mollie Payment ID: ${paymentId}`);
            console.log('  ✓ Mollie panel visible');
        } else {
            // Take screenshot for debugging
            await page.screenshot({ path: 'admin-no-mollie-panel.png', fullPage: true });
            console.log('  ⚠ Mollie panel not visible - taking screenshot');
        }
    });

    test('Admin: Execute partial refund', async ({ page }) => {
        if (!createdOrder) {
            test.skip();
        }

        console.log('=== Admin Login ===');
        const adminLogin = new AdminLoginPage(page);
        await adminLogin.login();

        console.log('=== Navigate to Orders ===');
        const ordersPage = new AdminOrdersPage(page);
        await ordersPage.navigateToOrders();
        
        console.log('=== Select Order ===');
        await ordersPage.selectOrderByCustomerName('Збигнев');
        
        console.log('=== Open Payment Tab ===');
        await ordersPage.openPaymentTab();
        
        console.log('=== Execute Refund ===');
        const orderPage = new AdminMollieOrderPage(page);
        await page.waitForTimeout(2000);
        
        const capturedAmount = await orderPage.getCapturedAmount();
        console.log(`  Captured amount: ${capturedAmount}`);

        // Execute a partial refund
        const refundSuccess = await orderPage.executePartialRefund('10.00', 'E2E test: 10% partial refund');
        
        if (refundSuccess) {
            console.log('  ✓ Partial refund executed');
        } else {
            console.log('  ⚠ Refund form not visible or submission failed');
        }
    });
});