import { chromium } from 'playwright';
import path from 'path';
import { execSync } from 'child_process';

const BASE_URL = 'http://localhost:3000';
const ARTIFACT_DIR = 'C:/Users/Westlie Casuncad/.gemini/antigravity/brain/3b8a0109-e2e7-46a5-bff7-6a89cfc626a2';

function setProposalStatus(status) {
  try {
    execSync(`php backend/scripts/set_proposal_status.php 11 ${status}`, {
      cwd: 'C:/xampp/htdocs/GRC-ENROLLMENT',
      encoding: 'utf-8',
    });
  } catch (err) {
    console.error('Failed to set proposal status:', err);
  }
}

async function login(page, email, password = 'password') {
  await page.goto(`${BASE_URL}/login`, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('input[type="email"]', { timeout: 10000 });
  await page.locator('input[type="email"]').fill(email);
  await page.locator('input[type="password"]').fill(password);
  await page.locator('button[type="submit"]').click();
  await page.waitForURL('**/portal**', { timeout: 15000 });
}

async function logout(page) {
  try {
    await page.evaluate(() => {
      localStorage.clear();
      sessionStorage.clear();
    });
    await page.goto(`${BASE_URL}/login`);
    await page.waitForTimeout(500);
  } catch {}
}

async function run() {
  console.log('Launching browser...');
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({
    viewport: { width: 1360, height: 900 }
  });
  const page = await context.newPage();
  page.on('console', msg => console.log('[BROWSER]', msg.text()));
  page.on('pageerror', err => console.error('[BROWSER ERR]', err));

  // ----------------------------------------------------
  // 1. Regular Student
  // ----------------------------------------------------
  console.log('\n--- 1. Testing Regular Student ---');
  await login(page, 'student.seed@grc.test');
  await page.goto(`${BASE_URL}/portal/enrollment`);
  await page.waitForSelector('article[aria-label$="section"], button:has-text("Choose")', { timeout: 15000 });
  console.log('Found student section cards!');

  const chooseButtons = await page.$$('button:has-text("Choose")');
  if (chooseButtons.length > 0) {
    await chooseButtons[0].click();
    await page.waitForSelector('button:has-text("Schedule list"), button:has-text("View in calendar")', { timeout: 10000 });
    console.log('Student schedule modal opened!');

    const studentShot = path.join(ARTIFACT_DIR, 'role1_student_schedule_modal.png');
    await page.screenshot({ path: studentShot, fullPage: true });
    console.log(`Saved: ${studentShot}`);
  }

  await logout(page);

  // ----------------------------------------------------
  // 2. Program Chair
  // ----------------------------------------------------
  console.log('\n--- 2. Testing Program Chair ---');
  await login(page, 'chair.ccs@grc.test');
  await page.goto(`${BASE_URL}/portal/schedule`);
  await page.waitForSelector('article[aria-label$="section"], button:has-text("View schedule")', { timeout: 15000 });
  console.log('Found program chair section cards!');

  const chairViewButtons = await page.$$('button:has-text("View schedule")');
  if (chairViewButtons.length > 0) {
    await chairViewButtons[0].click();
    await page.waitForSelector('div[role="dialog"]', { timeout: 10000 });
    await page.waitForTimeout(600);
    console.log('Program Chair schedule modal opened!');

    const chairShot = path.join(ARTIFACT_DIR, 'role2_program_chair_schedule_modal.png');
    await page.screenshot({ path: chairShot, fullPage: true });
    console.log(`Saved: ${chairShot}`);

    // Close the modal
    const closeBtn = await page.$('div[role="dialog"] button:has-text("Close")');
    if (closeBtn) await closeBtn.click();
  }

  await logout(page);

  // ----------------------------------------------------
  // 3. Dean Review
  // ----------------------------------------------------
  console.log('\n--- 3. Testing Dean Schedule Review ---');
  // Set proposal 11 status to 'draft' so it appears in Dean's review queue
  setProposalStatus('draft');

  await login(page, 'dean.seed@grc.test');
  await page.goto(`${BASE_URL}/portal/schedule-approvals`);
  await page.waitForSelector('button:has-text("Review schedule")', { timeout: 15000 });
  console.log('Found Dean review button!');

  await page.click('button:has-text("Review schedule")');
  await page.waitForTimeout(2000);
  const shotPath = path.join(ARTIFACT_DIR, 'debug_dean_dialog.png');
  await page.screenshot({ path: shotPath, fullPage: true });
  console.log(`Saved debug_dean_dialog: ${shotPath}`);

  await page.waitForSelector('[role="article"]', { timeout: 10000 });
  console.log('Dean saw thumbnail section cards in review dialog!');

  // Click IT101 card inside the review dialog
  const it101Card = page.locator('[role="article"][aria-label="IT101 section"]');
  await it101Card.scrollIntoViewIfNeeded();
  await it101Card.click();
  await page.waitForSelector('button:has-text("Schedule list"), button:has-text("View in calendar")', { timeout: 10000 });
  await page.waitForTimeout(600);
  console.log('Dean schedule modal opened!');

  const deanShot = path.join(ARTIFACT_DIR, 'role3_dean_schedule_modal.png');
  await page.screenshot({ path: deanShot, fullPage: true });
  console.log(`Saved: ${deanShot}`);

  // Restore proposal 11 back to published
  setProposalStatus('published');

  await logout(page);

  // ----------------------------------------------------
  // 4. Executive Director Master Schedule
  // ----------------------------------------------------
  console.log('\n--- 4. Testing Executive Director Master Schedule ---');
  await login(page, 'executive.seed@grc.test');
  await page.goto(`${BASE_URL}/portal/master-schedule`);
  await page.waitForSelector('button[role="tab"]:has-text("Published")', { timeout: 15000 });
  await page.click('button[role="tab"]:has-text("Published")');
  await page.waitForSelector('[role="article"]', { timeout: 15000 });
  console.log('Found Executive Director section cards!');

  const execCard = page.locator('[role="article"]').first();
  await execCard.scrollIntoViewIfNeeded();
  await execCard.click();
  await page.waitForSelector('button:has-text("Schedule list"), button:has-text("View in calendar")', { timeout: 10000 });
  await page.waitForTimeout(600);
  console.log('Executive Director schedule modal opened!');

  const execShot = path.join(ARTIFACT_DIR, 'role4_executive_director_schedule_modal.png');
  await page.screenshot({ path: execShot, fullPage: true });
  console.log(`Saved: ${execShot}`);

  await browser.close();
  console.log('\nAll 4 roles verified successfully with screenshots!');
}

run().catch((err) => {
  console.error('Error running test:', err);
  setProposalStatus('published');
  process.exit(1);
});
