import { chromium } from 'playwright';
import { execSync } from 'child_process';

const BASE_URL = 'http://localhost:3000';

function updateProposal(status) {
  execSync(`php backend/scripts/set_proposal_status.php 11 ${status}`, {
    cwd: 'C:/xampp/htdocs/GRC-ENROLLMENT',
  });
}

async function debugDean() {
  updateProposal('draft');

  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage({ viewport: { width: 1360, height: 900 } });

  page.on('console', msg => console.log('PAGE LOG:', msg.text()));
  page.on('pageerror', err => console.error('PAGE ERROR:', err));

  await page.goto(`${BASE_URL}/login`);
  await page.locator('input[type="email"]').fill('dean.seed@grc.test');
  await page.locator('input[type="password"]').fill('password');
  await page.locator('button[type="submit"]').click();
  await page.waitForURL('**/portal**');

  await page.goto(`${BASE_URL}/portal/schedule-approvals`);
  await page.waitForSelector('button:has-text("Review schedule")');
  console.log('Clicking Review schedule...');
  await page.click('button:has-text("Review schedule")');

  await page.waitForTimeout(2000);
  const dialogHtml = await page.$eval('div[role="dialog"]', el => el.outerHTML).catch(e => e.message);
  console.log('Dialog HTML snippet:', dialogHtml.slice(0, 1000));

  await page.screenshot({ path: 'C:/Users/Westlie Casuncad/.gemini/antigravity/brain/3b8a0109-e2e7-46a5-bff7-6a89cfc626a2/debug_dean.png' });
  console.log('Saved debug_dean.png');

  updateProposal('published');
  await browser.close();
}

debugDean().catch(err => {
  console.error(err);
  updateProposal('published');
});

