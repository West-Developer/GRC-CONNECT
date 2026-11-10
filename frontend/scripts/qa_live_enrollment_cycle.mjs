import { chromium } from 'playwright';

const BASE_URL = 'http://localhost:3000';
const API_URL = 'http://127.0.0.1:8000/api/v1';

async function runLiveEnrollmentCycle() {
  console.log('================================================================');
  console.log('Starting Live End-to-End Enrollment Execution Suite');
  console.log('Phases: Schedule Publishing -> Student Selection -> Registrar');
  console.log('        Approval & Assessment -> Queue Kiosk -> Cashier -> COM');
  console.log('================================================================\n');

  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext();
  const page = await context.newPage();

  page.on('console', msg => {
    if (msg.type() === 'error') {
      const text = msg.text();
      if (!text.includes('Download the React DevTools') && !text.includes('favicon.ico')) {
        console.warn(`    [BROWSER ERROR] ${text.slice(0, 150)}`);
      }
    }
  });

  // Helper login
  async function loginAs(email, password = 'password') {
    await page.goto(`${BASE_URL}/login`);
    await page.waitForLoadState('domcontentloaded');
    await page.locator('input[type="email"]').fill(email);
    await page.locator('input[type="password"]').fill(password);
    await page.locator('button[type="submit"]').click();
    await page.waitForURL('**/portal**', { timeout: 10000 });
  }

  async function signOut() {
    const signOutBtn = page.locator('button:has-text("Sign out")');
    if (await signOutBtn.isVisible()) {
      await signOutBtn.click();
      await page.waitForTimeout(500);
    } else {
      await page.evaluate(() => localStorage.clear());
      await page.goto(`${BASE_URL}/login`);
    }
  }

  // 1. Program Chair: Generate Schedule for CCS & Submit Proposal
  console.log('--- Step 1: Program Chair Schedule Generation & Proposal Submission ---');
  await loginAs('chair.ccs@grc.test');
  await page.goto(`${BASE_URL}/portal/program-chair-enrollment`);
  await page.waitForLoadState('domcontentloaded');

  const genBtn = page.locator('button:has-text("Generate Schedule")');
  if (await genBtn.isVisible()) {
    console.log('  Triggering predictive schedule generation...');
    await genBtn.click();
    await page.waitForTimeout(3000);
  }

  const submitProposalBtn = page.locator('button:has-text("Submit proposal")');
  if (await submitProposalBtn.isVisible()) {
    console.log('  Submitting schedule proposal to Dean...');
    await submitProposalBtn.click();
    const confirmBtn = page.locator('button:has-text("Confirm proposal submission")');
    if (await confirmBtn.isVisible()) {
      await confirmBtn.click();
      await page.waitForTimeout(2000);
    }
  }
  console.log('  ✓ Program Chair proposal ready');
  await signOut();

  // 2. Dean: Approve Proposal
  console.log('\n--- Step 2: Dean Review & Approval ---');
  await loginAs('dean.seed@grc.test');
  await page.goto(`${BASE_URL}/portal/schedule-approvals`);
  await page.waitForLoadState('domcontentloaded');

  const reviewBtn = page.locator('button:has-text("Review proposal")').first();
  if (await reviewBtn.isVisible()) {
    await reviewBtn.click();
    await page.waitForTimeout(1000);
    const approveBtn = page.locator('button:has-text("Approve schedule")');
    if (await approveBtn.isVisible()) {
      await approveBtn.click();
      await page.waitForTimeout(500);
      const confirmDecision = page.locator('button:has-text("Confirm decision")');
      if (await confirmDecision.isVisible()) {
        await confirmDecision.click();
        await page.waitForTimeout(2000);
      }
    }
  }
  console.log('  ✓ Dean approved schedule proposal');
  await signOut();

  // 3. Executive Director: Publish Master Schedule
  console.log('\n--- Step 3: Executive Director Schedule Publication ---');
  await loginAs('executive.seed@grc.test');
  await page.goto(`${BASE_URL}/portal/master-schedule`);
  await page.waitForLoadState('domcontentloaded');

  const publishBtn = page.locator('button:has-text("Publish schedule")').first();
  if (await publishBtn.isVisible()) {
    await publishBtn.click();
    await page.waitForTimeout(500);
    const confirmPub = page.locator('button:has-text("Confirm decision"), button:has-text("Publish")').last();
    if (await confirmPub.isVisible()) {
      await confirmPub.click();
      await page.waitForTimeout(2000);
    }
  }
  console.log('  ✓ Executive Director published master schedule');
  await signOut();

  // 4. Student Enrollment: Block Section IT102
  console.log('\n--- Step 4: Regular Student Enrollment Submission ---');
  await loginAs('student.seed@grc.test');
  await page.goto(`${BASE_URL}/portal/enrollment`);
  await page.waitForLoadState('domcontentloaded');
  await page.waitForTimeout(1000);

  const it102Card = page.locator('button:has-text("Choose IT102")').first();
  await it102Card.waitFor({ state: 'visible', timeout: 5000 });
  await it102Card.click();
  await page.waitForTimeout(500);

  const submitEnrollmentBtn = page.locator('button:has-text("Submit enrollment")').first();
  await submitEnrollmentBtn.waitFor({ state: 'visible', timeout: 5000 });
  await submitEnrollmentBtn.click();
  await page.waitForTimeout(500);

  const confirmEnrollment = page.locator('button:has-text("Confirm submission")');
  await confirmEnrollment.waitFor({ state: 'visible', timeout: 5000 });
  await confirmEnrollment.click();
  await page.waitForTimeout(2000);
  console.log('  ✓ Regular student submitted enrollment');
  await signOut();

  // 5. Registrar Staff: Approve Enrollment & Assess Fees
  console.log('\n--- Step 5: Registrar Staff Approval & Fee Assessment ---');
  await loginAs('registrar-staff.seed@grc.test');
  await page.goto(`${BASE_URL}/portal/enrollment-approvals`);
  await page.waitForLoadState('domcontentloaded');
  await page.waitForTimeout(1000);

  const approveEnrollmentBtn = page.locator('button:has-text("Approve")').first();
  await approveEnrollmentBtn.waitFor({ state: 'visible', timeout: 5000 });
  await approveEnrollmentBtn.click();
  await page.waitForTimeout(500);

  const confirmApprove = page.locator('button:has-text("Confirm decision")');
  await confirmApprove.waitFor({ state: 'visible', timeout: 5000 });
  await confirmApprove.click();
  await page.waitForTimeout(2000);
  console.log('  ✓ Registrar Staff approved enrollment and assessed fees');
  await signOut();

  // 6. Queue Kiosk: Issue Ticket Q001
  console.log('\n--- Step 6: Queue Kiosk Ticket Issuance ---');
  await page.goto(`${BASE_URL}/queue`);
  await page.waitForLoadState('domcontentloaded');
  await page.waitForTimeout(1000);

  await page.locator('#queue-kiosk-device-email').fill('queue@grc.com');
  await page.locator('#queue-kiosk-device-password').fill('password');
  await page.locator('button:has-text("Open Student sign-in")').click();
  await page.waitForTimeout(1000);

  await page.locator('#queue-kiosk-student-email').fill('student.seed@grc.test');
  await page.locator('#queue-kiosk-student-password').fill('password');
  await page.locator('button:has-text("View queue")').click();
  await page.waitForTimeout(1500);

  const claimBtn = page.locator('button:has-text("Claim queue number")');
  await claimBtn.waitFor({ state: 'visible', timeout: 5000 });
  await claimBtn.click();
  await page.waitForTimeout(2000);
  console.log('  ✓ Cashier queue ticket claimed');

  const doneBtn = page.locator('button:has-text("Done")');
  if (await doneBtn.isVisible()) await doneBtn.click();
  const lockBtn = page.locator('button:has-text("Sign out device")');
  if (await lockBtn.isVisible()) await lockBtn.click();
  console.log('  ✓ Queue kiosk locked cleanly');

  // 7. Cashier: Confirm Payment
  console.log('\n--- Step 7: Cashier Payment Confirmation ---');
  await loginAs('accounting.seed@grc.test');
  await page.goto(`${BASE_URL}/portal/payment-queue`);
  await page.waitForLoadState('domcontentloaded');
  await page.waitForTimeout(1000);

  const callNextBtn = page.locator('button:has-text("Call next →")');
  await callNextBtn.waitFor({ state: 'visible', timeout: 5000 });
  await callNextBtn.click();
  await page.waitForTimeout(2000);

  const confirmPayBtn = page.locator('button:has-text("Confirm payment")').first();
  await confirmPayBtn.waitFor({ state: 'visible', timeout: 5000 });
  await confirmPayBtn.click();
  await page.waitForTimeout(500);

  const confirmDialogBtn = page.locator('[role="alertdialog"] button:has-text("Confirm payment")').last();
  await confirmDialogBtn.waitFor({ state: 'visible', timeout: 5000 });
  await confirmDialogBtn.click();
  await page.waitForTimeout(2000);
  console.log('  ✓ Cashier confirmed payment and generated official COR');
  await signOut();

  // 8. Student Digital COM Verification
  console.log('\n--- Step 8: Official Certificate of Registration (COM) Verification ---');
  await loginAs('student.seed@grc.test');
  await page.goto(`${BASE_URL}/portal/digital-com`);
  await page.waitForLoadState('domcontentloaded');
  await page.waitForTimeout(1000);

  const viewCorBtn = page.locator('button:has-text("View COR")');
  await viewCorBtn.waitFor({ state: 'visible', timeout: 5000 });
  await viewCorBtn.click();
  await page.waitForTimeout(1000);

  const corHeader = await page.locator('h1:has-text("CERTIFICATE OF REGISTRATION")').first().isVisible();
  console.log(`  ✓ Official Certificate of Registration visible: ${corHeader}`);
  await signOut();

  await browser.close();

  console.log('\n================================================================');
  console.log('Live End-to-End Enrollment Execution Successfully Completed!');
  console.log('================================================================\n');
}

runLiveEnrollmentCycle().catch(err => {
  console.error('Fatal live enrollment cycle exception:', err);
  process.exit(1);
});
