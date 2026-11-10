import { expect, test } from "@playwright/test"

import { authenticateViaApi } from "../fixtures/auth"
import { selectOption } from "../fixtures/select"

// Journey 4: Program Chair section creation and proposal submission.
// Journey 5: Dean and Executive Director schedule approval.
//
// Chained in one spec since journey 5's precondition (a draft proposal) is
// exactly journey 4's own output — arranging it a second way via the API
// would just re-implement journey 4. "Create draft proposal" already puts a
// proposal in the exact state UpdateScheduleProposalRequest requires for
// dean_approve — there is no separate "submit for review" step.
//
// The Executive Director's half of journey 5 drives the real UI at
// /portal/master-schedule, not the API. An earlier version of this spec
// navigated to /portal/schedule-approvals (the Dean's own workspace) and,
// finding nothing there for this role, fell back to the API — recorded at
// the time as a routing gap in ScheduleDecisionWorkspace. That was wrong:
// the Executive Director's approval controls live on Master Schedule
// (MasterScheduleWorkspace embeds ScheduleDecisionControls with
// actorRole="executive_director"), which was already in their module list
// the whole time. See ADR 0016 decision 8's correction.

test("journeys 4 & 5 — section creation, proposal submission, and Dean/Executive Director approval", async ({
  page,
  request,
}) => {
  // Journey 4a: Program Chair creates a section.
  await authenticateViaApi(page, request, "program_chair")
  await page.goto("/portal/sections-schedules")
  await expect(
    page.getByRole("heading", { name: "Sections and schedules" }),
  ).toBeVisible()

  // CS201 has no seeded section yet (SectionSeeder only covers CS101, CS102,
  // MATH101, GE101, PE101), avoiding the (term, subject, section_code)
  // uniqueness constraint entirely rather than picking a fresh section_code
  // letter for an already-used subject.
  await selectOption(page, "Subject", "CS201 — Computer Programming 2")
  await page.getByLabel("Section code", { exact: true }).fill("E2E")
  await page.getByLabel("Capacity", { exact: true }).fill("30")

  // No success banner or list renders in this create view — verify the
  // actual network result rather than a UI side effect that doesn't exist.
  const sectionCreated = page.waitForResponse(
    (response) =>
      response.url().includes("/api/v1/sections") &&
      response.request().method() === "POST",
  )
  await page.getByRole("button", { name: "Save section" }).click()
  const sectionResponse = await sectionCreated
  expect(sectionResponse.status()).toBe(201)

  // Journey 4b: submit a draft schedule proposal for the active term.
  await page.goto("/portal/schedule-proposals")
  await expect(
    page.getByRole("heading", { name: "Schedule proposals" }),
  ).toBeVisible()
  await page.getByRole("button", { name: "Create draft proposal" }).click()

  const existingProposals = page.getByRole("region", {
    name: "Existing schedule proposals",
  })
  await expect(existingProposals.getByText("Draft")).toBeVisible()

  // Journey 5a: Dean approves.
  await authenticateViaApi(page, request, "dean")
  await page.goto("/portal/schedule-approvals")
  await expect(
    page.getByRole("heading", { name: "Schedule approvals" }),
  ).toBeVisible()
  await page.getByRole("button", { name: "Approve as Dean" }).first().click()
  await page.getByRole("button", { name: "Confirm decision" }).click()
  await expect(
    page.getByRole("button", { name: "Approve as Dean" }),
  ).toHaveCount(0)

  // Journey 5b: Executive Director approves, via the real Master Schedule UI.
  await authenticateViaApi(page, request, "executive_director")
  await page.goto("/portal/master-schedule")
  await expect(
    page.getByRole("heading", { name: "Master schedule" }),
  ).toBeVisible()
  await expect(
    page.getByRole("heading", { name: "Executive decisions" }),
  ).toBeVisible()

  await page
    .getByRole("button", { name: "Approve as Executive Director" })
    .first()
    .click()
  await page.getByRole("button", { name: "Confirm decision" }).click()
  await expect(
    page.getByRole("button", { name: "Approve as Executive Director" }),
  ).toHaveCount(0)

  // Regression coverage for the empty-published-sections bug this journey's
  // correction uncovered (ADR 0016 decision 8) lives in
  // master-schedule-workspace.test.tsx, not here: SectionSeeder seeds every
  // demo section as already "published", so a genuinely empty published-
  // sections list cannot occur against this suite's standard seed without
  // artificially unpublishing them first — real complexity for coverage the
  // Vitest unit test already gives precisely, by mocking the empty case
  // directly.
})
