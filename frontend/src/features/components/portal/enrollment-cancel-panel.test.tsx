import { screen, waitFor, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import { EnrollmentCancelPanel } from "@/features/components/portal/enrollment-cancel-panel"
import type { Enrollment } from "@/features/schemas/enrollment-schema"
import { renderWithSession } from "@/tests/render-app"

const studentSession = {
  userId: "1",
  displayName: "Student",
  role: "student",
  signedInAt: "2026-07-29T12:00:00Z",
} as const

function enrollmentWith(status: Enrollment["status"]): Enrollment {
  return {
    type: "enrollment",
    id: 31,
    student_id: 4,
    student_number: "2026-0001",
    student_name: null,
    student_year_level: null,
    student_financial_status: null,
    student_financial_status_label: null,
    academic_term_id: 2,
    status,
    status_label: status,
    total_units: 3,
    requires_overload_approval: false,
    program_head_comment: null,
    submitted_at: "2026-07-30T00:00:00Z",
    program_head_decided_at: null,
    registrar_decided_at: null,
    payment_confirmed_at: null,
    enrolled_at: null,
    subjects: [],
    revisions: [],
    queue_ticket: null,
    assessment: null,
  }
}

describe("EnrollmentCancelPanel", () => {
  const fetchMock = vi.fn<typeof fetch>()
  beforeEach(() => vi.stubGlobal("fetch", fetchMock))
  afterEach(() => {
    vi.unstubAllGlobals()
    fetchMock.mockReset()
  })

  it.each([
    "pending_program_head_approval",
    "awaiting_first_payment",
    "pending_registrar_approval",
  ] as const)("is offered while the enrollment is %s", (status) => {
    renderWithSession(
      <EnrollmentCancelPanel enrollment={enrollmentWith(status)} />,
      {
        session: studentSession,
      },
    )

    expect(
      screen.getByRole("button", { name: "Cancel enrollment" }),
    ).toBeInTheDocument()
  })

  it.each([
    "draft",
    "pending_payment",
    "enrolled",
    "cancelled",
    "rejected",
  ] as const)("is not offered once the enrollment is %s", (status) => {
    renderWithSession(
      <EnrollmentCancelPanel enrollment={enrollmentWith(status)} />,
      { session: studentSession },
    )

    expect(
      screen.queryByRole("button", { name: "Cancel enrollment" }),
    ).not.toBeInTheDocument()
  })

  it("needs a reason and then sends student_cancel", async () => {
    const user = userEvent.setup()
    const bodies: unknown[] = []
    fetchMock.mockImplementation((_input, init) => {
      if (init?.method === "PATCH") {
        bodies.push(
          JSON.parse(typeof init.body === "string" ? init.body : "{}"),
        )
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: {
                ...enrollmentWith("cancelled"),
                status_label: "Cancelled",
              },
            }),
          ),
        )
      }
      return Promise.resolve(new Response(JSON.stringify({ data: [] })))
    })
    renderWithSession(
      <EnrollmentCancelPanel
        enrollment={enrollmentWith("pending_registrar_approval")}
      />,
      { session: studentSession },
    )

    await user.click(screen.getByRole("button", { name: "Cancel enrollment" }))
    const dialog = await screen.findByRole("alertdialog")
    const confirm = within(dialog).getByRole("button", {
      name: "Cancel enrollment",
    })
    expect(confirm).toBeDisabled()

    await user.type(
      within(dialog).getByLabelText("Why are you cancelling?"),
      "Picked the wrong section.",
    )
    await user.click(confirm)

    await waitFor(() =>
      expect(bodies[0]).toMatchObject({
        action: "student_cancel",
        reason: "Picked the wrong section.",
      }),
    )
  })

  it("refreshes the student's sections after cancelling so they can choose again", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation((_input, init) =>
      Promise.resolve(
        new Response(
          JSON.stringify({
            data:
              init?.method === "PATCH"
                ? { ...enrollmentWith("cancelled"), status_label: "Cancelled" }
                : [],
          }),
        ),
      ),
    )
    const { queryClient } = renderWithSession(
      <EnrollmentCancelPanel
        enrollment={enrollmentWith("pending_registrar_approval")}
      />,
      { session: studentSession },
    )
    queryClient.setQueryData(["enrollment-blocks", "1", 2], [])
    queryClient.setQueryData(["eligible-subjects", "1", 2], [])

    await user.click(screen.getByRole("button", { name: "Cancel enrollment" }))
    const dialog = await screen.findByRole("alertdialog")
    await user.type(
      within(dialog).getByLabelText("Why are you cancelling?"),
      "Picked the wrong section.",
    )
    await user.click(
      within(dialog).getByRole("button", { name: "Cancel enrollment" }),
    )

    await waitFor(() => {
      expect(
        queryClient.getQueryState(["enrollment-blocks", "1", 2])?.isInvalidated,
      ).toBe(true)
      expect(
        queryClient.getQueryState(["eligible-subjects", "1", 2])?.isInvalidated,
      ).toBe(true)
    })
  })

  it("shows the server's message and keeps the dialog open when cancelling fails", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation((_input, init) => {
      if (init?.method === "PATCH") {
        return Promise.resolve(
          new Response(
            JSON.stringify({
              error: {
                code: "VALIDATION_FAILED",
                message: "This action requires the enrollment to be pending.",
                errors: { action: ["Already approved."] },
              },
            }),
            { status: 422 },
          ),
        )
      }
      return Promise.resolve(new Response(JSON.stringify({ data: [] })))
    })
    renderWithSession(
      <EnrollmentCancelPanel
        enrollment={enrollmentWith("pending_registrar_approval")}
      />,
      { session: studentSession },
    )

    await user.click(screen.getByRole("button", { name: "Cancel enrollment" }))
    const dialog = await screen.findByRole("alertdialog")
    await user.type(
      within(dialog).getByLabelText("Why are you cancelling?"),
      "Wrong section.",
    )
    await user.click(
      within(dialog).getByRole("button", { name: "Cancel enrollment" }),
    )

    expect(await within(dialog).findByRole("alert")).toBeInTheDocument()
    expect(screen.getByRole("alertdialog")).toBeInTheDocument()
  })
})
