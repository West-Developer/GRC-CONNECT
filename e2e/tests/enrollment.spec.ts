import { expect, test } from "@playwright/test"

import { ApiArranger, loginAs } from "../fixtures/api-client"
import { SEED_STUDENT_SCENARIOS } from "../fixtures/seed-identities"

// Journey 6: Student eligibility, selection, and enrollment submission.
//
// student5.seed@grc.test (irregular) is used rather than student.seed@grc.test:
// student1 already carries an active enrollment for the active term
// (DemoEnrollmentSeeder), which the workspace deliberately blocks a second
// submission against. student5 has no seeded enrollment at all, and — being
// the one irregular seed identity — is the student who actually sees the
// per-subject picker this journey drives; every regular student (0001-0004)
// now enrols by block instead (see block-enrollment.spec.ts).
//
// The irregular window is staggered to open ~28 days after a fresh seed
// (AcademicTermSeeder), so this journey opens it for real over the API as
// the Registrar Head first — the same PATCH the Registrar's own enrollment
// schedule card sends — before driving the student UI.

interface AudienceWindow {
  audience: string
  opens_at: string | null
  closes_at: string | null
}

interface EnrollmentScheduleEnvelope {
  data: {
    academic_term_id: number
    enrollment_opens_at: string | null
    enrollment_closes_at: string | null
    audiences: AudienceWindow[]
  }
}

interface AcademicTermsEnvelope {
  data: { id: number; status: string }[]
}

async function openIrregularWindowNow(arranger: ApiArranger): Promise<void> {
  const terms = (await arranger.get("/api/v1/academic-terms")) as AcademicTermsEnvelope
  const currentTerm = terms.data.find((term) => term.status === "semester_ongoing")
  if (!currentTerm) {
    throw new Error("No semester_ongoing term found — run the seed chain first.")
  }

  const schedule = (await arranger.get(
    `/api/v1/academic-terms/${currentTerm.id}/enrollment-windows`,
  )) as EnrollmentScheduleEnvelope

  const opensAt = new Date(Date.now() - 60_000).toISOString()
  const closesAt = new Date(Date.now() + 30 * 24 * 60 * 60 * 1000).toISOString()
  // The term-wide dates are `required` on this PATCH, and every audience
  // window must fit inside them. A Draft term carries no term-wide dates
  // yet (they're set here, on this very card) and the other four audience
  // windows still hold their original seed-time values, which this test
  // never touches — so the term-wide range must be wide enough to contain
  // both those untouched seed dates and the window this test opens, not
  // just whatever the current stored value happens to be.
  const termOpensAt = new Date(Date.now() - 365 * 24 * 60 * 60 * 1000).toISOString()
  const termClosesAt = new Date(Date.now() + 365 * 24 * 60 * 60 * 1000).toISOString()

  await arranger.patch(`/api/v1/academic-terms/${currentTerm.id}/enrollment-schedule`, {
    enrollment_opens_at: termOpensAt,
    enrollment_closes_at: termClosesAt,
    windows: schedule.data.audiences.map((window) =>
      window.audience === "irregular"
        ? { audience: window.audience, opens_at: opensAt, closes_at: closesAt }
        : { audience: window.audience, opens_at: window.opens_at, closes_at: window.closes_at },
    ),
  })
}

test("journey 6 — an irregular student selects a subject's section and submits enrollment", async ({
  page,
  request,
}) => {
  // Same multi-waterfall stacking risk as block-enrollment.spec.ts.
  test.setTimeout(60_000)

  const registrarSession = await loginAs(request, "registrar_head")
  await openIrregularWindowNow(new ApiArranger(request, registrarSession))

  // student5.seed@grc.test is a scenario account, not one of the 9 role
  // identities authenticateViaApi/signInViaUi cover — sign in via the real
  // login form directly.
  await page.goto("/login")
  await page.getByLabel("Email address").fill(SEED_STUDENT_SCENARIOS.irregular.email)
  await page.getByLabel("Password", { exact: true }).fill("password")
  await page.getByRole("button", { name: "Sign in" }).click()
  await expect(page).toHaveURL(/\/portal/)

  await page.goto("/portal/enrollment")
  await expect(
    page.getByRole("heading", { name: "Select your subjects" }),
  ).toBeVisible()

  const subjectCard = page.getByRole("article").first()
  // Same term-select -> eligible-subjects waterfall as block-enrollment.spec.ts —
  // can land past the default 5s budget.
  await expect(subjectCard).toBeVisible({ timeout: 15_000 })
  await subjectCard.getByLabel("Section", { exact: true }).click()
  await page.getByRole("option").first().click()

  await expect(
    page.getByRole("heading", { name: "Review your enrollment" }),
  ).toBeVisible()
  await page.getByRole("button", { name: "Submit enrollment" }).click()

  await page
    .getByRole("alertdialog")
    .getByRole("button", { name: "Confirm submission" })
    .click()

  // Same post-submit refetch waterfall as block-enrollment.spec.ts.
  await expect(
    page.getByText(/Enrollment submitted and pending registrar approval/),
  ).toBeVisible({ timeout: 15_000 })
})
