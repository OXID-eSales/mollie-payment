import { chromium } from './node_modules/playwright/index.mjs';

const browser = await chromium.launch({ headless: false });
const page = await browser.newPage();

await page.goto('http://localhost.local/index.php?cl=account');
await page.waitForLoadState('networkidle');
await page.waitForTimeout(2000);

const selectors = [
  'input[name="lgn_usr"]',
  'input[name="lgn_pwd"]',
  'input[type="hidden"][name="lgn_usr"]',
  'input[type="text"]',
  'input[type="email"]',
  'input[type="password"]',
  'input:not([type="hidden"])',
  'form',
];

console.log('=== Selector checks ===');
for (const sel of selectors) {
  const count = await page.locator(sel).count();
  console.log(`  ${sel}: ${count} found`);
}

// Get all inputs with their types
const inputs = await page.evaluate(() => {
  const els = document.querySelectorAll('input');
  return Array.from(els).map(el => ({
    name: el.name,
    type: el.type,
    id: el.id,
    cls: el.className,
    visible: el.offsetParent !== null,
  }));
});
console.log('\n=== All inputs ===');
inputs.forEach(i => console.log(`  type=${i.type}, name=${i.name}, id=${i.id}, visible=${i.visible}`));

const bodyText = await page.locator('body').innerText();
console.log('\n=== Page text (first 1500 chars) ===');
console.log(bodyText.substring(0, 1500));

await browser.close();
