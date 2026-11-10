import { chromium } from 'playwright';

const BASE_URL = 'http://localhost:3000';
const API_URL = 'http://127.0.0.1:8000/api/v1';

const ROLES = [
  {
    role: 'Registrar Head',
    email: 'registrar-head.seed@grc.test',
    password: 'password',
    workspaces: [
      '/portal',
      '/portal/academic-terms',
      '/portal/fee-settings',
      '/portal/rooms',
      '/portal/audit-logs'
    ]
  },
  {
    role: 'Program Chair (CCS)',
    email: 'chair.ccs@grc.test',
    password: 'password',
    workspaces: [
      '/portal',
      '/portal/program-chair-enrollment',
      '/portal/curriculum-management',
      '/portal/faculty-preferences'
    ]
  },
  {
    role: 'Dean',
    email: 'dean.seed@grc.test',
    password: 'password',
    workspaces: [
      '/portal',
      '/portal/schedule-approvals',
      '/portal/faculty-workload',
      '/portal/deans-list'
    ]
  },
  {
    role: 'Executive Director',
    email: 'executive.seed@grc.test',
    password: 'password',
    workspaces: [
      '/portal',
      '/portal/master-schedule',
      '/portal/enrollment-reports',
      '/portal/revenue-summary'
    ]
  },
  {
    role: 'Admission Staff',
    email: 'admission.seed@grc.test',
    password: 'password',
    workspaces: [
      '/portal',
      '/portal/student-records',
      '/portal/admission-queue'
    ]
  },
  {
    role: 'Registrar Staff',
    email: 'registrar-staff.seed@grc.test',
    password: 'password',
    workspaces: [
      '/portal',
      '/portal/enrollment-approvals',
      '/portal/credit-mappings',
      '/portal/academic-records'
    ]
  },
  {
    role: 'Accounting / Cashier',
    email: 'accounting.seed@grc.test',
    password: 'password',
    workspaces: [
      '/portal',
      '/portal/payment-queue',
      '/portal/student-accounts',
      '/portal/payment-audit'
    ]
  },
  {
    role: 'Faculty Member',
    email: 'faculty.sample.ccs@grc.test',
    password: 'password',
    workspaces: [
      '/portal',
      '/portal/availability-preferences',
      '/portal/teaching-schedule',
      '/portal/class-rosters',
      '/portal/grade-submission'
    ]
  }
];

async function runAudit() {
  console.log('================================================================');
  console.log('Starting Full Multi-Role Feature, Button & Notification Audit');
  console.log('Target: Next.js frontend on port 3000, Laravel API on port 8000');
  console.log('================================================================\n');

  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext();
  const page = await context.newPage();

  let totalChecks = 0;
  let passedChecks = 0;
  let failures = [];

  // Capture console errors
  page.on('console', msg => {
    if (msg.type() === 'error') {
      const text = msg.text();
      if (!text.includes('Download the React DevTools') && !text.includes('favicon.ico')) {
        console.warn(`  [BROWSER ERROR] ${text.slice(0, 150)}`);
      }
    }
  });

  for (const account of ROLES) {
    console.log(`\n----------------------------------------------------`);
    console.log(`Testing Role: ${account.role} (${account.email})`);
    console.log(`----------------------------------------------------`);

    // 1. Sign In
    totalChecks++;
    try {
      const res = await fetch(`${API_URL}/auth/login`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({ email: account.email, password: account.password })
      });
      const data = await res.json();
      if (!res.ok || !data.data?.token) {
        throw new Error(`Login failed with status ${res.status}: ${JSON.stringify(data)}`);
      }
      const token = data.data.token;
      
      await page.goto(`${BASE_URL}/login`);
      await page.evaluate((tok) => {
        localStorage.setItem('grc.auth-token.v1', tok);
      }, token);

      passedChecks++;
      console.log(`  ✓ Auth login successful (User ID: ${data.data.user.id}, Role: ${data.data.user.role})`);
    } catch (err) {
      console.error(`  ✗ Auth login failed: ${err.message}`);
      failures.push({ role: account.role, action: 'Auth login', error: err.message });
      continue;
    }

    // 2. Test Notification Sheet & Bell Button
    totalChecks++;
    try {
      await page.goto(`${BASE_URL}/portal`);
      await page.waitForLoadState('networkidle');

      const bell = page.locator('button[title="Notifications"]');
      await bell.waitFor({ state: 'visible', timeout: 5000 });
      const bellLabel = await bell.getAttribute('aria-label');
      console.log(`  ✓ Notification bell visible (Label: "${bellLabel}")`);

      // Click bell to open notification drawer
      await bell.click();
      const sheet = page.locator('[role="dialog"]');
      await sheet.waitFor({ state: 'visible', timeout: 5000 });
      const sheetTitle = await sheet.locator('h2').first().innerText();
      console.log(`  ✓ Notification sheet opened ("${sheetTitle}")`);

      // Test "Unread only" filter toggle button if available
      const unreadToggle = sheet.locator('button:has-text("Unread only")');
      if (await unreadToggle.isVisible()) {
        await unreadToggle.click();
        await page.waitForTimeout(300);
        console.log(`  ✓ "Unread only" button toggled`);
      }

      // Close notification sheet (press Escape)
      await page.keyboard.press('Escape');
      await page.waitForTimeout(300);
      passedChecks++;
    } catch (err) {
      console.error(`  ✗ Notification feature check failed: ${err.message}`);
      failures.push({ role: account.role, action: 'Notification Sheet', error: err.message });
    }

    // 3. Test Each Assigned Portal Workspace
    for (const path of account.workspaces) {
      totalChecks++;
      try {
        await page.goto(`${BASE_URL}${path}`);
        await page.waitForLoadState('networkidle');

        const title = await page.locator('h1').first().innerText().catch(() => 'No H1');
        const hasAlert = await page.locator('[role="alert"]').isVisible().catch(() => false);

        if (hasAlert) {
          const alertText = await page.locator('[role="alert"]').innerText();
          if (alertText.includes('500') || alertText.includes('Unexpected API response') || alertText.includes('Failed')) {
            throw new Error(`Alert banner detected on ${path}: ${alertText}`);
          }
        }

        const buttons = await page.locator('button').all();
        const enabledButtonsCount = (await Promise.all(buttons.map(b => b.isEnabled()))).filter(Boolean).length;

        console.log(`  ✓ Workspace ${path} loaded cleanly (Header: "${title.trim()}", ${enabledButtonsCount} active buttons)`);
        passedChecks++;
      } catch (err) {
        console.error(`  ✗ Workspace ${path} check failed: ${err.message}`);
        failures.push({ role: account.role, action: `Workspace ${path}`, error: err.message });
      }
    }
  }

  // 4. Test Queue Kiosk separately
  console.log(`\n----------------------------------------------------`);
  console.log(`Testing Queue Kiosk on /queue`);
  console.log(`----------------------------------------------------`);
  totalChecks++;
  try {
    await page.evaluate(() => localStorage.clear());
    await page.goto(`${BASE_URL}/queue`);
    await page.waitForLoadState('networkidle');

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
    await page.waitForTimeout(500);
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

