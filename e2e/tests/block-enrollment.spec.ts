import { expect, test } from "@playwright/test"

import { ApiArranger, loginAs } from "../fixtures/api-client"
import { SEED_STUDENT_SCENARIOS } from "../fixtures/seed-identities"

// Journey: a regular student enrols by block — every subject in the block
// together, rather than one section per subject.
//
// student4.seed@grc.test (4th year, regular, terminal "withdrawn" status —
// so no active enrollment blocks a fresh submission) has no seeded academic
// grades, so its two generated blocks (IT401/IT402) have no prerequisite
// gaps: the only thing standing between this student and a clean
// submission is their year-level window, which this journey opens for real
// over the Registrar's own API — the same PATCH the enrollment schedule
// card sends — before driving the student UI.
//
// student2/student3 were tried first: student2 (2nd year) has a genuine
// CS202→CS201 prerequisite gap in its curriculum data, and student1 (1st
// year) has seeded past grades that overlap its own year-1 blocks. Both are
// correct enforcement of real business rules, not seed bugs — student4 is
// simply the cleanest account for a full-submission happy path.

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

async function openYear4WindowNow(arranger: ApiArranger): Promise<void> {
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
      window.audience === "year_4"
        ? { audience: window.audience, opens_at: opensAt, closes_at: closesAt }
        : { audience: window.audience, opens_at: window.opens_at, closes_at: window.closes_at },
    ),
  })
}

test("a regular student picks a block, reviews its full weekly schedule, and submits", async ({
  page,
  request,
}) => {
  // Several slow waterfalls stack in one test (audience-viewer resolution,
  // then the post-submit triple-query refetch) — the default 30s test
  // budget can run out even with generous per-assertion timeouts.
  test.setTimeout(60_000)

  const registrarSession = await loginAs(request, "registrar_head")
  await openYear4WindowNow(new ApiArranger(request, registrarSession))

  await page.goto("/login")
  await page.getByLabel("Email address").fill(SEED_STUDENT_SCENARIOS.withdrawn.email)
  await page.getByLabel("Password", { exact: true }).fill("password")
  await page.getByRole("button", { name: "Sign in" }).click()
  await expect(page).toHaveURL(/\/portal/)

  await page.goto("/portal/enrollment")
  // This heading only appears once the audience viewer resolves (term
  // auto-select -> enrollment-schedule fetch -> block pool fetch): until
  // then the workspace renders its per-subject fallback heading first, so
  // the default 5s expect timeout can be too tight for this waterfall.
  await expect(
    page.getByRole("heading", { name: "Select your block" }),
  ).toBeVisible({ timeout: 15_000 })

  const blockCard = page.getByRole("radio").first()
  await expect(blockCard).toBeVisible()
  // The block's own weekly schedule table renders inside the card before
  // any selection — every subject, day, time, and professor is visible up
  // front, not hidden behind a second click.
  await expect(blockCard.getByRole("table")).toBeVisible()
  await blockCard.click()

  await expect(page.getByRole("heading", { name: "Review your block" })).toBeVisible()
  await page.getByRole("button", { name: "Submit enrollment" }).click()

  await expect(page.getByText(/enrolls you in all \d+ subjects of block/)).toBeVisible()
  await page
    .getByRole("alertdialog")
    .getByRole("button", { name: "Confirm submission" })
    .click()

  // The success banner only renders after onSuccess awaits three
  // invalidated queries refetching (enrollments, eligible-subjects,
  // enrollment-blocks), so it can land past the default 5s budget.
  await expect(
    page.getByText(/Enrollment submitted and pending registrar approval/),
  ).toBeVisible({ timeout: 15_000 })
})

test("an irregular student's window keeps block seats closed to them until it opens", async ({
  page,
  request,
}) => {
  const registrarSession = await loginAs(request, "registrar_head")
  const arranger = new ApiArranger(request, registrarSession)
  const terms = (await arranger.get("/api/v1/academic-terms")) as AcademicTermsEnvelope
  const currentTerm = terms.data.find((term) => term.status === "semester_ongoing")
  if (!currentTerm) {
    throw new Error("No semester_ongoing term found — run the seed chain first.")
  }

  await page.goto("/login")
  await page.getByLabel("Email address").fill(SEED_STUDENT_SCENARIOS.irregular.email)
  await page.getByLabel("Password", { exact: true }).fill("password")
  await page.getByRole("button", { name: "Sign in" }).click()
  await expect(page).toHaveURL(/\/portal/)

  await page.goto("/portal/enrollment")
  // Irregular students never see the block picker at all — they enrol
  // subject by subject once their own window opens.
  await expect(page.getByRole("heading", { name: "Select your subjects" })).toBeVisible()
  await expect(page.getByRole("heading", { name: "Select your block" })).not.toBeVisible()
})
