// The screenshots the User Manual and Guide needs.
//
// One entry per image. `role` picks the signed-in role (omit for pages anyone
// can open). `marks` draw the numbered boxes: `n` matches the step number in
// the guide text, `selector` finds the control on the live page.
// `prepare(page)` can click or fill before the picture is taken.

const portalMarks = {
  menu: { n: 1, selector: 'nav[aria-label="Role portal navigation"]', pad: 4 },
  term: { n: 2, selector: ".portal-sidebar__footer p", pad: 6 },
  bell: {
    n: 3,
    selector: '.portal-topbar__actions button[aria-label*="otification" i]',
    pad: 5,
  },
  signOut: {
    n: 4,
    selector: '.portal-topbar__actions button[aria-label="Sign out"]',
    pad: 5,
  },
}

/** Pages every module list below produces: one picture of the page as it opens. */
const labels = {
  enrollment: "Enrollment",
  schedule: "Schedule",
  "statement-of-account": "Statement of Account",
  grades: "Grades",
  "digital-com": "Certificate of Registration",
  "student-information": "Student Information",
  "admission-requirements": "Admission",
  "student-records": "Student Records",
  "enrollment-dashboard": "Enrollment Dashboard",
  "availability-preferences": "Availability Preferences",
  "teaching-schedule": "Teaching Schedule",
  "grade-submission": "Grade Submission",
  "professor-information": "My Information",
  "program-chair-enrollment": "Enrollment",
  "irregular-enrollments": "Irregular Advising",
  "program-chair-analytics": "Enrollment Analytics",
  "subjects-prerequisites": "Curriculum Editor",
  "faculty-loading": "Faculty Loading",
  "faculty-workforce": "Faculty Workforce",
  rooms: "Rooms",
  "credit-mappings": "Credit Mappings",
  "faculty-invitations": "Invite Professors",
  "schedule-approvals": "Enrollment",
  "curriculum-approvals": "Curriculum Approvals",
  "faculty-load-monitoring": "Faculty Load",
  honors: "Honors",
  "master-schedule": "Enrollment",
  "institution-dashboard": "Institution Dashboard",
  "academic-terms": "Enrollment",
  "enrollment-approvals": "Enrollment Approvals",
  students: "Students",
  "submitted-schedules": "Submitted Schedules",
  "section-change-requests": "Schedule Change Requests",
  "grade-approvals": "Grade Approvals",
  "academic-transcripts": "Academic Transcripts",
  graduates: "Graduates",
  "cor-records": "COR Records",
  "enrollment-requests": "Enrollment Requests",
  "attrition-analytics": "Attrition Analytics",
  "registrar-analytics": "Enrollment Analytics",
  "ched-reports": "CHED Reports",
  "payment-discount": "Payment Discount",
  "audit-logs": "Audit Logs",
  "staff-invitations": "Invite Staff",
  "payment-queue": "Payment Queue",
  "payment-records": "Transaction History",
  "advance-payment": "Advance Payment",
  "queue-kiosk-access": "Queue Kiosk Access",
  "fee-settings": "Fee Settings",
}

const roles = {
  student: {
    slug: "student",
    label: "Student",
    modules: [
      "enrollment",
      "schedule",
      "statement-of-account",
      "grades",
      "digital-com",
      "student-information",
      "admission-requirements",
    ],
  },
  admission_staff: {
    slug: "admission",
    label: "Admission Staff",
    modules: ["student-records", "enrollment-dashboard"],
  },
  faculty: {
    slug: "faculty",
    label: "Professor",
    modules: [
      "availability-preferences",
      "teaching-schedule",
      "grade-submission",
      "professor-information",
    ],
  },
  program_chair: {
    slug: "chair",
    label: "Program Head",
    modules: [
      "program-chair-enrollment",
      "irregular-enrollments",
      "enrollment-dashboard",
      "program-chair-analytics",
      "subjects-prerequisites",
      "schedule",
      "faculty-loading",
      "faculty-workforce",
      "rooms",
      "credit-mappings",
      "faculty-invitations",
    ],
  },
  dean: {
    slug: "dean",
    label: "Dean",
    modules: [
      "schedule-approvals",
      "curriculum-approvals",
      "enrollment-dashboard",
      "faculty-load-monitoring",
      "honors",
    ],
  },
  executive_director: {
    slug: "executive",
    label: "Executive Director",
    modules: [
      "master-schedule",
      "curriculum-approvals",
      "institution-dashboard",
      "enrollment-dashboard",
    ],
  },
  registrar_head: {
    slug: "registrar-head",
    label: "Registrar Head",
    modules: [
      "academic-terms",
      "enrollment-approvals",
      "students",
      "credit-mappings",
      "submitted-schedules",
      "section-change-requests",
      "grade-approvals",
      "academic-transcripts",
      "graduates",
      "cor-records",
      "enrollment-requests",
      "attrition-analytics",
      "registrar-analytics",
      "ched-reports",
      "enrollment-dashboard",
      "payment-discount",
      "audit-logs",
      "rooms",
      "faculty-workforce",
      "staff-invitations",
    ],
  },
  registrar_staff: {
    slug: "registrar-staff",
    label: "Registrar Staff",
    modules: [
      "enrollment-approvals",
      "students",
      "credit-mappings",
      "enrollment-requests",
      "cor-records",
      "graduates",
      "enrollment-dashboard",
    ],
  },
  accounting_staff: {
    slug: "accounting",
    label: "Accounting Staff",
    modules: [
      "payment-queue",
      "payment-records",
      "advance-payment",
      "statement-of-account",
      "cor-records",
      "queue-kiosk-access",
      "enrollment-dashboard",
      "fee-settings",
    ],
  },
}

/** `${slug}-${moduleId}`: the id the guide content refers to. */
export function pageShotId(role, moduleId) {
  return `${roles[role].slug}-${moduleId}`
}

/** Numbered markers for pages whose steps point at specific controls. */
const pageMarks = {
  "chair-irregular-enrollments": [
    { n: 1, selector: 'button:has-text("Pending Review")', pad: 4 },
    {
      n: 2,
      selector: 'tbody tr:nth-child(1) button:text-is("Check Schedule")',
      pad: 4,
    },
    {
      n: 3,
      selector: 'tbody tr:nth-child(1) button:text-is("Prospectus")',
      pad: 4,
    },
    {
      n: 4,
      selector: 'tbody tr:nth-child(1) button:text-is("Approve")',
      pad: 4,
    },
    {
      n: 5,
      selector: 'tbody tr:nth-child(1) button:text-is("Reject")',
      pad: 4,
    },
  ],
  "registrar-staff-enrollment-approvals": [
    { n: 1, selector: 'button:has-text("Pending review")', pad: 4 },
    {
      n: 2,
      selector: 'tbody tr:nth-child(1) button:text-is("Review")',
      pad: 4,
    },
    {
      n: 3,
      selector: 'tbody tr:nth-child(1) button:text-is("Approve")',
      pad: 4,
    },
    {
      n: 4,
      selector: 'tbody tr:nth-child(1) button:text-is("Reject")',
      pad: 4,
    },
  ],
}

const pageShots = Object.entries(roles).flatMap(([role, definition]) =>
  definition.modules.map((moduleId) => {
    const id = pageShotId(role, moduleId)
    return {
      id,
      role,
      path: `/portal/${moduleId}`,
      alt: `The ${labels[moduleId]} page as a ${definition.label} sees it.`,
      ...(pageMarks[id] ? { marks: pageMarks[id] } : {}),
    }
  }),
)

const phoneAlt = (what) => `A phone showing ${what}.`

export const shots = [
  {
    id: "login",
    path: "/login",
    alt: "The GRC Connect sign-in page with the email and password fields.",
    marks: [
      { n: 1, selector: "#login-email", pad: 6 },
      { n: 2, selector: "#login-password", pad: 6 },
      { n: 3, selector: 'button[type="submit"]', pad: 5 },
    ],
  },
  {
    id: "login-code",
    path: "/login",
    alt: "The Check your email step, with a field for the 6-digit code.",
    prepare: async (page) => {
      await page.fill("#login-email", "marissa.santos@grc.test")
      await page.fill("#login-password", "password")
      await page.click('button[type="submit"]')
      await page.waitForSelector("#login-otp-code", { timeout: 90_000 })
    },
    // Step 4 of the sign-in steps in the guide.
    marks: [{ n: 4, selector: "#login-otp-code", pad: 6 }],
  },
  {
    id: "forgot-password",
    path: "/forgot-password",
    alt: "The Forgot your password page with an email field and a Send reset code button.",
  },
  {
    id: "account-setup",
    path: "/account-setup",
    alt: "The Set up your account page with email, setup code and password fields.",
  },
  {
    id: "hero-portal",
    role: "student",
    path: "/portal",
    alt: "The GRC Connect home page for a student, with the menu on the left.",
  },
  {
    id: "portal-home",
    role: "student",
    path: "/portal",
    alt: "The GRC Connect home page with numbered markers on the menu, the current term, the notification bell and Sign out.",
    marks: [
      portalMarks.menu,
      portalMarks.term,
      portalMarks.bell,
      portalMarks.signOut,
    ],
  },
  {
    id: "portal-notifications",
    role: "student",
    path: "/portal",
    alt: "The notifications panel opened from the bell, with Mark all as read and Unread only.",
    prepare: async (page) => {
      await page
        .locator('.portal-topbar__actions button[aria-label*="otification" i]')
        .first()
        .click()
      await page.waitForSelector('[role="dialog"]', { timeout: 30_000 })
    },
  },
  {
    id: "phone-login",
    mobile: true,
    path: "/login",
    alt: phoneAlt("the sign-in page"),
  },
  {
    id: "phone-menu",
    role: "student",
    mobile: true,
    path: "/portal",
    alt: phoneAlt("the portal menu open"),
    prepare: async (page) => {
      await page.click('button[aria-label="Open portal navigation"]')
      await page.waitForSelector('[role="dialog"]', { timeout: 30_000 })
    },
  },
  ...pageShots,
  // Tabs and states that need a click before the picture.
  {
    id: "student-prospectus",
    role: "student",
    path: "/portal/grades",
    alt: "The prospectus: every subject of the curriculum by year and semester, with the grade or status of each.",
    prepare: async (page) => {
      await page
        .getByRole("button", { name: "Prospectus", exact: true })
        .click()
      await page.waitForTimeout(3500)
    },
  },
  {
    id: "admission-student-directory",
    role: "admission_staff",
    path: "/portal/student-records",
    alt: "The Student Directory tab with the search box and the matching students.",
    prepare: async (page) => {
      await page.getByRole("tab", { name: "Student Directory" }).click()
      await page.waitForTimeout(1200)
      await page
        .getByPlaceholder("Name, student number, or email")
        .fill("Juan Dela Cruz")
      await page.getByRole("button", { name: "Search", exact: true }).click()
      await page.waitForTimeout(4000)
    },
    marks: [
      {
        n: 1,
        selector: 'input[placeholder="Name, student number, or email"]',
        pad: 5,
      },
      { n: 2, selector: 'button:text-is("Search")', pad: 4 },
    ],
  },
  {
    id: "admission-freshmen",
    role: "admission_staff",
    path: "/portal/student-records",
    alt: "The Freshmen tab showing each freshman's queue number and whether the Cashier has confirmed the first payment.",
    prepare: async (page) => {
      await page.getByRole("tab", { name: "Freshmen" }).click()
      await page.waitForTimeout(4000)
    },
  },
  {
    id: "admission-change-requests",
    role: "admission_staff",
    path: "/portal/student-records",
    alt: "The Change Requests tab with a student's request waiting for a decision.",
    prepare: async (page) => {
      await page.getByRole("tab", { name: "Change Requests" }).click()
      await page.waitForTimeout(4000)
    },
  },
  {
    id: "registrar-head-credit-review",
    role: "registrar_head",
    path: "/portal/credit-mappings",
    alt: "The credit review panel: each previous subject beside the subject the Program Head mapped it to, with Return and Reject on every row and Approve all below.",
    prepare: async (page) => {
      await page
        .getByRole("button", { name: /Review/ })
        .first()
        .click()
      await page.waitForSelector("text=Credits of", { timeout: 60_000 })
      await page.waitForTimeout(2500)
      // The page scrolls inside its own container, not the window.
      await page
        .locator("text=Credits of")
        .first()
        .evaluate((el) => el.scrollIntoView({ block: "start" }))
      await page.waitForTimeout(600)
    },
    marks: [
      { n: 1, selector: 'button:text-is("Return")', nth: 0, pad: 4 },
      { n: 2, selector: 'button:text-is("Reject")', nth: 0, pad: 4 },
      { n: 3, selector: 'button:has-text("Approve all")', pad: 4 },
    ],
  },
  {
    id: "faculty-grade-sheet",
    role: "faculty",
    path: "/portal/grade-submission",
    alt: "A section's grade sheet: one row per enrolled student with the grade and remarks, and the buttons to download the template, upload grades and submit.",
    prepare: async (page) => {
      await page
        .locator("button, a, [role=button]")
        .filter({ hasText: "Intro to Human Computer Interaction LEC" })
        .first()
        .click()
      await page.waitForSelector("text=Section Grade Sheet", {
        timeout: 60_000,
      })
      await page.waitForTimeout(2500)
    },
    marks: [
      { n: 1, selector: 'button:has-text("Download CSV Template")', pad: 4 },
      { n: 2, selector: 'button:has-text("Upload Grades")', pad: 4 },
      { n: 3, selector: 'button:has-text("Submit final grades")', pad: 4 },
    ],
  },
  {
    id: "faculty-subject-preferences",
    role: "faculty",
    path: "/portal/availability-preferences",
    alt: "The Subject preferences tab, where a professor ranks the subjects they want to teach.",
    prepare: async (page) => {
      await page
        .getByRole("tab", { name: "Subject preferences" })
        .click()
        .catch(async () => {
          await page
            .getByRole("button", { name: "Subject preferences" })
            .click()
        })
      await page.waitForTimeout(3000)
    },
  },
]
