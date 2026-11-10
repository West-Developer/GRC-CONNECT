import { chromium } from 'playwright';

async function testFaculty() {
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage();

  await page.goto('http://localhost:3000/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[type="email"]').fill('faculty.seed@grc.test');
  await page.locator('input[type="password"]').fill('password');
  await page.locator('button[type="submit"]').click();
  await page.waitForURL('**/portal**', { timeout: 10000 });

  await page.goto('http://localhost:3000/portal/grade-submission', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(3000);

  const card = page.locator('button:has-text("Ready to submit"), button:has-text("IT101")').first();
  await card.waitFor({ state: 'visible', timeout: 15000 });
  console.log('Found card! Clicking...');
  await card.click();
  await page.waitForTimeout(1500);

  // Wait for table
  const table = page.locator('table').first();
  await table.waitFor({ state: 'visible', timeout: 15000 });
  console.log('Found grade table!');

  // Change grade in first row
  const selectTrigger = page.locator('table button[aria-label*="Grade for"]').first();
  await selectTrigger.waitFor({ state: 'visible', timeout: 8000 });
  console.log('Clicking grade select dropdown...');
  await selectTrigger.click();
  await page.waitForTimeout(500);

  // Select 1.25
  const option = page.locator('[role="option"]:has-text("1.25")').first();
  await option.click();
  await page.waitForTimeout(500);
  console.log('Selected grade 1.25!');

  // Enter remarks
  const remarks = page.locator('table input[aria-label*="Remarks for"]').first();
  await remarks.fill('Outstanding class performance and lab output');
  await page.waitForTimeout(300);
  console.log('Entered remarks!');

  // Click Save draft
  const saveBtn = page.locator('button:has-text("Save draft")').first();
  const isDisabled = await saveBtn.isDisabled();
  console.log('Save draft disabled?', isDisabled);
  if (!isDisabled) {
    await saveBtn.click();
    await page.waitForTimeout(2000);
    console.log('Save draft clicked successfully!');
  }

  // Click Submit final grades
  const submitBtn = page.locator('button:has-text("Submit final grades")').first();
  console.log('Submit final grades disabled?', await submitBtn.isDisabled());
  if (!(await submitBtn.isDisabled())) {
    await submitBtn.click();
    await page.waitForTimeout(1000);
    console.log('Submit final grades modal opened!');

    const alertTitle = await page.locator('[role="alertdialog"] h2').innerText();
    console.log('Alert Dialog Title:', alertTitle);

    // Click "Review again" (Cancel)
    await page.locator('[role="alertdialog"] button:has-text("Review again")').click();
    await page.waitForTimeout(500);
    console.log('Alert Dialog closed cleanly!');
  }

  await browser.close();
}

testFaculty().catch(console.error);
