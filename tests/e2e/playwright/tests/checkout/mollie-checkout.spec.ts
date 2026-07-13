import { test, expect, Page } from '@playwright/test';
import { LoginPage, TEST_USER } from '../pages/frontend/LoginPage';
import { ProductPage } from '../pages/frontend/ProductPage';
import { MollieCheckoutPage } from '../pages/frontend/MollieCheckoutPage';

interface LanguageCase {
    langName: string;
    langId: 0 | 1;
    productPath: string;
}

const LANGUAGE_CASES: LanguageCase[] = [
    {
        langName: 'English (lang=1)',
        langId: 1,
        productPath: '/en/Spare-parts/Axle-parts/Wishbone-aluminum.html',
    },
    {
        langName: 'German (lang=0)',
        langId: 0,
        productPath: '/Ersatzteile/Achsteile/Querlenker-Aluminium.html',
    },
];

/**
 * Mollie Checkout Flow
 *
 * Mirrors Stripe's checkout.spec.ts but uses Mollie's hosted checkout:
 * 1. Login to shop
 * 2. Navigate to product and add to cart
 * 3. Go through checkout steps, select Mollie payment
 * 4. Click "Mollie bezahlen" → redirect to Mollie hosted checkout
 * 5. Select PayPal (test method) → test-mode screen → select "Paid"
 * 6. Return to shop thankyou page
 */
async function runMollieCheckout(page: Page, langCase: LanguageCase): Promise<{ orderNumber: string }> {
    const shopUrl = (process.env.MOLLIE_E2E_SHOP_URL || process.env.SHOP_URL || 'http://localhost.local').replace(/\/$/, '');
    const { langId, productPath } = langCase;

    console.log(`STEP 1: Loading shop (${langCase.langName})...`);
    await page.goto(`${shopUrl}/index.php?lang=${langId}`);
    await page.waitForLoadState('networkidle');

    // Accept cookies if shown
    const cookieBtn = page.locator(
        'button:has-text("Accept"), button:has-text("Akzeptieren"), .cookie-accept'
    );
    if (await cookieBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
        await cookieBtn.click();
        await page.waitForTimeout(500);
    }
    console.log('✓ Shop loaded');

    console.log('STEP 2: Logging in...');
    const loginPage = new LoginPage(page);
    await loginPage.navigateToLogin();
    await loginPage.login(TEST_USER);
    console.log('✓ Logged in');

    console.log(`STEP 3: Adding product to cart via ${productPath}...`);
    await page.goto(`${shopUrl}${productPath}`);
    await page.waitForLoadState('networkidle');

    // Click "To cart" / "In den Warenkorb"
    const toCartBtn = page.locator('#toBasket').first();
    await toCartBtn.waitFor({ state: 'visible', timeout: 10000 });
    await Promise.all([
        page.waitForLoadState('networkidle'),
        toCartBtn.click(),
    ]);
    console.log('✓ Added to cart');

    console.log('STEP 4: Entering checkout...');
    await page.goto(`${shopUrl}/index.php?cl=user&lang=${langId}`);
    await page.waitForLoadState('networkidle');

    // Walk checkout steps - Mollie is selected by radio on payment step
    // The "Order now" button on the order review page triggers Mollie redirect
    const orderNowBtn = page.locator(
        'button:has-text("Order now"), button:has-text("Bestellung pflichtig bestellen"), button:has-text("zahlungspflichtig bestellen"), input[type="submit"]:has-text("Order now")'
    );
    const MAX_STEPS = 8;
    for (let step = 0; step < MAX_STEPS; step++) {
        await page.waitForTimeout(1000);

        // Check if we're on the order review page with "Order now" button
        if (await orderNowBtn.count() > 0) break;

        // Select Mollie payment method on the payment step
        const mollieRadio = page.locator(
            'input[type="radio"][value="oe_payments_mollie"]'
        ).first();
        if (await mollieRadio.isVisible({ timeout: 1500 }).catch(() => false)) {
            await mollieRadio.check({ force: true });
            await page.waitForTimeout(400);
        }

        // Click continue/next (English: "Next", German: "Weiter")
        const continueBtn = page.locator(
            'button:has-text("Next"), button:has-text("Weiter"), button:has-text("Continue")'
        ).first();
        if (await continueBtn.isVisible({ timeout: 2000 }).catch(() => false)) {
            await continueBtn.click();
            await page.waitForLoadState('networkidle');
        }
    }

    await expect(
        orderNowBtn,
        'Expected to reach order review page with "Order now" button'
    ).toHaveCount(1, { timeout: 5000 });
    console.log(`✓ Reached order review step (lang=${langId})`);

    console.log('STEP 5: Clicking "Order now" (triggers Mollie redirect)...');
    // Accept AGB/T&C if checkbox exists
    const agbCheckbox = page.locator('#checkAgbTop, #checkAllAgbTop, input[name="checkAgbTop"]');
    if (await agbCheckbox.count() > 0) {
        await agbCheckbox.first().evaluate((el: HTMLInputElement) => {
            if (!el.checked) {
                el.checked = true;
                el.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
        await page.waitForTimeout(300);
    }

    await orderNowBtn.first().waitFor({ state: 'visible', timeout: 15000 });
    await orderNowBtn.first().click({ force: true });
    console.log('✓ Clicked "Order now"');

    console.log('STEP 6: Completing Mollie payment...');
    const molliePage = new MollieCheckoutPage(page);
    await molliePage.completePayment('paypal', 'Paid');
    console.log('✓ Mollie payment completed');

    // Wait for redirect back to shop
    await molliePage.waitForRedirectBack(shopUrl);
    console.log('✓ Redirected back to shop');

    // STEP 7: Verify order confirmation
    console.log('STEP 7: Verifying order confirmation...');
    await page.waitForLoadState('networkidle');
    const finalUrl = page.url();
    console.log(`Final URL: ${finalUrl}`);

    expect(finalUrl, 'Expected thankyou page').toContain('thankyou');
    expect(finalUrl, `Thankyou page must be in lang=${langId}`).toContain(`lang=${langId}`);

    // Extract order number (OXID thankyou page: "We registered your order with number 217")
    const bodyText = await page.locator('body').innerText();
    const orderMatch = bodyText.match(/We registered your order with number\s*(\d+)/i) ||
                       bodyText.match(/Nummer\s*:?\s*(\d+)/) ||
                       bodyText.match(/Bestellnummer\s*:?\s*(\d+)/) ||
                       bodyText.match(/order number\s*:?\s*(\d+)/i);
    const orderNumber = orderMatch?.[1] || orderMatch?.[2] || '';

    console.log(`✓ Order confirmed: #${orderNumber}`);
    return { orderNumber };
}

test.describe('Mollie Checkout — happy path', () => {
    for (const langCase of LANGUAGE_CASES) {
        test(`customer pays with Mollie (${langCase.langName})`, async ({ page }) => {
            const { orderNumber } = await runMollieCheckout(page, langCase);
            expect(orderNumber).not.toBe('');
        });
    }
});