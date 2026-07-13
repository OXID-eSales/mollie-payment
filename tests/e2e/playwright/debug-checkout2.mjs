import { chromium } from './node_modules/playwright/index.mjs';

const browser = await chromium.launch({ headless: false });
const page = await browser.newPage();

const shopUrl = 'http://localhost.local';

// Login
await page.goto(`${shopUrl}/index.php?cl=account`);
await page.waitForLoadState('networkidle');
await page.locator('#loginUser').fill('playwright.user@oxid-esales.dev');
await page.locator('#loginPwd').fill('useruser');
await page.locator('#loginButton').click();
await page.waitForLoadState('networkidle');
await page.waitForTimeout(1000);
console.log('✓ Logged in');

// Add product
await page.goto(`${shopUrl}/en/Spare-parts/Axle-parts/Wishbone-aluminum.html`);
await page.waitForLoadState('networkidle');
const toBasket = page.locator('#toBasket').first();
if (await toBasket.isVisible({ timeout: 3000 }).catch(() => false)) {
  await toBasket.click();
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(1000);
}
console.log(`✓ Added to cart. URL: ${page.url()}`);

// Walk through checkout steps
console.log('\n--- STEP: cl=user ---');
await page.goto(`${shopUrl}/index.php?cl=user&lang=1`);
await page.waitForLoadState('networkidle');
await page.waitForTimeout(2000);
console.log(`URL: ${page.url()}`);

// Check for payment methods
const radios = await page.evaluate(() => {
  const inputs = document.querySelectorAll('input[type="radio"]');
  return Array.from(inputs).map(i => ({ name: i.name, value: i.value })).filter(i => i.value);
});
console.log('Radio buttons:', radios);

// Click continue
const continueBtn = page.locator('button:has-text("Weiter"), button:has-text("Continue"), button:has-text("Next")').first();
if (await continueBtn.isVisible({ timeout: 2000 }).catch(() => false)) {
  await continueBtn.click();
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(2000);
}
console.log(`After continue URL: ${page.url()}`);

console.log('\n--- STEP: cl=payment ---');
await page.goto(`${shopUrl}/index.php?cl=payment&lang=1`);
await page.waitForLoadState('networkidle');
await page.waitForTimeout(2000);
console.log(`URL: ${page.url()}`);

// Check for Mollie
const mollieRadio = await page.evaluate(() => {
  const inputs = document.querySelectorAll('input[type="radio"]');
  return Array.from(inputs).map(i => ({ name: i.name, value: i.value })).filter(i => i.value && i.value.includes('mollie'));
});
console.log('Mollie radio:', mollieRadio);

const allRadios = await page.evaluate(() => {
  const inputs = document.querySelectorAll('input[type="radio"]');
  return Array.from(inputs).map(i => ({ name: i.name, value: i.value })).filter(i => i.value);
});
console.log('All radios:', allRadios);

// Check body for Mollie text
const bodyText = await page.locator('body').innerText();
const hasMollie = bodyText.toLowerCase().includes('mollie');
console.log(`Has Mollie text: ${hasMollie}`);

// Click continue to order
const continueBtn2 = page.locator('button:has-text("Weiter"), button:has-text("Continue"), button:has-text("Next")').first();
if (await continueBtn2.isVisible({ timeout: 2000 }).catch(() => false)) {
  await continueBtn2.click();
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(2000);
}
console.log(`After payment continue URL: ${page.url()}`);

// Order page
console.log('\n--- STEP: cl=order ---');
await page.goto(`${shopUrl}/index.php?cl=order&lang=1`);
await page.waitForLoadState('networkidle');
await page.waitForTimeout(3000);
console.log(`Order page URL: ${page.url()}`);

// Look for payment radio
const orderRadios = await page.evaluate(() => {
  const inputs = document.querySelectorAll('input[type="radio"]');
  return Array.from(inputs).map(i => ({ name: i.name, value: i.value })).filter(i => i.value);
});
console.log('Order page radios:', orderRadios);

// Look for Mollie button
const allButtons = await page.evaluate(() => {
  const btns = document.querySelectorAll('button, input[type="submit"]');
  return Array.from(btns).map(b => ({
    text: b.textContent?.trim().substring(0, 60),
    id: b.id,
  }));
});
console.log('\nOrder page buttons (first 20):');
allButtons.slice(0, 20).forEach(b => console.log(`  "${b.text}" id=${b.id}`));

// Body text
const orderBody = await page.locator('body').innerText();
const hasMollieText = orderBody.toLowerCase().includes('mollie');
console.log(`\nHas Mollie in body: ${hasMollieText}`);
console.log('Order page text (first 1500):');
console.log(orderBody.substring(0, 1500));

await browser.close();
