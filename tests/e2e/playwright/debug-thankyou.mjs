import { chromium, expect } from '@playwright/test';

const browser = await chromium.launch({ headless: false });
const page = await browser.newPage();

const shopUrl = 'https://daniil.oxiddev.de';

// Login
await page.goto(`${shopUrl}/index.php?cl=account`);
await page.waitForLoadState('networkidle');
await page.locator('#loginUser').fill('playwright.user@oxid-esales.dev');
await page.locator('#loginPwd').fill('useruser');
await page.locator('#loginButton').click();
await page.waitForLoadState('networkidle');
await page.waitForTimeout(1000);

// Add product
await page.goto(`${shopUrl}/en/Spare-parts/Axle-parts/Wishbone-aluminum.html`);
await page.waitForLoadState('networkidle');
const toBasket = page.locator('#toBasket').first();
if (await toBasket.isVisible({ timeout: 3000 }).catch(() => false)) {
  await toBasket.click();
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(1000);
}

// Checkout - select Mollie
await page.goto(`${shopUrl}/index.php?cl=payment&lang=1`);
await page.waitForLoadState('networkidle');
const mollieRadio = page.locator('input[type="radio"][value="oe_payments_mollie"]');
if (await mollieRadio.isVisible({ timeout: 3000 }).catch(() => false)) {
  await mollieRadio.check({ force: true });
}
const continueBtn = page.locator('button:has-text("Weiter"), button:has-text("Continue")').first();
if (await continueBtn.isVisible({ timeout: 2000 }).catch(() => false)) {
  await continueBtn.click();
  await page.waitForLoadState('networkidle');
}

// Order now
await page.waitForTimeout(2000);
const orderNowBtn = page.locator('button:has-text("Order now")');
if (await orderNowBtn.isVisible({ timeout: 5000 }).catch(() => false)) {
  await orderNowBtn.click();
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(1000);
}

// Complete Mollie payment
await expect(page).toHaveURL(/mollie\.com\/checkout/i, { timeout: 30000 });
const methodBtn = page.getByRole('button', { name: /^paypal$/i });
if (await methodBtn.isVisible({ timeout: 10000 }).catch(() => false)) {
  await methodBtn.click();
}
await expect(page).toHaveURL(/mollie\.com\/checkout\/test-mode/i, { timeout: 15000 });
const paidOption = page.getByText('Paid', { exact: true });
await paidOption.click();
const continueBtn2 = page.getByRole('button', { name: /continue/i });
await continueBtn2.click();

// Wait for thankyou
await page.waitForURL(/thankyou|checkoutReturn/i, { timeout: 60000 });
await page.waitForLoadState('networkidle');
console.log(`Thankyou URL: ${page.url()}`);

// Extract order number
const bodyText = await page.locator('body').innerText();
console.log('\n=== Thankyou page text (first 2000 chars) ===');
console.log(bodyText.substring(0, 2000));

// Try regex
const patterns = [
  /Nummer\s*:?\s*(\d+)/,
  /Bestellnummer\s*:?\s*(\d+)/,
  /order number\s*:?\s*(\d+)/i,
  /order\s+#?\s*(\d+)/i,
  /We registered your order with number\s*(\d+)/i,
  /Vielen Dank.*?(\d{4,})/is,
];
for (const p of patterns) {
  const m = bodyText.match(p);
  if (m) console.log(`Pattern "${p}" matched: ${m[1]}`);
}

await browser.close();
