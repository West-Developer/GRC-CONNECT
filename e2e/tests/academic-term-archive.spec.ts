import { expect, test } from "@playwright/test"

import { ApiArranger, loginAs } from "../fixtures/api-client"
import { authenticateViaApi } from "../fixtures/auth"

// Journey: the last step of a semester is archiving it, and the only place
// the next school year and semester are ever typed in is the modal that
// step opens — there is no longer a standalone "create school year and
// semester" form for an already-started institution (see ADR 0020 and the
// archive-term-dialog.tsx component this drives).

interface AcademicTermsEnvelope {
  data: { id: number; school_year: string; semester: string; status: string }[]
}

test("archiving the current semester opens the next one via the archive modal", async ({
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

  // A school year guaranteed not to collide with the seeded archived range
  // (2020-2021..2022-2023) or the current term (2026-2027).
  const nextSchoolYear = "2099-2100"

  await authenticateViaApi(page, request, "registrar_head")
  await page.goto("/portal/academic-terms")

  await expect(
    page.getByText(`Current: ${currentTerm.school_year} · ${currentTerm.semester}`),
  ).toBeVisible({ timeout: 15_000 })

  await page.getByRole("button", { name: "Archive current semester" }).click()

  const dialog = page.getByRole("dialog")
  await expect(
    dialog.getByRole("heading", {
      name: `Archiving ${currentTerm.school_year} · ${currentTerm.semester}. What comes next?`,
    }),
  ).toBeVisible()

  await dialog.getByLabel("Next school year").fill(nextSchoolYear)
  // Semester defaults to "1st" — left as-is.
  await dialog.getByRole("button", { name: "Archive and open next term" }).click()

  // The mutation archives the current term, creates the next as Draft, and
  // writes 4 college-workflow rows plus 5 audience-window rows in one
  // transaction — past the default 5s budget.
  await expect(dialog).not.toBeVisible({ timeout: 15_000 })
  await expect(
    page.getByText(`Current: ${nextSchoolYear} · 1st`),
  ).toBeVisible({ timeout: 15_000 })

  // The retired term is not deleted — it moves into archived history.
  const refreshedTerms = (await arranger.get(
    "/api/v1/academic-terms",
  )) as AcademicTermsEnvelope
  const retiredTerm = refreshedTerms.data.find((term) => term.id === currentTerm.id)
  expect(retiredTerm?.status).toBe("archived")
})
