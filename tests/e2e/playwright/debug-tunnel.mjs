import { chromium } from './node_modules/playwright/index.mjs';

const browser = await chromium.launch({ headless: false });
const page = await browser.newPage();

const shopUrl = 'https://daniil.oxiddev.de';

// Login
console.log('STEP 1: Login...');
await page.goto(`${shopUrl}/index.php?cl=account`);
await page.waitForLoadState('networkidle');
await page.locator('#loginUser').fill('playwright.user@oxid-esales.dev');
await page.locator('#loginPwd').fill('useruser');
await page.locator('#loginButton').click();
await page.waitForLoadState('networkidle');
await page.waitForTimeout(1000);
console.log(`✓ Logged in. URL: ${page.url()}`);

// Add product
console.log('STEP 2: Add product...');
await page.goto(`${shopUrl}/en/Spare-parts/Axle-parts/Wishbone-aluminum.html`);
await page.waitForLoadState('networkidle');
const toBasket = page.locator('#toBasket').first();
if (await toBasket.isVisible({ timeout: 3000 }).catch(() => false)) {
  await toBasket.click();
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(1000);
}
console.log(`✓ Added to cart. URL: ${page.url()}`);

// Checkout step by step
console.log('STEP 3: cl=user...');
await page.goto(`${shopUrl}/index.php?cl=user&lang=1`);
await page.waitForLoadState('networkidle');
await page.waitForTimeout(2000);
console.log(`URL: ${page.url()}`);

// Continue through payment
const continueBtn = page.locator('button:has-text("Weiter"), button:has-text("Continue"), button:has-text("Next")').first();
if (await continueBtn.isVisible({ timeout: 2000 }).catch(() => false)) {
  await continueBtn.click();
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(2000);
}
console.log(`After continue URL: ${page.url()}`);

console.log('STEP 4: cl=payment...');
await page.goto(`${shopUrl}/index.php?cl=payment&lang=1`);
await page.waitForLoadState('networkidle');
await page.waitForTimeout(2000);
console.log(`URL: ${page.url()}`);

// Check for Mollie radio
const mollieRadio = page.locator('input[type="radio"][value="oe_payments_mollie"]');
if (await mollieRadio.isVisible({ timeout: 2000 }).catch(() => false)) {
  console.log('✓ Mollie radio found');
  await mollieRadio.check({ force: true });
} else {
  console.log('✗ Mollie radio NOT found');
}

// Check radios
const allRadios = await page.evaluate(() => {
  const inputs = document.querySelectorAll('input[type="radio"]');
  return Array.from(inputs).map(i => ({ name: i.name, value: i.value })).filter(i => i.value);
});
console.log('All radios:', allRadios);

// Continue to order
const continueBtn2 = page.locator('button:has-text("Weiter"), button:has-text("Continue"), button:has-text("Next")').first();
if (await continueBtn2.isVisible({ timeout: 2000 }).catch(() => false)) {
  await continueBtn2.click();
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(2000);
}
console.log(`After payment continue URL: ${page.url()}`);

// Order page
console.log('STEP 5: cl=order...');
await page.goto(`${shopUrl}/index.php?cl=order&lang=1`);
await page.waitForLoadState('networkidle');
await page.waitForTimeout(3000);
console.log(`Order page URL: ${page.url()}`);

// Look for Mollie button
const mollieBtn = page.locator('button:has-text("Mollie"), button:has-text("Pay with Mollie"), #mollie-checkout-btn');
if (await mollieBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
  console.log('✓ Mollie button found!');
} else {
  console.log('✗ Mollie button NOT found');
}

// All buttons
const allButtons = await page.evaluate(() => {
  const btns = document.querySelectorAll('button, input[type="submit"]');
  return Array.from(btns).map(b => b.textContent?.trim().substring(0, 60));
});
console.log('\nButtons with "Mollie":', allButtons.filter(b => b.toLowerCase().includes('mollie')));
console.log('All button texts:', allButtons.slice(0, 15));

// Body text
const orderBody = await page.locator('body').innerText();
console.log('\nHas Mollie in body:', orderBody.toLowerCase().includes('mollie'));
console.log('Order text (first 1000):', orderBody.substring(0, 1000));

await browser.close();
