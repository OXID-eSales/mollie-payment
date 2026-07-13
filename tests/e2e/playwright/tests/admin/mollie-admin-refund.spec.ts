import { test, expect } from '@playwright/test';
import { AdminLoginPage } from '../pages/admin/AdminLoginPage';
import { AdminOrdersPage } from '../pages/admin/AdminOrdersPage';
import { AdminMollieOrderPage } from '../pages/admin/AdminMollieOrderPage';

const DEFAULT_CUSTOMER_NAME = 'Збигнев';

/**
 * Mollie Admin Refund Flow - Complete E2E
 *
 * 1. Log into admin
 * 2. Find an order with Mollie payment
 * 3. Open Mollie payment tab
 * 4. Verify panel is visible
 * 5. Execute partial refund
 * 6. Verify refund worked
 */
test('Admin: Mollie Refund Operations - Complete Flow', async ({ page }) => {
    const adminLogin = new AdminLoginPage(page);
    const ordersPage = new AdminOrdersPage(page);
    const molliePage = new AdminMollieOrderPage(page);

    console.log('\n========================================');
    console.log('ADMIN: Mollie Refund E2E Flow');
    console.log('========================================\n');

    // STEP 1: Login
    console.log('STEP 1: Login...');
    await adminLogin.navigate();
    await page.waitForTimeout(1000);

    if (!await adminLogin.isLoggedIn()) {
        await adminLogin.login();
    }
    console.log('✓ Logged into admin');

    // STEP 2: Navigate to orders
    console.log('STEP 2: Navigate to orders...');
    await ordersPage.navigateToOrders();
    console.log('✓ Navigated to Orders');

    // STEP 3: Select order
    console.log('STEP 3: Select Mollie order...');
    await ordersPage.selectOrderByCustomerName(DEFAULT_CUSTOMER_NAME);
    console.log('✓ Selected order');

    // STEP 4: Open Payment tab
    console.log('STEP 4: Open Payment tab...');
    await ordersPage.openPaymentTab();
    console.log('✓ Opened Payment tab');
    await page.waitForTimeout(2000);
    await page.screenshot({ path: 'reports/mollie-refund-01-payment-tab.png' });

    // STEP 5: Check if Mollie panel is visible
    console.log('STEP 5: Verify Mollie panel...');
    const panelVisible = await molliePage.isMolliePanelVisible();
    console.log(`  Panel visible: ${panelVisible}`);

    if (!panelVisible) {
        // Take screenshot for debugging
        await page.screenshot({ path: 'reports/mollie-refund-no-panel.png' });

        // Check what's in the edit frame
        const editFrame = molliePage.getEditFrame();
        if (editFrame) {
            const frameContent = await editFrame.locator('body').innerText();
            console.log('  Edit frame content (first 500):', frameContent.substring(0, 500));
        }

        throw new Error('Mollie panel not visible - no Mollie payment on this order');
    }
    console.log('✓ Mollie panel is visible');

    // STEP 6: Get payment details
    console.log('STEP 6: Get payment details...');
    const details = await molliePage.getMolliePaymentDetails();
    if (details) {
        console.log(`  Contract ID: ${details.contractId || 'N/A'}`);
        console.log(`  Transaction ID: ${details.transactionId || 'N/A'}`);
        console.log(`  Dashboard Link: ${details.dashboardLink ? '✓ Present' : '✗ Missing'}`);
    }
    await page.screenshot({ path: 'reports/mollie-refund-02-payment-details.png' });

    // STEP 7: Check if refund button is visible
    console.log('STEP 7: Check refund availability...');
    let refundVisible = await molliePage.isRefundButtonVisible();
    console.log(`  Refund button visible: ${refundVisible}`);

    // If not refundable, check if capture is needed first
    if (!refundVisible) {
        const captureVisible = await molliePage.isCaptureButtonVisible();
        console.log(`  Capture button visible: ${captureVisible}`);

        if (captureVisible) {
            console.log('  Executing capture...');
            const captured = await molliePage.executeCapture();
            if (captured) {
                console.log('✓ Capture executed');
                await page.waitForTimeout(3000);

                // Re-check refund visibility
                refundVisible = await molliePage.isRefundButtonVisible();
                console.log(`  Refund button visible after capture: ${refundVisible}`);
            }
        }

        if (!refundVisible) {
            await page.screenshot({ path: 'reports/mollie-refund-no-refund.png' });
            throw new Error('Refund button not visible - order may not be in refundable state');
        }
    }

    // STEP 8: Get refundable amount
    console.log('STEP 8: Get refundable amount...');
    const refundableAmount = await molliePage.getRefundableAmount();
    console.log(`  Refundable amount: ${refundableAmount}`);

    if (refundableAmount <= 0) {
        await page.screenshot({ path: 'reports/mollie-refund-no-amount.png' });
        throw new Error('No refundable amount available');
    }

    // STEP 9: Execute partial refund
    console.log('STEP 9: Execute partial refund...');
    const partialAmount = parseFloat((refundableAmount * 0.5).toFixed(2));
    console.log(`  Refunding: ${partialAmount}`);

    // Handle the confirm dialog
    page.once('dialog', async dialog => {
        console.log(`  Dialog: ${dialog.type()} - ${dialog.message()}`);
        await dialog.accept();
    });

    const refunded = await molliePage.executeRefund(
        'requested_by_customer',
        partialAmount,
        'E2E test: 50% partial refund'
    );

    console.log(`  Refunded returned: ${refunded}`);

    if (!refunded) {
        await page.screenshot({ path: 'reports/mollie-refund-failed.png' });
        throw new Error('Refund execution failed');
    }
    console.log('✓ Refund executed');

    // Wait for potential error messages
    await page.waitForTimeout(2000);
    const editFrame = molliePage.getEditFrame();
    if (editFrame) {
        const errorEl = editFrame.locator('.pc-alert-danger, .alert-danger, [data-testid="mollie-validation-errors"]');
        if (await errorEl.isVisible({ timeout: 2000 }).catch(() => false)) {
            const errorText = await errorEl.textContent();
            console.log(`  Error message: ${errorText}`);
        }
    }

    
    // Refresh the page to see updated amount
    await page.waitForTimeout(2000);
    await ordersPage.openPaymentTab();
    await page.waitForTimeout(2000);
    await page.screenshot({ path: 'reports/mollie-refund-03-after-refund.png' });

    // STEP 10: Verify refund worked
    console.log('STEP 10: Verify refund worked...');
    const remainingAmount = await molliePage.getRefundableAmount();
    console.log(`  Remaining refundable: ${remainingAmount}`);

    if (remainingAmount >= refundableAmount - 0.01) {
        throw new Error('Refund did not reduce the refundable amount');
    }
    console.log('✓ Refund verified');

    // STEP 11: Check transaction history
    console.log('STEP 11: Check transaction history...');
    const txHistory = await molliePage.getTransactionHistory();
    console.log(`  Transaction rows: ${txHistory.length}`);

    // Final summary
    console.log('\n========================================');
    console.log('REFUND TEST COMPLETE - ALL STEPS PASSED');
    console.log('========================================');
    console.log(`  Order: ${DEFAULT_CUSTOMER_NAME}`);
    console.log(`  Contract: ${details?.contractId || 'N/A'}`);
    console.log(`  Transaction: ${details?.transactionId || 'N/A'}`);
    console.log(`  Original refundable: ${refundableAmount}`);
    console.log(`  Refunded amount: ${partialAmount}`);
    console.log(`  Remaining: ${remainingAmount}`);
    console.log(`  Transaction history: ${txHistory.length} rows`);
    console.log('========================================\n');

    await page.screenshot({ path: 'reports/mollie-refund-04-complete.png' });
});