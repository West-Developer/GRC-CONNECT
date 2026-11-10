import { chromium } from 'playwright';

(async () => {
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage();
  await page.goto('http://localhost:3000/login');
  await page.locator('input[type="email"]').fill('registrar-head.seed@grc.test');
  await page.locator('input[type="password"]').fill('password');
  await page.locator('button[type="submit"]').click();
  await page.waitForURL('**/portal**');
  console.log('Navigated to:', page.url());
  const bell = page.locator('button[title="Notifications"]');
  await bell.waitFor({ state: 'visible', timeout: 5000 });
  console.log('Bell visible:', await bell.isVisible());
  await bell.click();
  const sheet = page.locator('[role="dialog"]');
  await sheet.waitFor({ state: 'visible', timeout: 5000 });
  console.log('Sheet opened:', await sheet.isVisible());
  const title = await sheet.locator('h2').first().innerText();
  console.log('Sheet title:', title);
  await browser.close();
})();

