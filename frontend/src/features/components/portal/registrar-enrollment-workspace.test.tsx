import { act, screen, waitFor, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import { axe } from "vitest-axe"

import { RegistrarEnrollmentWorkspace } from "@/features/components/portal/registrar-enrollment-workspace"
import { renderWithSession } from "@/tests/render-app"

function url(input: RequestInfo | URL) {
  if (typeof input === "string") return input
  return input instanceof URL ? input.toString() : input.url
}

const paginationLinks = {
  first: "https://api.test/enrollments?page=1",
  last: "https://api.test/enrollments?page=1",
  prev: null,
  next: null,
}
const paginationMeta = {
  current_page: 1,
  last_page: 1,
  per_page: 20,
  total: 1,
}

const pendingApprovalEnrollment = {
  type: "enrollment",
  id: 9,
  student_id: 4,
  student_number: "2026-0001",
  student_name: "Test Student",
  student_year_level: 1,
  student_financial_status: null,
  student_financial_status_label: null,
  academic_term_id: 2,
  status: "pending_registrar_approval",
  status_label: "Pending Registrar Approval",
  total_units: 3,
  requires_overload_approval: false,
  program_head_comment: null,
  submitted_at: "2026-07-30T00:00:00Z",
  program_head_decided_at: null,
  registrar_decided_at: null,
  payment_confirmed_at: null,
  enrolled_at: null,
  subjects: [],
  queue_ticket: null,
  assessment: null,
} as const

const overloadFlaggedEnrollment = {
  ...pendingApprovalEnrollment,
  id: 11,
  total_units: 24,
  requires_overload_approval: true,
} as const

const registrarStaffSession = {
  userId: "6",
  displayName: "Registrar Staff",
  role: "registrar_staff",
  signedInAt: "2026-07-29T12:00:00Z",
} as const

const registrarHeadSession = {
  userId: "5",
  displayName: "Registrar Head",
  role: "registrar_head",
  signedInAt: "2026-07-29T12:00:00Z",
} as const

describe("RegistrarEnrollmentWorkspace", () => {
  const fetchMock = vi.fn<typeof fetch>()
  beforeEach(() => vi.stubGlobal("fetch", fetchMock))
  afterEach(() => {
    vi.unstubAllGlobals()
    vi.useRealTimers()
  })

  it("does not render the approvals queue for an unauthorized role", () => {
    fetchMock.mockResolvedValue(
      new Response(
        JSON.stringify({
          data: [],
          links: paginationLinks,
          meta: paginationMeta,
        }),
      ),
    )
    renderWithSession(
      <RegistrarEnrollmentWorkspace initialModuleId="enrollment-approvals" />,
      {
        session: {
          userId: "1",
          displayName: "Student",
          role: "student",
          signedInAt: "2026-07-29T12:00:00Z",
        },
      },
    )
    expect(
      screen.getByText("This workspace is not available for your role."),
    ).toBeInTheDocument()
  })

  it("lets a Registrar Head approve and reject from the approvals queue (ADR 0030)", async () => {
    fetchMock.mockResolvedValue(
      new Response(
        JSON.stringify({
          data: [pendingApprovalEnrollment],
          links: paginationLinks,
          meta: paginationMeta,
        }),
      ),
    )
    renderWithSession(
      <RegistrarEnrollmentWorkspace initialModuleId="enrollment-approvals" />,
      { session: registrarHeadSession },
    )

    const table = await screen.findByRole("table", { name: "Enrollment queue" })
    expect(
      within(table).getByRole("button", { name: "Approve" }),
    ).toBeInTheDocument()
    expect(
      within(table).getByRole("button", { name: "Reject" }),
    ).toBeInTheDocument()
    expect(
      screen.queryByText("This workspace is not available for your role."),
    ).not.toBeInTheDocument()
  })

  it("offers approve/reject on an irregular enrollment once the Program Head has approved it", async () => {
    fetchMock.mockResolvedValue(
      new Response(
        JSON.stringify({
          data: [
            {
              ...pendingApprovalEnrollment,
              is_irregular: true,
              student_enrollment_category: "irregular",
              program_head_decided_at: "2026-07-31T00:00:00Z",
            },
          ],
          links: paginationLinks,
          meta: paginationMeta,
        }),
      ),
    )
    renderWithSession(
      <RegistrarEnrollmentWorkspace initialModuleId="enrollment-approvals" />,
      { session: registrarStaffSession },
    )

    const table = await screen.findByRole("table", { name: "Enrollment queue" })
    expect(
      within(table).getByRole("button", { name: "Approve" }),
    ).toBeInTheDocument()
    expect(within(table).queryByText(/View only/)).not.toBeInTheDocument()
  })

  it("shows an enrollment still with the Program Head as view-only", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation((input) =>
      Promise.resolve(
        new Response(
          JSON.stringify({
            data: [
              {
                ...pendingApprovalEnrollment,
                is_irregular: true,
                status: "pending_program_head_approval",
                status_label: "Pending Program Head Approval",
              },
            ],
            links: paginationLinks,
            meta: paginationMeta,
          }),
        ),
      ).then((response) => {
        void input
        return response
      }),
    )
    renderWithSession(
      <RegistrarEnrollmentWorkspace initialModuleId="enrollment-approvals" />,
      { session: registrarStaffSession },
    )

    await user.click(
      await screen.findByRole("button", { name: "Awaiting Program Head" }),
    )
    const table = await screen.findByRole("table", { name: "Enrollment queue" })
    expect(
      within(table).getByText(/Awaiting Program Head · View only/),
    ).toBeInTheDocument()
    expect(
      within(table).queryByRole("button", { name: "Approve" }),
    ).not.toBeInTheDocument()
    expect(
      within(table).queryByRole("button", { name: "Reject" }),
    ).not.toBeInTheDocument()
  })

  it("opens the student's information from their name for the Registrar Head only", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation((input) => {
      if (url(input).includes("/subject-waivers")) {
        return Promise.resolve(
          new Response(
            JSON.stringify({ data: [], meta: { blocked_subjects: [] } }),
          ),
        )
      }
      if (url(input).includes("/registrar-profile")) {
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: {
                type: "student_profile",
                id: 4,
                user_id: 40,
                student_number: "2026-0001",
                name: "Test Student",
                first_name: "Test",
                middle_initial: null,
                last_name: "Student",
                suffix: null,
                email: "test.student@grc.com",
                address: null,
                program_id: 1,
                program_code: "BSIT",
                program_name: "BS Information Technology",
                curriculum_id: 1,
                entry_year: 2026,
                curriculum_name: "BSIT Curriculum",
                curriculum_effective_school_year: "2026-2027",
                year_level: 1,
                enrollment_category: "regular",
                student_type: "freshman",
                student_type_label: "Freshman",
                admission_status: "admitted",
                admission_status_label: "Admitted",
                academic_standing: "good",
                academic_standing_label: "Good standing",
                financial_status: null,
                financial_status_label: null,
                requirements_verified_at: null,
                academic_setup_editable: false,
                account_setup_status: "active",
                invitation_delivery_status: "sent",
              },
            }),
          ),
        )
      }
      return Promise.resolve(
        new Response(
          JSON.stringify({
            data: [pendingApprovalEnrollment],
            links: paginationLinks,
            meta: paginationMeta,
          }),
        ),
      )
    })
    renderWithSession(
      <RegistrarEnrollmentWorkspace initialModuleId="enrollment-approvals" />,
      { session: registrarHeadSession },
    )

    const table = await screen.findByRole("table", { name: "Enrollment queue" })
    await user.click(
      within(table).getByRole("button", { name: /Test Student/ }),
    )

    const dialog = await screen.findByRole("dialog")
    expect(
      await within(dialog).findByText("BSIT — BS Information Technology"),
    ).toBeInTheDocument()
    expect(within(dialog).getByText("1st Year")).toBeInTheDocument()
    expect(within(dialog).getByText("Good standing")).toBeInTheDocument()
    expect(
      await within(dialog).findByText("Prerequisite waivers"),
    ).toBeInTheDocument()
  })

  it("does not turn the student's name into a link for Registrar Staff", async () => {
    fetchMock.mockResolvedValue(
      new Response(
        JSON.stringify({
          data: [pendingApprovalEnrollment],
          links: paginationLinks,
          meta: paginationMeta,
        }),
      ),
    )
    renderWithSession(
      <RegistrarEnrollmentWorkspace initialModuleId="enrollment-approvals" />,
      { session: registrarStaffSession },
    )

    const table = await screen.findByRole("table", { name: "Enrollment queue" })
    expect(
      within(table).queryByRole("button", { name: /Test Student/ }),
    ).not.toBeInTheDocument()
    expect(within(table).getByText("2026-0001")).toBeInTheDocument()
  })

  it("offers approve/reject to Registrar Staff on the approvals queue, filtered by status", async () => {
    fetchMock.mockImplementation((input) =>
      Promise.resolve(
        new Response(
          JSON.stringify({
            data: [pendingApprovalEnrollment],
            links: paginationLinks,
            meta: paginationMeta,
          }),
        ),
      ).then((response) => {
        expect(url(input)).toContain("status=pending_registrar_approval")
        return response
      }),
    )
    renderWithSession(
      <RegistrarEnrollmentWorkspace initialModuleId="enrollment-approvals" />,
      { session: registrarStaffSession },
    )

    const table = await screen.findByRole("table", { name: "Enrollment queue" })
    expect(within(table).getByText("#9")).toBeInTheDocument()
    expect(
      within(table).getByRole("button", { name: "Approve" }),
    ).toBeInTheDocument()
    expect(
      within(table).getByRole("button", { name: "Reject" }),
    ).toBeInTheDocument()
    expect(
      within(table).queryByRole("button", { name: "Void" }),
    ).not.toBeInTheDocument()
  })

  it("offers no action on an enrollment still with the Program Head (view only)", async () => {
    fetchMock.mockResolvedValue(
      new Response(
        JSON.stringify({
          data: [
            {
              ...pendingApprovalEnrollment,
              status: "pending_program_head_approval",
              status_label: "Pending Program Head Approval",
            },
          ],
          links: paginationLinks,
          meta: paginationMeta,
        }),
      ),
    )
    renderWithSession(
      <RegistrarEnrollmentWorkspace initialModuleId="enrollment-approvals" />,
      { session: registrarHeadSession },
    )

    const table = await screen.findByRole("table", { name: "Enrollment queue" })
    expect(
      within(table).queryByRole("button", { name: "Void" }),
    ).not.toBeInTheDocument()
    expect(
      within(table).queryByRole("button", { name: "Approve" }),
    ).not.toBeInTheDocument()
  })
  it("offers only Reject on an approved enrollment that is not paid yet, and sends registrar_reject", async () => {
    const user = userEvent.setup()
    const approvedEnrollment = {
      ...pendingApprovalEnrollment,
      status: "pending_payment",
      status_label: "Pending Payment",
    }
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
                ...approvedEnrollment,
                status: "rejected",
                status_label: "Rejected",
              },
            }),
          ),
        )
      }
      return Promise.resolve(
        new Response(
          JSON.stringify({
            data: [approvedEnrollment],
            links: paginationLinks,
            meta: paginationMeta,
          }),
        ),
      )
    })
    renderWithSession(
      <RegistrarEnrollmentWorkspace initialModuleId="enrollment-approvals" />,
      { session: registrarStaffSession },
    )

    const table = await screen.findByRole("table", { name: "Enrollment queue" })
    expect(
      within(table).queryByRole("button", { name: "Void" }),
    ).not.toBeInTheDocument()
    expect(
      within(table).queryByRole("button", { name: "Approve" }),
    ).not.toBeInTheDocument()
    await user.click(within(table).getByRole("button", { name: "Reject" }))

    const dialog = await screen.findByRole("alertdialog")
    expect(
      within(dialog).getByText(/already approved and is waiting for payment/i),
    ).toBeInTheDocument()
    const confirm = within(dialog).getByRole("button", {
      name: "Confirm decision",
    })
    expect(confirm).toBeDisabled()
    await user.type(
      within(dialog).getByLabelText("Reason"),
      "Requirements were incomplete.",
    )
    await user.click(confirm)

    await waitFor(() =>
      expect(bodies[0]).toMatchObject({
        action: "registrar_reject",
        reason: "Requirements were incomplete.",
      }),
    )
  })

  it("re-reads the queue every 5 seconds so new submissions show up live", async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    try {
      fetchMock.mockImplementation(() =>
        Promise.resolve(
          new Response(
            JSON.stringify({
              data: [pendingApprovalEnrollment],
              links: paginationLinks,
              meta: paginationMeta,
            }),
          ),
        ),
      )
      renderWithSession(
        <RegistrarEnrollmentWorkspace initialModuleId="enrollment-approvals" />,
        { session: registrarStaffSession },
      )
      await screen.findByRole("table", { name: "Enrollment queue" })
      const listCalls = () =>
        fetchMock.mock.calls.filter(([input]) =>
          url(input).includes("/enrollments"),
        ).length
      const before = listCalls()

      await act(async () => {
        await vi.advanceTimersByTimeAsync(5_500)
      })

      expect(listCalls()).toBeGreaterThan(before)
    } finally {
      vi.useRealTimers()
    }
  })

  it("shows the student's financial status as a badge when set", async () => {
    fetchMock.mockResolvedValue(
      new Response(
        JSON.stringify({
          data: [
            {
              ...pendingApprovalEnrollment,
              student_financial_status: "payee",
              student_financial_status_label: "Payee",
            },
          ],
          links: paginationLinks,
          meta: paginationMeta,
        }),
      ),
    )
    renderWithSession(
      <RegistrarEnrollmentWorkspace initialModuleId="enrollment-approvals" />,
      { session: registrarStaffSession },
    )

    const table = await screen.findByRole("table", { name: "Enrollment queue" })
    expect(within(table).getByText("Payee")).toBeInTheDocument()
  })

  it("reviews a student's chosen subjects in the student-style schedule table", async () => {
    const user = userEvent.setup()
    const enrollmentWithSubjects = {
      ...pendingApprovalEnrollment,
      total_units: 3,
      subjects: [
        {
          section_id: 55,
          subject_id: 550,
          subject_code: "CS101",
          paired_subject_id: null,
          subject_title: "Programming 1",
          status: "selected",
          status_label: "Selected",
        },
      ],
    }
    fetchMock.mockImplementation((input) => {
      const target = url(input)
      if (target.includes("/sections"))
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: [
                {
                  type: "section",
                  id: 55,
                  academic_term_id: 2,
                  subject_id: 7,
                  section_code: "A",
                  professor_id: null,
                  schedule_days: "MWF",
                  starts_at_time: "08:00:00",
                  ends_at_time: "09:00:00",
                  room: "RM-101",
                  capacity: 40,
                  capacity_source: "manual",
                  viability_threshold: null,
                  enrolled_count: 1,
                  remaining_seats: 39,
                  is_block_exclusive: null,
                  status: "published",
                  status_label: "Published",
                },
              ],
            }),
          ),
        )
      if (target.includes("/subjects"))
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: [
                {
                  type: "subject",
                  id: 7,
                  code: "CS101",
                  title: "Programming 1",
                  units: 3,
                  status: "active",
                  status_label: "Active",
                  is_completion_only: false,
                },
              ],
            }),
          ),
        )
      return Promise.resolve(
        new Response(
          JSON.stringify({
            data: [enrollmentWithSubjects],
            links: paginationLinks,
            meta: paginationMeta,
          }),
        ),
      )
    })
    renderWithSession(
      <RegistrarEnrollmentWorkspace initialModuleId="enrollment-approvals" />,
      { session: registrarStaffSession },
    )

    const table = await screen.findByRole("table", { name: "Enrollment queue" })
    await user.click(within(table).getByRole("button", { name: "Review" }))
    const dialog = await screen.findByRole("dialog")
    const scheduleTable = within(dialog).getByRole("table", {
      name: "Enrollment #9 schedule",
    })
    expect(within(dialog).getByText("Student")).toBeInTheDocument()
    expect(within(dialog).getByText("Test Student")).toBeInTheDocument()
    expect(within(dialog).getByText("1ST YEAR")).toBeInTheDocument()
    expect(within(dialog).getByText("2026-0001")).toBeInTheDocument()
    expect(
      within(scheduleTable)
        .getAllByRole("columnheader")
        .map((header) => header.textContent),
    ).toEqual([
      "Subject code",
      "Description",
      "Units",
      "Section ID",
      "Day",
      "Time",
      "Room",
    ])
    expect(within(scheduleTable).getByText("CS101")).toBeInTheDocument()
    expect(within(scheduleTable).getByText("Programming 1")).toBeInTheDocument()
    expect(within(scheduleTable).getByText("3")).toBeInTheDocument()
    expect(within(scheduleTable).getByText("55")).toBeInTheDocument()
    expect(within(scheduleTable).getByText("MWF")).toBeInTheDocument()
    expect(within(scheduleTable).getByText("8:00 AM–9:00 AM")).toBeInTheDocument()
    expect(within(scheduleTable).getByText("RM-101")).toBeInTheDocument()
  })

  it("refreshes the approvals queue when a student submits without a page reload", async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    let submitted = false
    fetchMock.mockImplementation(() =>
      Promise.resolve(
        new Response(
          JSON.stringify({
            data: submitted ? [pendingApprovalEnrollment] : [],
            links: paginationLinks,
            meta: paginationMeta,
          }),
        ),
      ),
    )

    renderWithSession(
      <RegistrarEnrollmentWorkspace initialModuleId="enrollment-approvals" />,
      { session: registrarStaffSession },
    )

    expect(
      await screen.findByText("No enrollments match this queue."),
    ).toBeInTheDocument()

    submitted = true
    // The role-scoped enrollment queue polls every 15 s (was 5 s; ADR 0029).
    await act(async () => {
      await vi.advanceTimersByTimeAsync(15_000)
    })

    const table = await screen.findByRole("table", { name: "Enrollment queue" })
    expect(within(table).getByText("#9")).toBeInTheDocument()
  })

  it("requires a reason before confirming a rejection", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation((_input, init) => {
      if (init?.method === "PATCH")
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: { ...pendingApprovalEnrollment, status: "rejected" },
            }),
          ),
        )
      return Promise.resolve(
        new Response(
          JSON.stringify({
            data: [pendingApprovalEnrollment],
            links: paginationLinks,
            meta: paginationMeta,
          }),
        ),
      )
    })
    renderWithSession(
      <RegistrarEnrollmentWorkspace initialModuleId="enrollment-approvals" />,
      { session: registrarStaffSession },
    )

    const table = await screen.findByRole("table", { name: "Enrollment queue" })
    await user.click(within(table).getByRole("button", { name: "Reject" }))
    expect(
      screen.getByRole("button", { name: "Confirm decision" }),
    ).toBeDisabled()
    await user.type(screen.getByLabelText("Reason"), "Missing prerequisite.")
    await user.click(screen.getByRole("button", { name: "Confirm decision" }))

    await vi.waitFor(() =>
      expect(fetchMock).toHaveBeenCalledWith(
        expect.stringContaining("/enrollments/9"),
        expect.objectContaining({
          method: "PATCH",
          body: JSON.stringify({
            action: "registrar_reject",
            reason: "Missing prerequisite.",
          }),
        }),
      ),
    )
  })

  it("requires acknowledgement before approving an overload-flagged enrollment", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation((_input, init) => {
      if (init?.method === "PATCH")
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: { ...overloadFlaggedEnrollment, status: "pending_payment" },
            }),
          ),
        )
      return Promise.resolve(
        new Response(
          JSON.stringify({
            data: [overloadFlaggedEnrollment],
            links: paginationLinks,
            meta: paginationMeta,
          }),
        ),
      )
    })
    renderWithSession(
      <RegistrarEnrollmentWorkspace initialModuleId="enrollment-approvals" />,
      { session: registrarStaffSession },
    )

    const table = await screen.findByRole("table", { name: "Enrollment queue" })
    expect(within(table).getByText("Overload")).toBeInTheDocument()
    await user.click(within(table).getByRole("button", { name: "Approve" }))

    expect(
      screen.getByRole("button", { name: "Confirm decision" }),
    ).toBeDisabled()

    await user.click(
      screen.getByRole("checkbox", {
        name: /I acknowledge this enrollment exceeds/,
      }),
    )
    await user.click(screen.getByRole("button", { name: "Confirm decision" }))

    await vi.waitFor(() =>
      expect(fetchMock).toHaveBeenCalledWith(
        expect.stringContaining("/enrollments/11"),
        expect.objectContaining({
          method: "PATCH",
          body: JSON.stringify({
            action: "registrar_approve",
            overload_acknowledged: true,
          }),
        }),
      ),
    )
  })

  it("does not require acknowledgement for an enrollment that was never flagged", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation((_input, init) => {
      if (init?.method === "PATCH")
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: { ...pendingApprovalEnrollment, status: "pending_payment" },
            }),
          ),
        )
      return Promise.resolve(
        new Response(
          JSON.stringify({
            data: [pendingApprovalEnrollment],
            links: paginationLinks,
            meta: paginationMeta,
          }),
        ),
      )
    })
    renderWithSession(
      <RegistrarEnrollmentWorkspace initialModuleId="enrollment-approvals" />,
      { session: registrarStaffSession },
    )

    const table = await screen.findByRole("table", { name: "Enrollment queue" })
    expect(within(table).queryByText("Overload")).not.toBeInTheDocument()
    await user.click(within(table).getByRole("button", { name: "Approve" }))

    expect(
      screen.getByRole("button", { name: "Confirm decision" }),
    ).not.toBeDisabled()
  })

  it("renders a complete phone-sized approval card with review and decision actions", async () => {
    fetchMock.mockResolvedValue(
      new Response(
        JSON.stringify({
          data: [
            {
              ...overloadFlaggedEnrollment,
              student_financial_status: "scholar",
              student_financial_status_label: "Scholar",
            },
          ],
          links: paginationLinks,
          meta: paginationMeta,
        }),
      ),
    )
    renderWithSession(
      <RegistrarEnrollmentWorkspace initialModuleId="enrollment-approvals" />,
      { session: registrarStaffSession },
    )

    const card = await screen.findByRole("article", {
      name: "Enrollment #11",
    })
    expect(within(card).getByText("2026-0001")).toBeInTheDocument()
    expect(within(card).getByText("Scholar")).toBeInTheDocument()
    expect(within(card).getByText("24 units")).toBeInTheDocument()
    expect(within(card).getByText("Overload")).toBeInTheDocument()
    expect(
      within(card).getByRole("button", { name: "Review" }),
    ).toBeInTheDocument()
    expect(
      within(card).getByRole("button", { name: "Approve" }),
    ).toBeInTheDocument()
    expect(
      within(card).getByRole("button", { name: "Reject" }),
    ).toBeInTheDocument()
  })

  it("supports switching between status filter tabs (Pending review, Approved, Rejected, All)", async () => {
    const user = userEvent.setup()
    fetchMock.mockResolvedValue(
      new Response(
        JSON.stringify({
          data: [pendingApprovalEnrollment],
          links: paginationLinks,
          meta: paginationMeta,
        }),
      ),
    )
    renderWithSession(
      <RegistrarEnrollmentWorkspace initialModuleId="enrollment-approvals" />,
      { session: registrarStaffSession },
    )

    expect(
      await screen.findByRole("button", { name: "Pending review" }),
    ).toBeInTheDocument()
    expect(screen.getByRole("button", { name: "Approved" })).toBeInTheDocument()
    expect(
      screen.getByRole("button", { name: "Enrolled students" }),
    ).toBeInTheDocument()
    expect(screen.getByRole("button", { name: "Rejected" })).toBeInTheDocument()
    expect(screen.getByRole("button", { name: "All" })).toBeInTheDocument()

    await user.click(screen.getByRole("button", { name: "Approved" }))
    expect(fetchMock).toHaveBeenLastCalledWith(
      expect.stringContaining("status=pending_payment"),
      expect.anything(),
    )

    await user.click(screen.getByRole("button", { name: "Enrolled students" }))
    expect(fetchMock).toHaveBeenLastCalledWith(
      expect.stringContaining("status=enrolled"),
      expect.anything(),
    )

    await user.click(screen.getByRole("button", { name: "Rejected" }))
    expect(fetchMock).toHaveBeenLastCalledWith(
      expect.stringContaining("status=rejected"),
      expect.anything(),
    )

    await user.click(screen.getByRole("button", { name: "All" }))
    expect(fetchMock).toHaveBeenLastCalledWith(
      expect.not.stringContaining("status="),
      expect.anything(),
    )
  })

  it("filters enrollments by student number or name via search input", async () => {
    const user = userEvent.setup()
    fetchMock.mockResolvedValue(
      new Response(
        JSON.stringify({
          data: [pendingApprovalEnrollment],
          links: paginationLinks,
          meta: paginationMeta,
        }),
      ),
    )
    renderWithSession(
      <RegistrarEnrollmentWorkspace initialModuleId="enrollment-approvals" />,
      { session: registrarStaffSession },
    )

    const searchInput = await screen.findByRole("searchbox", {
      name: "Search enrollments",
    })
    await user.type(searchInput, "2026-0001")

    // Debounced (ADR 0029): no request goes out for the partial text, and one
    // follows once typing stops.
    await vi.waitFor(() =>
      expect(fetchMock).toHaveBeenLastCalledWith(
        expect.stringContaining("search=2026-0001"),
        expect.anything(),
      ),
    )
    const searchRequests = fetchMock.mock.calls.filter(([input]) =>
      url(input).includes("search="),
    )
    expect(searchRequests).toHaveLength(1)
  })

  it("lets Registrar Staff view enrolled students and check schedule and info", async () => {
    const user = userEvent.setup()
    const enrolledRecord = {
      ...pendingApprovalEnrollment,
      id: 25,
      status: "enrolled",
      status_label: "Enrolled",
      student_number: "2026-9999",
      student_name: "Enrolled Student",
      subjects: [
        {
          section_id: 88,
          subject_id: 880,
          subject_code: "IT101",
          paired_subject_id: null,
          subject_title: "Intro to IT",
          status: "selected",
          status_label: "Selected",
        },
      ],
    }
    fetchMock.mockImplementation((input) => {
      const target = url(input)
      if (target.includes("/sections")) {
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: [
                {
                  type: "section",
                  id: 88,
                  academic_term_id: 2,
                  subject_id: 12,
                  section_code: "B",
                  professor_id: null,
                  schedule_days: "TTH",
                  starts_at_time: "10:00:00",
                  ends_at_time: "11:30:00",
                  room: "RM-202",
                  capacity: 40,
                  capacity_source: "manual",
                  viability_threshold: null,
                  enrolled_count: 20,
                  remaining_seats: 20,
                  is_block_exclusive: null,
                  status: "published",
                  status_label: "Published",
                },
              ],
            }),
          ),
        )
      }
      if (target.includes("/subjects")) {
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: [
                {
                  type: "subject",
                  id: 12,
                  code: "IT101",
                  title: "Intro to IT",
                  units: 3,
                  status: "active",
                  status_label: "Active",
                  is_completion_only: false,
                },
              ],
            }),
          ),
        )
      }
      return Promise.resolve(
        new Response(
          JSON.stringify({
            data: [enrolledRecord],
            links: paginationLinks,
            meta: paginationMeta,
          }),
        ),
      )
    })
    renderWithSession(
      <RegistrarEnrollmentWorkspace initialModuleId="enrollment-approvals" />,
      { session: registrarStaffSession },
    )

    const table = await screen.findByRole("table", { name: "Enrollment queue" })
    const checkBtn = within(table).getByRole("button", {
      name: "Check Schedule & Info",
    })
    expect(checkBtn).toBeInTheDocument()

    await user.click(checkBtn)
    const dialog = await screen.findByRole("dialog")
    expect(within(dialog).getByText("Enrolled Student")).toBeInTheDocument()
    expect(within(dialog).getByText("2026-9999")).toBeInTheDocument()
    expect(within(dialog).getAllByText("IT101").length).toBeGreaterThan(0)
    expect(within(dialog).getAllByText("TTH").length).toBeGreaterThan(0)
    expect(within(dialog).getAllByText("10:00 AM–11:30 AM").length).toBeGreaterThan(0)
  })

  it("has no detectable accessibility violations once loaded", async () => {
    fetchMock.mockResolvedValue(
      new Response(
        JSON.stringify({
          data: [pendingApprovalEnrollment],
          links: paginationLinks,
          meta: paginationMeta,
        }),
      ),
    )
    const { container } = renderWithSession(
      <RegistrarEnrollmentWorkspace initialModuleId="enrollment-approvals" />,
      { session: registrarStaffSession },
    )

    await screen.findByRole("table", { name: "Enrollment queue" })
    expect(await axe(container)).toHaveNoViolations()
  })
})
