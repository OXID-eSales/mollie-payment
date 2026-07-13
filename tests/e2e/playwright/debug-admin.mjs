import { chromium } from '@playwright/test';

const browser = await chromium.launch({ headless: false });
const page = await browser.newPage();

const shopUrl = 'https://daniil.oxiddev.de';

// Login to admin
console.log('STEP 1: Admin login...');
await page.goto(`${shopUrl}/admin/index.php`);
await page.waitForLoadState('networkidle');
console.log(`URL: ${page.url()}`);

await page.locator('input[name="user"]').fill('admin');
await page.locator('input[name="pwd"]').fill('admin');
await page.locator('input[type="submit"]').click();
await page.waitForLoadState('networkidle');
await page.waitForTimeout(2000);
console.log(`After login URL: ${page.url()}`);

// Click "Start OXID eShop Admin" if visible
const startBtn = page.locator('button:has-text("Start OXID eShop Admin")');
if (await startBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
  console.log('Found staging mode - clicking Start button');
  await startBtn.click();
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(3000);
  console.log(`After Start button URL: ${page.url()}`);
}

// Check frames
const frameNames = await page.evaluate(() => {
  return Array.from(document.querySelectorAll('iframe, frame')).map(f => ({
    name: f.name || f.id || '(unnamed)',
    src: f.src || '(no src)',
  }));
});
console.log('\nFrames:', JSON.stringify(frameNames, null, 2));

// Also try to get all frame URLs from Playwright
const pwFrames = page.frames();
console.log('\nPlaywright frames:', pwFrames.map(f => f.name()));

// Check URL
console.log('\nCurrent URL:', page.url());
console.log('Page title:', await page.title());

// Body text
const bodyText = await page.locator('body').innerText();
console.log('\nBody text (first 1000):', bodyText.substring(0, 1000));

await browser.close();
