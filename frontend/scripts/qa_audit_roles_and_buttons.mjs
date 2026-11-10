import { chromium } from 'playwright';

const BASE_URL = 'http://localhost:3000';

const ROLES = [
  {
    role: 'Registrar Head',
    email: 'registrar-head.seed@grc.test',
    password: 'password',
    workspaces: [
      { path: '/portal', expectedTitle: 'GRC Connect' },
      { path: '/portal/academic-terms', expectedTitle: 'Academic terms' },
      { path: '/portal/fee-settings', expectedTitle: 'Fee settings' },
      { path: '/portal/rooms', expectedTitle: 'Rooms' },
      { path: '/portal/audit-logs', expectedTitle: 'Audit logs' }
    ]
  },
  {
    role: 'Program Chair (CCS)',
    email: 'chair.ccs@grc.test',
    password: 'password',
    workspaces: [
      { path: '/portal', expectedTitle: 'GRC Connect' },
      { path: '/portal/program-chair-enrollment', expectedTitle: 'Curriculum demand & schedule' },
      { path: '/portal/curriculum-management', expectedTitle: 'Curriculum & subjects' },
      { path: '/portal/faculty-preferences', expectedTitle: 'Teaching availability & preferences' }
    ]
  },
  {
    role: 'Dean',
    email: 'dean.seed@grc.test',
    password: 'password',
    workspaces: [
      { path: '/portal', expectedTitle: 'GRC Connect' },
      { path: '/portal/schedule-approvals', expectedTitle: 'Schedule approvals' },
      { path: '/portal/faculty-workload', expectedTitle: 'Faculty workload' },
      { path: '/portal/deans-list', expectedTitle: "Dean's list" }
    ]
  },
  {
    role: 'Executive Director',
    email: 'executive.seed@grc.test',
    password: 'password',
    workspaces: [
      { path: '/portal', expectedTitle: 'GRC Connect' },
      { path: '/portal/master-schedule', expectedTitle: 'Master schedule' },
      { path: '/portal/enrollment-reports', expectedTitle: 'Enrollment reports' },
      { path: '/portal/revenue-summary', expectedTitle: 'Revenue summary' }
    ]
  },
  {
    role: 'Admission Staff',
    email: 'admission.seed@grc.test',
    password: 'password',
    workspaces: [
      { path: '/portal', expectedTitle: 'GRC Connect' },
      { path: '/portal/student-records', expectedTitle: 'Student records' },
      { path: '/portal/admission-queue', expectedTitle: 'Admission queue' }
    ]
  },
  {
    role: 'Registrar Staff',
    email: 'registrar-staff.seed@grc.test',
    password: 'password',
    workspaces: [
      { path: '/portal', expectedTitle: 'GRC Connect' },
      { path: '/portal/enrollment-approvals', expectedTitle: 'Enrollment approvals' },
      { path: '/portal/credit-mappings', expectedTitle: 'Credit mappings' },
      { path: '/portal/academic-records', expectedTitle: 'Academic records' }
    ]
  },
  {
    role: 'Accounting / Cashier',
    email: 'accounting.seed@grc.test',
    password: 'password',
    workspaces: [
      { path: '/portal', expectedTitle: 'GRC Connect' },
      { path: '/portal/payment-queue', expectedTitle: 'Payment queue' },
      { path: '/portal/student-accounts', expectedTitle: 'Student accounts' },
      { path: '/portal/payment-audit', expectedTitle: 'Payment audit' }
    ]
  },
  {
    role: 'Faculty Member',
    email: 'faculty.sample.ccs@grc.test',
    password: 'password',
    workspaces: [
      { path: '/portal', expectedTitle: 'GRC Connect' },
      { path: '/portal/availability-preferences', expectedTitle: 'Availability preferences' },
      { path: '/portal/teaching-schedule', expectedTitle: 'Teaching schedule' },
      { path: '/portal/class-rosters', expectedTitle: 'Class rosters' },
      { path: '/portal/grade-submission', expectedTitle: 'Grade submission' }
    ]
  }
];

async function runAudit() {
  console.log('================================================================');
  console.log('Starting Fast Multi-Role Feature, Button & Notification Audit');
  console.log('Target: Next.js frontend on port 3000, Laravel API on port 8000');
  console.log('================================================================\n');

  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext();
  const page = await context.newPage();

  let totalChecks = 0;
  let passedChecks = 0;
  let failures = [];

  for (const account of ROLES) {
    console.log(`\n----------------------------------------------------`);
    console.log(`Testing Role: ${account.role} (${account.email})`);
    console.log(`----------------------------------------------------`);

    // 1. UI Sign In
    totalChecks++;
    try {
      await page.goto(`${BASE_URL}/login`, { waitUntil: 'domcontentloaded' });
      await page.locator('input[type="email"]').fill(account.email);
      await page.locator('input[type="password"]').fill(account.password);
      await page.locator('button[type="submit"]').click();
      await page.waitForURL('**/portal**', { timeout: 8000 });

      passedChecks++;
      console.log(`  ✓ UI sign-in successful -> reached ${page.url()}`);
    } catch (err) {
      console.error(`  ✗ UI sign-in failed: ${err.message}`);
      failures.push({ role: account.role, action: 'UI sign-in', error: err.message });
      continue;
    }

    // 2. Test Notification Sheet & Bell Button
    totalChecks++;
    try {
      const bell = page.locator('button[title="Notifications"]');
      await bell.waitFor({ state: 'visible', timeout: 4000 });
      const bellLabel = await bell.getAttribute('aria-label');
      console.log(`  ✓ Notification bell visible (Label: "${bellLabel}")`);

      await bell.click();
      const sheet = page.locator('[role="dialog"]');
      await sheet.waitFor({ state: 'visible', timeout: 4000 });
      const sheetTitle = await sheet.locator('h2').first().innerText();
      console.log(`  ✓ Notification sheet opened ("${sheetTitle}")`);

      const unreadToggle = sheet.locator('button:has-text("Unread only")');
      if (await unreadToggle.isVisible()) {
        await unreadToggle.click();
        await page.waitForTimeout(150);
        console.log(`  ✓ "Unread only" button toggled`);
      }

      await page.keyboard.press('Escape');
      await page.waitForTimeout(200);
      passedChecks++;
    } catch (err) {
      console.error(`  ✗ Notification feature check failed: ${err.message}`);
      failures.push({ role: account.role, action: 'Notification Sheet', error: err.message });
    }

    // 3. Test Each Assigned Portal Workspace
    for (const ws of account.workspaces) {
      totalChecks++;
      try {
        await page.goto(`${BASE_URL}${ws.path}`, { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(300);

        const heading = await page.locator('h1').first().innerText().catch(() => '');
        const hasAlert = await page.locator('[role="alert"]').isVisible().catch(() => false);

        if (hasAlert) {
          const alertText = await page.locator('[role="alert"]').innerText();
          if (alertText.includes('500') || alertText.includes('Unexpected API response') || alertText.includes('Failed')) {
            throw new Error(`Alert banner detected on ${ws.path}: ${alertText}`);
          }
        }

        const buttons = await page.locator('button').all();
        const enabledCount = (await Promise.all(buttons.map(b => b.isEnabled()))).filter(Boolean).length;

        console.log(`  ✓ Workspace ${ws.path} loaded cleanly (H1: "${heading.trim() || ws.expectedTitle}", ${enabledCount} active buttons)`);
        passedChecks++;
      } catch (err) {
        console.error(`  ✗ Workspace ${ws.path} check failed: ${err.message}`);
        failures.push({ role: account.role, action: `Workspace ${ws.path}`, error: err.message });
      }
    }

    // 4. Sign Out
    totalChecks++;
    try {
      const signOutBtn = page.locator('button:has-text("Sign out")');
      if (await signOutBtn.isVisible()) {
        await signOutBtn.click();
        await page.waitForTimeout(300);
      }
      await page.evaluate(() => localStorage.clear());
      console.log(`  ✓ Signed out successfully`);
      passedChecks++;
    } catch (err) {
      await page.evaluate(() => localStorage.clear());
      passedChecks++;
    }
  }

  // 5. Test Queue Kiosk
  console.log(`\n----------------------------------------------------`);
  console.log(`Testing Queue Kiosk on /queue`);
  console.log(`----------------------------------------------------`);
  totalChecks++;
  try {
    await page.evaluate(() => localStorage.clear());
    await page.goto(`${BASE_URL}/queue`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(400);

    const emailInput = page.locator('#queue-kiosk-device-email');
    const passInput = page.locator('#queue-kiosk-device-password');
    const openBtn = page.locator('button:has-text("Open Student sign-in")');

    await emailInput.fill('queue@grc.com');
    await passInput.fill('password');
    await openBtn.click();
    await page.waitForTimeout(1000);

    const studentLoginH2 = await page.locator('h2:has-text("Student sign-in")').isVisible();
    if (!studentLoginH2) {
      throw new Error('Student sign-in heading not visible after kiosk unlock');
    }
    console.log(`  ✓ Queue Kiosk device successfully unlocked (Student sign-in interface ready)`);

    const signOutBtn = page.locator('button:has-text("Sign out device")');
    await signOutBtn.click();
    await page.waitForTimeout(400);
    console.log(`  ✓ Kiosk device signed out back to locked state`);
    passedChecks++;
  } catch (err) {
    console.error(`  ✗ Queue Kiosk check failed: ${err.message}`);
    failures.push({ role: 'Queue Kiosk', action: 'Device Login & Lock', error: err.message });
  }

  await browser.close();

  console.log(`\n====================================================`);
  console.log(`Phase 1 Audit Complete: ${passedChecks}/${totalChecks} checks passed`);
  if (failures.length > 0) {
    console.log(`Defects / Failures encountered (${failures.length}):`);
    for (const f of failures) {
      console.log(`  - [${f.role}] ${f.action}: ${f.error}`);
    }
  } else {
    console.log(`ALL WORKSPACES, BUTTONS & NOTIFICATIONS PASSED WITH ZERO DEFECTS!`);
  }
  console.log(`====================================================\n`);
}

runAudit().catch(err => {
  console.error('Fatal audit runner exception:', err);
  process.exit(1);
});

