import type { APIRequestContext } from "@playwright/test"
import { expect, test } from "@playwright/test"

import { ApiArranger, loginWithEmail } from "../fixtures/api-client"
import { authenticateViaApi } from "../fixtures/auth"
import { SEED_STUDENT_SCENARIOS } from "../fixtures/seed-identities"

// Journey 7: Registrar approval.
//
// Self-contained API-arranged precondition, for the same reason
// payment-and-com.spec.ts (journey 8) arranges its own: the shared dev
// database carries state forward across sessions and across repeated runs
// of this suite, so DemoEnrollmentSeeder's student2.seed@grc.test being
// pending_registrar_approval is true only once per fresh seed, not durable.
// If no enrollment is currently pending, this submits a fresh one for
// student4.seed@grc.test (both of their seeded enrollments are terminal
// statuses — rejected, withdrawn — so they're eligible to submit again).

interface AcademicTerm {
  id: number
  status: string
}

interface EligibleSubject {
  is_eligible: boolean
  available_sections: { id: number }[]
}

interface EnrollmentSummary {
  id: number
  student_number: string
  status: string
}

test("journey 7 — Registrar Head approves a pending enrollment", async ({
  page,
  request,
}) => {
  await authenticateViaApi(page, request, "registrar_head")
  await page.goto("/portal/enrollment-approvals")

  await expect(
    page.getByRole("heading", { name: "Enrollment approvals" }),
  ).toBeVisible()

  const studentNumber = await ensurePendingRegistrarApproval(request)

  // A student can carry more than one historical enrollment row (e.g.
  // student4.seed@grc.test already has a rejected and a withdrawn one) —
  // scope to the specific row still pending this decision, not just the
  // student number. This locator's own accessible name includes the status
  // text, so it is re-queried fresh each call, not reused after the status
  // changes underneath it.
  const pendingRow = page.getByRole("row", {
    name: new RegExp(`${studentNumber}.*Pending Registrar Approval`),
  })
  await expect(pendingRow).toBeVisible()
  await pendingRow.getByRole("button", { name: "Approve" }).click()

  await page.getByRole("button", { name: "Confirm decision" }).click()

  // This queue lists every enrollment, not just pending ones — approving
  // moves status to pending_payment, which availableActions() maps to a
  // "Void" action instead of "Approve"/"Reject". Asserting via a freshly
  // built locator for the new status, rather than reusing pendingRow (whose
  // own name pattern no longer matches once the status text changes).
  const approvedRow = page.getByRole("row", {
    name: new RegExp(`${studentNumber}.*Pending Payment`),
  })
  await expect(approvedRow).toBeVisible()
  await expect(approvedRow.getByRole("button", { name: "Void" })).toBeVisible()
})

async function ensurePendingRegistrarApproval(
  request: APIRequestContext,
): Promise<string> {
  const registrarSession = await loginWithEmail(
    request,
    "registrar-head.seed@grc.test",
  )
  const registrarArranger = new ApiArranger(request, registrarSession)

  const list = (await registrarArranger.get(
    "/api/v1/enrollments?per_page=50",
  )) as { data: EnrollmentSummary[] }
  const existing = list.data.find(
    (e) => e.status === "pending_registrar_approval",
  )
  if (existing) {
    return existing.student_number
  }

  const studentSession = await loginWithEmail(
    request,
    SEED_STUDENT_SCENARIOS.withdrawn.email,
  )
  const studentArranger = new ApiArranger(request, studentSession)

  const terms = (await studentArranger.get(
    "/api/v1/academic-terms",
  )) as { data: AcademicTerm[] }
  const activeTerm = terms.data.find((t) => t.status === "active")
  if (!activeTerm) {
    throw new Error("No active academic term found to arrange a fresh enrollment.")
  }

  const eligible = (await studentArranger.get(
    `/api/v1/eligible-subjects?academic_term_id=${activeTerm.id}`,
  )) as { data: EligibleSubject[] }
  const firstEligible = eligible.data.find(
    (s) => s.is_eligible && s.available_sections.length > 0,
  )
  if (!firstEligible) {
    throw new Error("No eligible subject with an open section was found.")
  }

  const submitted = (await studentArranger.post("/api/v1/enrollments", {
    academic_term_id: activeTerm.id,
    sections: [{ section_id: firstEligible.available_sections[0]!.id }],
  })) as { data: { student_number: string } }

  return submitted.data.student_number
}
