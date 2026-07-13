import { chromium } from './node_modules/playwright/index.mjs';

const browser = await chromium.launch({ headless: true });
const page = await browser.newPage();

await page.goto('https://daniil.oxiddev.de/index.php?cl=account');
await page.waitForLoadState('networkidle');
await page.waitForTimeout(2000);

const selectors = [
  'input[name="lgn_usr"]',
  'input[name="lgn_pwd"]',
  'input[type="email"]',
  'input[type="text"]',
  '#loginUser',
  '#loginPwd',
  'form',
  'button[type="submit"]',
];

console.log('\n=== Selector checks ===');
for (const sel of selectors) {
  const count = await page.locator(sel).count();
  console.log(`  ${sel}: ${count} found`);
}

const bodyText = await page.locator('body').innerText();
console.log('\n=== Page text (first 2000 chars) ===');
console.log(bodyText.substring(0, 2000));

// Also get the full HTML
const html = await page.content();
console.log('\n=== HTML (500-3000 chars) ===');
console.log(html.substring(500, 3000));

await browser.close();
