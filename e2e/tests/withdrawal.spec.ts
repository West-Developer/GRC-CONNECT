import { expect, test } from "@playwright/test"

import { ApiArranger, loginAs } from "../fixtures/api-client"
import { authenticateViaApi } from "../fixtures/auth"

// Journey 13: Withdrawal idempotency.
//
// useCreateWithdrawalRequestMutation (frontend/src/features/hooks/
// use-withdrawal-requests.ts) exists but is not called from any component —
// there is no student-facing "Withdraw" button in the app yet, a real,
// pre-existing gap (not something to invent UI for to make this journey
// "feel" more UI-driven). The action under test — POST
// /enrollments/{id}/withdraw — is exercised directly over the API, which is
// also simply the correct way to test idempotency itself: calling the same
// endpoint twice and inspecting both responses. What UI exists for this
// flow — Registrar Staff's Drops & Withdrawals queue — is what verifies the
// observable outcome: exactly one request, not two.

interface EnrollmentSummary {
  id: number
  student_number: string
  status: string
}

test("journey 13 — a second withdrawal request for the same enrollment is rejected, not duplicated", async ({
  page,
  request,
}) => {
  const studentSession = await loginAs(request, "student")
  const studentArranger = new ApiArranger(request, studentSession)

  const list = (await studentArranger.get(
    "/api/v1/enrollments?per_page=50",
  )) as { data: EnrollmentSummary[] }
  const enrolled = list.data.find((e) => e.status === "enrolled")
  if (!enrolled) {
    throw new Error(
      "No enrolled enrollment found for student.seed@grc.test to withdraw from.",
    )
  }

  const firstResponse = await request.post(
    `http://127.0.0.1:8000/api/v1/enrollments/${enrolled.id}/withdraw`,
    {
      headers: {
        Authorization: `Bearer ${studentSession.token}`,
        Accept: "application/json",
      },
      data: { reason: "E2E journey 13: idempotency verification." },
    },
  )
  expect(firstResponse.status()).toBe(201)

  const secondResponse = await request.post(
    `http://127.0.0.1:8000/api/v1/enrollments/${enrolled.id}/withdraw`,
    {
      headers: {
        Authorization: `Bearer ${studentSession.token}`,
        Accept: "application/json",
      },
      data: { reason: "E2E journey 13: second call, must not duplicate." },
    },
  )
  expect(secondResponse.status()).toBe(422)
  const secondBody = (await secondResponse.json()) as {
    error: { errors: Record<string, string[]> }
  }
  expect(secondBody.error.errors.enrollment?.[0]).toContain(
    "already pending",
  )

  // Verify the observable outcome via the one piece of UI that exists for
  // this flow: exactly one row for this student, not two.
  await authenticateViaApi(page, request, "registrar_staff")
  await page.goto("/portal/drops-withdrawals")

  await expect(
    page.getByRole("heading", { name: "Drops & withdrawals" }),
  ).toBeVisible()

  const withdrawalTable = page.getByRole("table", {
    name: "Withdrawal requests",
  })
  const matchingRows = withdrawalTable.getByRole("row", {
    name: new RegExp(enrolled.student_number),
  })
  await expect(matchingRows).toHaveCount(1)
})
