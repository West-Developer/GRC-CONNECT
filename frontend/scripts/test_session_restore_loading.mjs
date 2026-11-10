import { chromium } from 'playwright';
import path from 'path';

const BASE_URL = 'http://localhost:3000';
const ARTIFACT_DIR = 'C:/Users/Westlie Casuncad/.gemini/antigravity/brain/3b8a0109-e2e7-46a5-bff7-6a89cfc626a2';

async function testSessionRestore() {
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({
    viewport: { width: 1360, height: 900 },
  });

  // Pre-seed localStorage before page load using addInitScript
  await context.addInitScript(() => {
    localStorage.setItem('grc.auth-token.v1', 'valid_test_token');
  });

  const page = await context.newPage();

  // Intercept the /api/v1/auth/me request and delay it so we can capture the restoring state
  await page.route('**/api/v1/auth/me', async route => {
    console.log('Intercepted /api/v1/auth/me, holding in restoring state for 3 seconds...');
    await new Promise(resolve => setTimeout(resolve, 3000));
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        data: {
          id: 1,
          name: 'Student User',
          email: 'student.seed@grc.test',
          role: 'student',
        },
      }),
    });
  });

  console.log('Navigating directly to /portal with pre-seeded token...');
  const gotoPromise = page.goto(`${BASE_URL}/portal`);

  // Wait for the status element to appear
  const statusSelector = 'div[role="status"][aria-label="Restoring your session…"]';
  await page.waitForSelector(statusSelector, { timeout: 10000 });
  console.log('Found "Restoring your session…" loading status element!');

  // Wait a short moment for animation/styles to render
  await page.waitForTimeout(600);

  // Capture screenshot of the session restore screen
  const screenshotPath = path.join(ARTIFACT_DIR, 'session_restore_loading_logo.png');
  await page.screenshot({ path: screenshotPath });
  console.log(`Captured session restore loading screenshot: ${screenshotPath}`);

  await gotoPromise.catch(() => {});
  await page.waitForTimeout(3000);
  await browser.close();
}

testSessionRestore().catch(err => {
  console.error('Test failed:', err);
  process.exit(1);
});
