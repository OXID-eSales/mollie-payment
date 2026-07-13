import { chromium } from './node_modules/playwright/index.mjs';

const browser = await chromium.launch({ headless: false });
const page = await browser.newPage();

const shopUrl = 'http://localhost.local';

// Login
await page.goto(`${shopUrl}/index.php?cl=account`);
await page.waitForLoadState('networkidle');
await page.waitForTimeout(1000);
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
  await page.waitForTimeout(500);
}
console.log('✓ Added to cart');

// Try to reach order page directly
await page.goto(`${shopUrl}/index.php?cl=order&lang=1`);
await page.waitForLoadState('networkidle');
await page.waitForTimeout(2000);

console.log('\n=== Order page content ===');
const html = await page.content();
// Find buttons
const buttons = await page.evaluate(() => {
  const btns = document.querySelectorAll('button, input[type="submit"], input[type="button"]');
  return Array.from(btns).map(b => ({
    text: b.textContent?.trim().substring(0, 50),
    id: b.id,
    name: b.name,
    cls: b.className,
    type: b.type,
  }));
});
console.log('\n=== Buttons on order page ===');
buttons.forEach(b => console.log(`  text="${b.text}", id=${b.id}, name=${b.name}`));

// Check for Mollie-related elements
const mollieRelated = await page.evaluate(() => {
  const allText = document.body.innerText;
  const hasMollie = allText.toLowerCase().includes('mollie');
  const inputs = document.querySelectorAll('input[type="radio"][value*="mollie"], input[value*="mollie"]');
  return {
    hasMollieInText: hasMollie,
    radioCount: inputs.length,
    radioValues: Array.from(inputs).map(i => i.value),
  };
});
console.log('\n=== Mollie-related ===');
console.log(JSON.stringify(mollieRelated, null, 2));

// What's on the page
const bodyText = await page.locator('body').innerText();
console.log('\n=== Page text (first 2000 chars) ===');
console.log(bodyText.substring(0, 2000));

await browser.close();
