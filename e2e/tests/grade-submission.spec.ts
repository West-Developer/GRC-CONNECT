import { expect, test } from "@playwright/test"

import { authenticateViaApi } from "../fixtures/auth"

// Journey 9: Professor grade submission.
//
// No API arrangement needed: SectionSeeder assigns every seeded section to
// the single seeded Faculty user (faculty.seed@grc.test), and
// DemoEnrollmentSeeder enrolls student.seed@grc.test in CS102-A for the
// active term with no grade yet recorded.

test("journey 9 — Faculty records and submits a grade for an enrolled student", async ({
  page,
  request,
}) => {
  await authenticateViaApi(page, request, "faculty")
  await page.goto("/portal/grade-submission")

  await expect(
    page.getByRole("heading", { name: "Grade submission" }),
  ).toBeVisible()

  // The Section dropdown's option text is "Section {code} ({status})" with
  // no subject disambiguation ("Section A (Published)" for every A-lettered
  // section across every subject) — a real, pre-existing UX gap out of
  // scope for this E2E-foundation phase, not something to silently work
  // around by inventing option text that doesn't exist. ownSections
  // preserves GET /api/v1/sections' order (SectionSeeder's declaration
  // order: CS101-A, CS102-A, CS102-B, MATH101-A, GE101-A, PE101-A — CS102-A
  // is index 1), so this selects by position, verified against the live API.
  await page.getByLabel("Section", { exact: true }).click()
  await page.getByRole("option").nth(1).click()

  const table = page.getByRole("table", { name: "Roster grades" })
  await expect(table).toBeVisible()

  const gradeInput = table.getByLabel("Final grade for 2023-06-00001")
  await gradeInput.fill("1.75")
  await table
    .getByRole("row", { name: /2023-06-00001/ })
    .getByRole("button", { name: "Record grade" })
    .click()

  await expect(
    table.getByRole("row", { name: /2023-06-00001/ }).getByText("Draft"),
  ).toBeVisible()

  await table
    .getByRole("row", { name: /2023-06-00001/ })
    .getByRole("button", { name: "Submit" })
    .click()

  await expect(
    table.getByRole("row", { name: /2023-06-00001/ }).getByText("Submitted"),
  ).toBeVisible()
})
