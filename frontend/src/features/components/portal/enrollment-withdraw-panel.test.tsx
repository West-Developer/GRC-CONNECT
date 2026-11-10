import { screen, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import { axe } from "vitest-axe"

import { EnrollmentWithdrawPanel } from "@/features/components/portal/enrollment-withdraw-panel"
import type { Enrollment } from "@/features/schemas/enrollment-schema"
import { renderWithSession } from "@/tests/render-app"

function url(input: RequestInfo | URL) {
  if (typeof input === "string") return input
  return input instanceof URL ? input.toString() : input.url
}

const paginationLinks = {
  first: "https://api.test/x?page=1",
  last: "https://api.test/x?page=1",
  prev: null,
  next: null,
}
const paginationMeta = {
  current_page: 1,
  last_page: 1,
  per_page: 20,
  total: 1,
}

const enrolledEnrollment: Enrollment = {
  type: "enrollment",
  id: 9,
  student_id: 4,
  student_number: "2026-0001",
  student_name: null,
  student_year_level: null,
  student_financial_status: null,
  student_financial_status_label: null,
  academic_term_id: 2,
  status: "enrolled",
  status_label: "Enrolled",
  total_units: 3,
  requires_overload_approval: false,
  program_head_comment: null,
  submitted_at: "2026-07-30T00:00:00Z",
  program_head_decided_at: null,
  registrar_decided_at: "2026-07-30T00:00:00Z",
  payment_confirmed_at: "2026-07-30T00:00:00Z",
  enrolled_at: "2026-07-30T00:00:00Z",
  subjects: [
    {
      section_id: 5,
      subject_id: 50,
      subject_code: "CS101",
      paired_subject_id: null,
      subject_title: "Programming 1",
      status: "selected",
      status_label: "Selected",
    },
  ],
  revisions: [],
  queue_ticket: {
    ticket_number: "Q000009",
    queue_date: "2026-07-30",
    status: "waiting",
    status_label: "Waiting",
    priority: "regular",
    priority_label: "Regular",
    position: 0,
  },
  assessment: null,
}

const withdrawalRequest = {
  type: "withdrawal_request",
  id: 3,
  enrollment_id: 9,
  student_number: "2026-0001",
  reason: "Personal reasons.",
  status: "pending",
  status_label: "Pending",
  processed_at: null,
  created_at: "2026-08-05T00:00:00Z",
} as const

const studentSession = {
  userId: "4",
  displayName: "Student",
  role: "student",
  signedInAt: "2026-07-29T12:00:00Z",
} as const

function mockRoutes(overrides: { requests?: readonly unknown[] } = {}) {
  return (input: RequestInfo | URL, init?: RequestInit) => {
    const target = url(input)
    if (target.includes("/withdraw") && init?.method === "POST")
      return Promise.resolve(
        new Response(JSON.stringify({ data: withdrawalRequest }), {
          status: 201,
        }),
      )
    if (target.includes("/withdrawal-requests"))
      return Promise.resolve(
        new Response(
          JSON.stringify({
            data: overrides.requests ?? [],
            links: paginationLinks,
            meta: paginationMeta,
          }),
        ),
      )
    return Promise.resolve(new Response(JSON.stringify({ data: [] })))
  }
}

describe("EnrollmentWithdrawPanel", () => {
  const fetchMock = vi.fn<typeof fetch>()
  beforeEach(() => vi.stubGlobal("fetch", fetchMock))
  afterEach(() => vi.unstubAllGlobals())

  it("shows the withdraw button and submits a reason", async () => {
    const user = userEvent.setup()
    let postBody: unknown = null
    fetchMock.mockImplementation((input, init) => {
      const target = url(input)
      if (target.includes("/withdraw") && init?.method === "POST") {
        postBody = init.body ? JSON.parse(init.body as string) : null
        return Promise.resolve(
          new Response(JSON.stringify({ data: withdrawalRequest }), {
            status: 201,
          }),
        )
      }
      return mockRoutes()(input, init)
    })

    renderWithSession(
      <EnrollmentWithdrawPanel enrollment={enrolledEnrollment} />,
      { session: studentSession },
    )

    await user.click(screen.getByRole("button", { name: "Withdraw" }))
    const dialog = await screen.findByRole("alertdialog")
    await user.type(
      within(dialog).getByLabelText("Reason"),
      "Personal reasons.",
    )
    await user.click(
      within(dialog).getByRole("button", { name: "Submit request" }),
    )

    await vi.waitFor(() =>
      expect(postBody).toEqual({ reason: "Personal reasons." }),
    )
  })

  it("requires a reason before submitting", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation(mockRoutes())
    renderWithSession(
      <EnrollmentWithdrawPanel enrollment={enrolledEnrollment} />,
      { session: studentSession },
    )

    await user.click(screen.getByRole("button", { name: "Withdraw" }))
    const dialog = await screen.findByRole("alertdialog")
    await user.click(
      within(dialog).getByRole("button", { name: "Submit request" }),
    )

    expect(screen.getByText("Reason is required.")).toBeInTheDocument()
  })

  it("shows a pending-request message instead of the withdraw button when one already exists", async () => {
    fetchMock.mockImplementation(mockRoutes({ requests: [withdrawalRequest] }))
    renderWithSession(
      <EnrollmentWithdrawPanel enrollment={enrolledEnrollment} />,
      { session: studentSession },
    )

    expect(
      await screen.findByText(/already have a withdrawal request pending/),
    ).toBeInTheDocument()
    expect(
      screen.queryByRole("button", { name: "Withdraw" }),
    ).not.toBeInTheDocument()
  })

  it("shows the student's own withdrawal request history", async () => {
    fetchMock.mockImplementation(mockRoutes({ requests: [withdrawalRequest] }))
    renderWithSession(
      <EnrollmentWithdrawPanel enrollment={enrolledEnrollment} />,
      { session: studentSession },
    )

    const table = await screen.findByRole("table", {
      name: "Your withdrawal requests",
    })
    expect(within(table).getByText("Personal reasons.")).toBeInTheDocument()
    expect(within(table).getByText("Pending")).toBeInTheDocument()
  })

  it("has no detectable accessibility violations once loaded", async () => {
    fetchMock.mockImplementation(mockRoutes())
    const { container } = renderWithSession(
      <EnrollmentWithdrawPanel enrollment={enrolledEnrollment} />,
      { session: studentSession },
    )

    await screen.findByRole("button", { name: "Withdraw" })
    expect(await axe(container)).toHaveNoViolations()
  })
})
