import { chromium } from 'playwright';

async function testAdmission() {
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage();

  await page.goto('http://localhost:3000/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[type="email"]').fill('admission.seed@grc.test');
  await page.locator('input[type="password"]').fill('password');
  await page.locator('button[type="submit"]').click();
  await page.waitForURL('**/portal**', { timeout: 10000 });

  await page.goto('http://localhost:3000/portal/student-records', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2000);

  const heading = await page.locator('h1').innerText();
  console.log('Heading:', heading);

  const tabs = await page.locator('[role="tab"]').allInnerTexts();
  console.log('Tabs on page:', tabs);

  // Check form inputs on default tab
  const inputs = await page.locator('input').all();
  console.log('Inputs count on default view:', inputs.length);
  for (const input of inputs) {
    const id = await input.getAttribute('id');
    const name = await input.getAttribute('name');
    const visible = await input.isVisible();
    console.log(`Input id="${id}" name="${name}" visible=${visible}`);
  }

  // Click Student Directory
  console.log('\nClicking Student Directory tab...');
  await page.locator('[role="tab"]:has-text("Student Directory")').click();
  await page.waitForTimeout(2000);

  const dirButtons = await page.locator('button').allInnerTexts();
  console.log('Buttons on Student Directory tab (first 20):', dirButtons.slice(0, 20));

  await browser.close();
}

testAdmission().catch(console.error);

