import { test, expect, Page } from '@playwright/test';
import { LoginPage, TEST_USER } from './pages/frontend/LoginPage';
import { ProductPage } from './pages/frontend/ProductPage';
import { CheckoutPage } from './pages/frontend/CheckoutPage';
import { AdminLoginPage } from './pages/admin/AdminLoginPage';
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

async function runMollieCheckout(page: Page, langId: 0 | 1 = 1): Promise<{ orderNumber: string; orderId: string }> {
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
    
    // Look for order number in various formats
    const orderPatterns = [
        /We registered your order with number\s*(\d+)/i,  // "We registered your order with number 209"
        /Bestellnummer\s*:?\s*(\d+)/i,  // German: "Bestellnummer: 123"
        /order number\s*:?\s*(\d+)/i,      // English: "Order number: 123"
        /Nummer\s*:?\s*(\d+)/i,             // "Nummer: 123"
        /number\s+(\d{4,})/i,              // "number 1234"
        /(\d{4,})/,                           // Any 4+ digit number (fallback)
    ];
    
    let orderNumber = '';
    for (const pattern of orderPatterns) {
        const match = body.match(pattern);
        if (match) {
            orderNumber = match[1];
            break;
        }
    }

    // Get order ID from URL or page
    let orderId = '';
    const urlMatch = page.url().match(/oxid=([a-f0-9]+)/i);
    if (urlMatch) {
        orderId = urlMatch[1];
    } else {
        // Try to find oxid in a link
        const oxidLink = page.locator('a[href*="oxid="]').first();
        if (await oxidLink.isVisible({ timeout: 1000 }).catch(() => false)) {
            const href = await oxidLink.getAttribute('href');
            const hrefMatch = href?.match(/oxid=([a-f0-9]+)/i);
            orderId = hrefMatch?.[1] || '';
        }
    }

    console.log(`  Order Number: ${orderNumber}`);
    console.log(`  Order ID: ${orderId}`);

    return { orderNumber, orderId };
}

test.describe('Mollie Checkout + Admin Refund Flow', () => {
    // Store created order info for sharing between tests
    let createdOrder: { orderNumber: string; orderId: string } | null = null;

    test('Checkout: Create Mollie order', async ({ page }) => {
        const result = await runMollieCheckout(page);
        createdOrder = result;

        expect(result.orderNumber).not.toBe('');

        console.log(`\n✓ Created order: ${result.orderNumber}`);
        
        // For now, skip orderId - OXID doesn't expose it on thankyou page
        // We'll search for the order by number in admin
    });

    test('Admin: View order and verify Mollie panel', async ({ page }) => {
        if (!createdOrder) {
            test.skip();
        }

        const adminLogin = new AdminLoginPage(page);
        await adminLogin.login();
        console.log('  ✓ Logged into admin');

        const orderPage = new AdminMollieOrderPage(page);

        // Navigate to orders and search by order number
        await page.goto(`${SHOP_URL}/admin/index.php?cl=order_list`);
        await page.waitForLoadState('networkidle');
        
        // Search for the order
        const searchInput = page.locator('input[name="sor异议rdnr"]').first();
        const orderNr = createdOrder?.orderNumber || '210';
        
        // Fill in order search if input exists
        const searchExists = await searchInput.isVisible({ timeout: 2000 }).catch(() => false);
        if (searchExists) {
            await searchInput.fill(orderNr);
            await page.keyboard.press('Enter');
            await page.waitForLoadState('networkidle');
        }
        
        // Click on the order
        const orderLink = page.locator(`text=/${orderNr}/`).first();
        if (await orderLink.isVisible({ timeout: 5000 }).catch(() => false)) {
            await orderLink.click();
            await page.waitForLoadState('networkidle');
            console.log('  ✓ Opened order');
        }

        const panelVisible = await orderPage.isMolliePanelVisible();
        expect(panelVisible).toBeTruthy();
        console.log('  ✓ Mollie panel visible');

        const paymentId = await orderPage.getMolliePaymentId();
        if (paymentId) {
            console.log(`  Payment ID: ${paymentId}`);
        }
    });

    test('Admin: Execute partial refund', async ({ page }) => {
        if (!createdOrder) {
            test.skip();
        }

        const adminLogin = new AdminLoginPage(page);
        await adminLogin.login();

        const orderPage = new AdminMollieOrderPage(page);
        const orderNr = createdOrder?.orderNumber || '210';

        // Navigate to orders
        await page.goto(`${SHOP_URL}/admin/index.php?cl=order_list`);
        await page.waitForLoadState('networkidle');
        
        // Search for the order
        const orderLink = page.locator(`text=/${orderNr}/`).first();
        if (await orderLink.isVisible({ timeout: 5000 }).catch(() => false)) {
            await orderLink.click();
            await page.waitForLoadState('networkidle');
        }
        
        await orderPage.openMollieTab();

        const capturedAmount = await orderPage.getCapturedAmount();
        console.log(`  Captured amount: ${capturedAmount}`);

        // Execute a partial refund (10% of captured)
        const refundSuccess = await orderPage.executePartialRefund('10.00', 'E2E test: 10% partial refund');
        expect(refundSuccess).toBeTruthy();
        console.log('  ✓ Partial refund executed');
    });
});