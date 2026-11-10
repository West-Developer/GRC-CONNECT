import { chromium } from 'playwright';
import path from 'path';

const BASE_URL = 'http://localhost:3000';
const ARTIFACT_DIR = 'C:/Users/Westlie Casuncad/.gemini/antigravity/brain/3b8a0109-e2e7-46a5-bff7-6a89cfc626a2';

async function run() {
  console.log('Launching browser...');
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({
    viewport: { width: 1280, height: 900 }
  });
  const page = await context.newPage();

  console.log('Logging in as student.seed@grc.test...');
  await page.goto(`${BASE_URL}/login`, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('input[type="email"]', { timeout: 10000 });
  await page.locator('input[type="email"]').fill('student.seed@grc.test');
  await page.locator('input[type="password"]').fill('password');
  await page.locator('button[type="submit"]').click();

  await page.waitForURL('**/portal**', { timeout: 15000 });
  console.log('Logged in successfully!');

  console.log('Navigating to /portal/enrollment...');
  await page.goto(`${BASE_URL}/portal/enrollment`);

  // Wait for section cards or enrollment page
  await page.waitForSelector('article[aria-label$="section"], button:has-text("Choose")', { timeout: 15000 });
  console.log('Found section options on enrollment page!');

  // Take Screenshot 1: Thumbnail section grid
  const shot1Path = path.join(ARTIFACT_DIR, 'state1_section_cards.png');
  await page.screenshot({ path: shot1Path, fullPage: true });
  console.log(`Saved screenshot 1: ${shot1Path}`);

  // Find a selectable section button (e.g., "Choose IT101" or first available)
  const chooseButtons = await page.$$('button:has-text("Choose")');
  console.log(`Found ${chooseButtons.length} section buttons`);
  if (chooseButtons.length > 0) {
    const firstButton = chooseButtons[0];
    const buttonText = await firstButton.innerText();
    console.log(`Clicking button: ${buttonText}...`);
    await firstButton.click();

    // Wait for the schedule list / toggle group to appear
    await page.waitForSelector('button:has-text("Schedule list"), button:has-text("View in calendar")', { timeout: 10000 });
    console.log('Schedule and layout toggle revealed!');

    // Take Screenshot 2: Schedule revealed
    const shot2Path = path.join(ARTIFACT_DIR, 'state2_schedule_revealed.png');
    await page.screenshot({ path: shot2Path, fullPage: true });
    console.log(`Saved screenshot 2: ${shot2Path}`);

    // Click "View in calendar"
    const calendarToggle = await page.$('button:has-text("View in calendar")');
    if (calendarToggle) {
      console.log('Clicking "View in calendar"...');
      await calendarToggle.click();
      await page.waitForTimeout(500);

      // Take Screenshot 3: Calendar view
      const shot3Path = path.join(ARTIFACT_DIR, 'state3_calendar_view.png');
      await page.screenshot({ path: shot3Path, fullPage: true });
      console.log(`Saved screenshot 3: ${shot3Path}`);
    }

    // Click "Change section"
    const changeSectionBtn = await page.$('button:has-text("Change section")');
    if (changeSectionBtn) {
      console.log('Clicking "Change section"...');
      await changeSectionBtn.click();
      await page.waitForSelector('button:has-text("Choose")', { timeout: 10000 });
      console.log('Returned to section thumbnail cards!');

      // Take Screenshot 4: Returned to section thumbnail cards
      const shot4Path = path.join(ARTIFACT_DIR, 'state4_returned_to_sections.png');
      await page.screenshot({ path: shot4Path, fullPage: true });
      console.log(`Saved screenshot 4: ${shot4Path}`);
    }
  }

  await browser.close();
  console.log('Browser test complete!');
}

run().catch((err) => {
  console.error('Error running test:', err);
  process.exit(1);
});