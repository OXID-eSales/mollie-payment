import { chromium } from './node_modules/playwright/index.mjs';

const browser = await chromium.launch({ headless: false });
const page = await browser.newPage();

// Product page
await page.goto('http://localhost.local/Merchandise/Sonnenbrillen/Ocean-Eyes.html');
await page.waitForLoadState('networkidle');
await page.waitForTimeout(1000);

const inputs = await page.evaluate(() => {
  const els = document.querySelectorAll('button, input[type="submit"]');
  return Array.from(els).map(el => ({
    name: el.name,
    type: el.type,
    id: el.id,
    cls: el.className,
    text: el.textContent?.trim().substring(0, 50),
    visible: el.offsetParent !== null,
  }));
});
console.log('\n=== Buttons/Submits ===');
inputs.forEach(i => console.log(`  type=${i.type}, id=${i.id}, text=${i.text}, visible=${i.visible}`));

const bodyText = await page.locator('body').innerText();
console.log('\n=== Product page text (first 500) ===');
console.log(bodyText.substring(0, 500));

// Checkout page  
await page.goto('http://localhost.local/index.php?cl=payment');
await page.waitForLoadState('networkidle');
await page.waitForTimeout(1000);

const payInputs = await page.evaluate(() => {
  const els = document.querySelectorAll('input[type="radio"], button');
  return Array.from(els).map(el => ({
    name: el.name,
    type: el.type,
    id: el.id,
    value: el.value,
    text: el.textContent?.trim().substring(0, 50),
    visible: el.offsetParent !== null,
  }));
});
console.log('\n=== Payment page inputs ===');
payInputs.filter(i => i.visible).forEach(i => console.log(`  type=${i.type}, name=${i.name}, value=${i.value}`));

await browser.close();
