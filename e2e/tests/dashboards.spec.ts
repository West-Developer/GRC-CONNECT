import { expect, test } from "@playwright/test"

import { authenticateViaApi } from "../fixtures/auth"

// Journey 16: Dean views the enrollment dashboard and stuck-students report.
// Journey 17: Executive Director views the institution dashboard.
//
// Phase 7c's factual-only design (ADR 0017): both dashboards are pure row
// counts grouped by a PRD-authoritative status enum, never institutional
// judgment.
//
// Deliberately not asserting which specific seeded student numbers appear,
// unlike most other journeys in this suite: journey 7 and journey 8 each
// hunt for "any pending_registrar_approval/pending_payment enrollment" and
// advance whichever they find (see their own header comments), and journey 6
// creates a fresh one for student4. With `fullyParallel: true` and 2 CI
// workers, file execution order is not guaranteed, so which seeded student
// ends up in-progress by the time this journey runs is not deterministic —
// only that at least one always is (the suite never drives every seeded
// enrollment to a terminal state at once). No env file sets
// `DASHBOARD_STUCK_THRESHOLD_DAYS`, so the unconfigured-threshold notice is
// an invariant regardless of ordering.

test("journey 16 — Dean reads the enrollment dashboard and stuck-students report", async ({
  page,
  request,
}) => {
  await authenticateViaApi(page, request, "dean")

  await page.goto("/portal/enrollment-dashboard")
  await expect(
    page.getByRole("heading", { name: "Enrollment dashboard" }),
  ).toBeVisible()
  // The four student groups are buttons whose accessible name leads with the
  // group and its count; the per-step chart replaces the old approval funnel.
  await expect(
    page.getByRole("button", { name: /^Enrolled: \d+ students/ }),
  ).toBeVisible()
  await expect(
    page.getByRole("heading", { name: "By department" }),
  ).toBeVisible()
  await expect(
    page.getByRole("heading", { name: "Enrollment progress by step" }),
  ).toBeVisible()
  await expect(
    page.getByRole("heading", { name: "Section fill" }),
  ).toBeVisible()
  await expect(page.getByText(/\d+ of \d+ sections published/)).toBeVisible()
  await expect(
    page.getByRole("heading", { name: "Grade submission" }),
  ).toBeVisible()

  await page.goto("/portal/stuck-students")
  await expect(
    page.getByRole("heading", { name: "Stuck students" }),
  ).toBeVisible()
  await expect(
    page.getByText(/No institutional threshold is configured/),
  ).toBeVisible()

  const stuckTable = page.getByRole("table", { name: "Stuck students" })
  const studentCells = stuckTable.getByRole("cell", {
    name: /^\d{4}-\d{2}-\d{5}$/,
  })
  await expect(studentCells.first()).toBeVisible()
  for (const cell of await studentCells.all()) {
    await expect(cell).toHaveText(/^\d{4}-\d{2}-\d{5}$/)
  }
})

test("journey 17 — Executive Director reads the institution dashboard", async ({
  page,
  request,
}) => {
  await authenticateViaApi(page, request, "executive_director")

  await page.goto("/portal/institution-dashboard")
  await expect(
    page.getByRole("heading", { name: "Institution dashboard" }),
  ).toBeVisible()
  await expect(
    page.getByRole("heading", { name: "Enrollment status, all terms" }),
  ).toBeVisible()
  await expect(page.getByText(/Enrolled: \d+/)).toBeVisible()
  await expect(
    page.getByRole("heading", { name: "Programs and sections" }),
  ).toBeVisible()
  await expect(page.getByText(/\d+ of \d+ programs active/)).toBeVisible()
  await expect(
    page.getByRole("heading", { name: "Students by program" }),
  ).toBeVisible()
  await expect(
    page.getByRole("heading", { name: "Year over year" }),
  ).toBeVisible()
})
