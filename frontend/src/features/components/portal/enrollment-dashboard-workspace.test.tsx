import { act, screen, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import { axe } from "vitest-axe"

import { EnrollmentDashboardWorkspace } from "@/features/components/portal/enrollment-dashboard-workspace"
import { renderWithSession } from "@/tests/render-app"

// recharts' ResponsiveContainer measures its container via
// getBoundingClientRect on mount before its ResizeObserver ever fires; jsdom
// reports every element as 0x0, so without this stub the steps chart renders
// no series. See enrollment-year-over-year-chart.test.tsx for the same stub.
// eslint-disable-next-line @typescript-eslint/unbound-method
const realGetBoundingClientRect = HTMLElement.prototype.getBoundingClientRect

beforeEach(() => {
  vi.spyOn(HTMLElement.prototype, "getBoundingClientRect").mockImplementation(
    function (this: HTMLElement): DOMRect {
      if (this.classList.contains("recharts-responsive-container")) {
        return {
          width: 600,
          height: 320,
          top: 0,
          left: 0,
          bottom: 320,
          right: 600,
          x: 0,
          y: 0,
          toJSON: () => "",
        }
      }
      return realGetBoundingClientRect.call(this)
    },
  )
})

afterEach(() => {
  vi.restoreAllMocks()
})

const terms = {
  data: [
    {
      type: "academic-term",
      id: 2,
      school_year: "2026-2027",
      semester: "1st",
      starts_at: null,
      ends_at: null,
      enrollment_opens_at: null,
      enrollment_closes_at: null,
      add_drop_deadline_at: null,
      grading_deadline_at: null,
      status: "semester_ongoing",
      status_label: "Semester Ongoing",
    },
  ],
}

const summary = {
  data: {
    type: "enrollment_summary",
    academic_term_id: 2,
    status_counts: { draft: 0, enrolled: 5, rejected: 1 },
    funnel_counts: {
      submitted: 6,
      registrar_decided: 6,
      payment_confirmed: 5,
      enrolled: 4,
    },
    total_sections: 4,
    published_sections: 3,
    total_capacity: 120,
    total_enrolled_seats: 40,
    grade_status_counts: { draft: 1, submitted: 0, locked: 2 },
  },
}

const groups = (
  enrolled: number,
  inProgress: number,
  notYetDone: number,
  notEnrolled: number,
) => ({
  enrolled,
  in_progress: inProgress,
  not_yet_done: notYetDone,
  not_enrolled: notEnrolled,
})

const overview = {
  data: {
    type: "enrollment_status_overview",
    academic_term_id: 2,
    total_students: 20,
    groups: groups(11, 1, 6, 2),
    steps: {
      draft: 0,
      pending_registrar_approval: 1,
      pending_payment: 0,
      enrolled: 11,
    },
    departments: [
      {
        department: "ccs",
        label: "College of Computer Studies",
        total: 12,
        groups: groups(8, 1, 3, 0),
        steps: {
          draft: 0,
          pending_registrar_approval: 0,
          pending_payment: 0,
          enrolled: 8,
        },
      },
      {
        department: "coe",
        label: "College of Education",
        total: 8,
        groups: groups(3, 0, 3, 2),
        steps: {
          draft: 0,
          pending_registrar_approval: 1,
          pending_payment: 0,
          enrolled: 3,
        },
      },
    ],
  },
}

const sections = {
  data: {
    type: "enrollment_status_sections",
    academic_term_id: 2,
    department: "ccs",
    sections: [
      { section_code: "IT201", total: 9, groups: groups(8, 1, 0, 0) },
      { section_code: null, total: 3, groups: groups(0, 0, 3, 0) },
    ],
  },
}

const studentRow = {
  type: "enrollment_status_student",
  student_profile_id: 41,
  student_number: "2026-06-01722",
  student_name: "Ricardo C. Tagumpay",
  program_code: "BSIT",
  program_name: "BS Information Technology",
  department: "ccs",
  year_level: 2,
  section_code: "IT201",
  group: "enrolled",
  group_label: "Enrolled",
  enrollment_id: 90,
  enrollment_status: "enrolled",
  enrollment_status_label: "Enrolled",
  submitted_at: "2026-09-20T02:00:00Z",
  enrolled_at: "2026-09-22T05:30:00Z",
}

const students = {
  data: [studentRow],
  links: {
    first:
      "http://127.0.0.1:8000/api/v1/dashboards/enrollment-status/students?page=1",
    last: "http://127.0.0.1:8000/api/v1/dashboards/enrollment-status/students?page=1",
    prev: null,
    next: null,
  },
  meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 },
}

const studentDetail = {
  data: {
    type: "enrollment_status_student_detail",
    academic_term_id: 2,
    student_profile_id: 41,
    student_number: "2026-06-01722",
    student_name: "Ricardo C. Tagumpay",
    program_code: "BSIT",
    program_name: "BS Information Technology",
    department: "ccs",
    year_level: 2,
    enrollment_category: "regular",
    group: "enrolled",
    group_label: "Enrolled",
    section_code: "IT201",
    enrollment: {
      id: 90,
      status: "enrolled",
      status_label: "Enrolled",
      total_units: 24.5,
      submitted_at: "2026-09-20T02:00:00Z",
      registrar_decided_at: "2026-09-20T03:00:00Z",
      payment_confirmed_at: "2026-09-22T05:00:00Z",
      enrolled_at: "2026-09-22T05:30:00Z",
    },
    subjects: [
      {
        subject_code: "CPROG2",
        subject_title: "Computer Programming 2 LEC",
        units: 2,
        section_code: "IT201",
        status: "enrolled",
        status_label: "Enrolled",
      },
    ],
  },
}

function url(input: RequestInfo | URL) {
  return typeof input === "string"
    ? input
    : input instanceof URL
      ? input.toString()
      : input.url
}

function mockDashboardFetch(fetchMock: ReturnType<typeof vi.fn<typeof fetch>>) {
  fetchMock.mockImplementation((input) => {
    const requestUrl = url(input)
    const body = requestUrl.includes("academic-terms")
      ? terms
      : /enrollment-status\/students\/\d+/.test(requestUrl)
        ? studentDetail
        : requestUrl.includes("enrollment-status/students")
          ? students
          : requestUrl.includes("enrollment-status/sections")
            ? sections
            : requestUrl.includes("enrollment-status")
              ? overview
              : summary
    return Promise.resolve(new Response(JSON.stringify(body)))
  })
}

const registrarHead = {
  userId: "7",
  displayName: "Registrar Head",
  role: "registrar_head" as const,
  signedInAt: "2026-07-31T00:00:00Z",
}

const dean = {
  userId: "5",
  displayName: "Dean",
  role: "dean" as const,
  signedInAt: "2026-07-31T00:00:00Z",
}

describe("EnrollmentDashboardWorkspace", () => {
  const fetchMock = vi.fn<typeof fetch>()
  beforeEach(() => vi.stubGlobal("fetch", fetchMock))
  afterEach(() => {
    vi.unstubAllGlobals()
    vi.useRealTimers()
  })

  it("withholds the dashboard from an unauthorized role", () => {
    renderWithSession(<EnrollmentDashboardWorkspace />, {
      session: {
        userId: "6",
        displayName: "Student User",
        role: "student",
        signedInAt: "2026-07-31T00:00:00Z",
      },
    })
    expect(
      screen.getByText("This workspace is not available for your role."),
    ).toBeInTheDocument()
  })

  it("shows the four student groups, the per-department split and the total", async () => {
    mockDashboardFetch(fetchMock)
    renderWithSession(<EnrollmentDashboardWorkspace />, {
      session: registrarHead,
    })

    expect(
      await screen.findByRole("button", { name: /Enrolled: 11 students/ }),
    ).toBeInTheDocument()
    expect(
      screen.getByRole("button", { name: /Ongoing: 1 students/ }),
    ).toBeInTheDocument()
    expect(
      screen.getByRole("button", { name: /Not yet done: 6 students/ }),
    ).toBeInTheDocument()
    expect(
      screen.getByRole("button", { name: /Not enrolled: 2 students/ }),
    ).toBeInTheDocument()
    expect(screen.getByText("20")).toBeInTheDocument()
    expect(
      screen.getByRole("region", { name: "College of Computer Studies" }),
    ).toBeInTheDocument()
    expect(
      screen.getByRole("region", { name: "College of Education" }),
    ).toBeInTheDocument()
  })

  it("refreshes the counts on its own after a newly-submitted enrollment, without a reload", async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    let enrolledCount = 11
    fetchMock.mockImplementation((input) => {
      const requestUrl = url(input)
      if (requestUrl.includes("academic-terms"))
        return Promise.resolve(new Response(JSON.stringify(terms)))
      if (requestUrl.includes("enrollment-status"))
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: {
                ...overview.data,
                groups: groups(enrolledCount, 1, 6, 2),
              },
            }),
          ),
        )
      return Promise.resolve(new Response(JSON.stringify(summary)))
    })
    renderWithSession(<EnrollmentDashboardWorkspace />, {
      session: registrarHead,
    })

    await screen.findByRole("button", { name: /Enrolled: 11 students/ })

    enrolledCount = 12
    // The dashboard polls every 30s (Stakeholder Doc 17) — a passive
    // staff-monitoring view, so it matches the notification-bell tier
    // rather than a transactional queue's faster one.
    await act(async () => {
      await vi.advanceTimersByTimeAsync(30_000)
    })

    expect(
      await screen.findByRole("button", { name: /Enrolled: 12 students/ }),
    ).toBeInTheDocument()
  })

  it("lets staff force an immediate refresh instead of waiting for the next poll", async () => {
    const user = userEvent.setup()
    let enrolledCount = 11
    fetchMock.mockImplementation((input) => {
      const requestUrl = url(input)
      if (requestUrl.includes("academic-terms"))
        return Promise.resolve(new Response(JSON.stringify(terms)))
      if (requestUrl.includes("enrollment-status"))
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: {
                ...overview.data,
                groups: groups(enrolledCount, 1, 6, 2),
              },
            }),
          ),
        )
      return Promise.resolve(new Response(JSON.stringify(summary)))
    })
    renderWithSession(<EnrollmentDashboardWorkspace />, {
      session: registrarHead,
    })

    await screen.findByRole("button", { name: /Enrolled: 11 students/ })

    enrolledCount = 20
    await user.click(screen.getByRole("button", { name: "Refresh" }))

    expect(
      await screen.findByRole("button", { name: /Enrolled: 20 students/ }),
    ).toBeInTheDocument()
  })

  it("no longer shows the enrollment funnel and includes Enrolled in the step chart", async () => {
    mockDashboardFetch(fetchMock)
    renderWithSession(<EnrollmentDashboardWorkspace />, { session: dean })

    await screen.findByRole("button", { name: /Enrolled: 11 students/ })
    expect(screen.queryByText("Enrollment funnel")).not.toBeInTheDocument()
    expect(
      screen.getByRole("heading", { name: "Enrollment progress by step" }),
    ).toBeInTheDocument()

    const steps = within(
      screen.getByRole("table", { name: "Students at each enrollment step" }),
    )
    expect(steps.getByRole("row", { name: "Enrolled 11" })).toBeInTheDocument()
    expect(
      steps.getByRole("row", { name: "Pending Registrar Approval 1" }),
    ).toBeInTheDocument()
    // Nothing on the dashboard talks about "stuck" or a threshold any more.
    expect(screen.queryByText(/past threshold|stuck/i)).not.toBeInTheDocument()
  })

  it("shows the section fill and grade submission counts", async () => {
    mockDashboardFetch(fetchMock)
    renderWithSession(<EnrollmentDashboardWorkspace />, {
      session: registrarHead,
    })

    expect(await screen.findByText(/3 of 4/)).toBeInTheDocument()
    expect(screen.getByText(/40 of 120/)).toBeInTheDocument()
    expect(screen.getByText(/Locked: 2/)).toBeInTheDocument()
  })

  it("shows an empty state, not an error, when no term is in session", async () => {
    fetchMock.mockImplementation((input) =>
      Promise.resolve(
        new Response(
          JSON.stringify(
            url(input).includes("academic-terms")
              ? {
                  data: terms.data.map((term) => ({
                    ...term,
                    status: "semester_closed",
                    status_label: "Semester Closed",
                  })),
                }
              : {},
          ),
        ),
      ),
    )
    renderWithSession(<EnrollmentDashboardWorkspace />, { session: dean })

    expect(
      await screen.findByText(/No enrollment term is open right now/),
    ).toBeInTheDocument()
    expect(screen.queryByRole("alert")).not.toBeInTheDocument()
    expect(
      fetchMock.mock.calls.some(([input]) =>
        url(input).includes("enrollment-status"),
      ),
    ).toBe(false)
  })

  it("never asks for stuck-student data", async () => {
    mockDashboardFetch(fetchMock)
    renderWithSession(<EnrollmentDashboardWorkspace />, {
      session: {
        userId: "8",
        displayName: "Executive Director",
        role: "executive_director",
        signedInAt: "2026-07-31T00:00:00Z",
      },
    })

    await screen.findByRole("button", { name: /Enrolled: 11 students/ })
    expect(
      fetchMock.mock.calls.some(([input]) =>
        url(input).includes("stuck-enrollments"),
      ),
    ).toBe(false)
  })

  it.each([
    ["registrar_staff", /waiting for the Registrar's approval/],
    ["accounting_staff", /waiting at the payment stage/],
    ["admission_staff", /still in the admission process/],
  ] as const)(
    "%s sees the dashboard for their own stage without the institution-wide summary",
    async (role, note) => {
      mockDashboardFetch(fetchMock)
      renderWithSession(<EnrollmentDashboardWorkspace />, {
        session: {
          userId: "9",
          displayName: "Staff",
          role,
          signedInAt: "2026-07-31T00:00:00Z",
        },
      })

      await screen.findByRole("button", { name: /Enrolled: 11 students/ })
      expect(screen.getByText(note)).toBeInTheDocument()
      // Section fill and grade submission belong to the roles that see every stage.
      expect(screen.queryByText(/sections published/)).not.toBeInTheDocument()
      expect(
        fetchMock.mock.calls.some(([input]) =>
          url(input).includes("enrollment-summary"),
        ),
      ).toBe(false)
    },
  )
  it("has no detectable accessibility violations once loaded", async () => {
    mockDashboardFetch(fetchMock)
    const { container } = renderWithSession(<EnrollmentDashboardWorkspace />, {
      session: dean,
    })
    await screen.findByRole("button", { name: /Enrolled: 11 students/ })
    expect(await axe(container)).toHaveNoViolations()
  })

  it("drills from a group to departments, sections, students and one student's enrollment", async () => {
    const user = userEvent.setup()
    mockDashboardFetch(fetchMock)
    renderWithSession(<EnrollmentDashboardWorkspace />, {
      session: registrarHead,
    })

    await user.click(
      await screen.findByRole("button", { name: /Enrolled: 11 students/ }),
    )
    const dialog = await screen.findByRole("dialog", {
      name: "Enrollment status · Enrolled",
    })

    // Departments, narrowed to the chosen group.
    await user.click(
      within(dialog).getByRole("button", {
        name: /College of Computer Studies: 8 Enrolled, of 12 students/,
      }),
    )
    // Sections of that department that have enrolled students.
    await user.click(
      await within(dialog).findByRole("button", { name: /IT201: 8 Enrolled/ }),
    )
    expect(
      within(dialog).queryByRole("button", { name: /No section yet/ }),
    ).not.toBeInTheDocument()
    // Students, asked for with the group and section filters.
    const studentButtons = await within(dialog).findAllByRole("button", {
      name: "Ricardo C. Tagumpay",
    })
    expect(fetchMock).toHaveBeenCalledWith(
      expect.stringMatching(
        /enrollment-status\/students\?.*department=ccs.*section_code=IT201.*group=enrolled/,
      ),
      expect.anything(),
    )
    await user.click(studentButtons[0])

    expect(await within(dialog).findByText("2026-06-01722")).toBeInTheDocument()
    expect(within(dialog).getByText("Enrollment timeline")).toBeInTheDocument()
    expect(
      within(dialog).getByText(/Subjects · 24.5 units/),
    ).toBeInTheDocument()
    // Registrar Head can jump to the full record.
    expect(
      within(dialog).getByRole("link", { name: "Open COR Records" }),
    ).toHaveAttribute("href", "/portal/cor-records")

    // A breadcrumb takes the reader back up a level.
    await user.click(
      within(dialog).getByRole("button", {
        name: "College of Computer Studies",
      }),
    )
    expect(
      await within(dialog).findByRole("button", { name: /IT201: 8 Enrolled/ }),
    ).toBeInTheDocument()
  })

  it("opens a department directly from the per-department view and asks for students with no section", async () => {
    const user = userEvent.setup()
    mockDashboardFetch(fetchMock)
    renderWithSession(<EnrollmentDashboardWorkspace />, { session: dean })

    await user.click(
      await screen.findByRole("button", {
        name: "College of Computer Studies, Not yet done: 3",
      }),
    )
    const dialog = await screen.findByRole("dialog", {
      name: "Enrollment status · Not yet done",
    })
    await user.click(
      await within(dialog).findByRole("button", { name: /No section yet: 3/ }),
    )
    await within(dialog).findAllByRole("button", {
      name: "Ricardo C. Tagumpay",
    })

    expect(fetchMock).toHaveBeenCalledWith(
      expect.stringMatching(
        /enrollment-status\/students\?.*department=ccs.*without_section=1.*group=not_yet_done/,
      ),
      expect.anything(),
    )
    expect(
      within(dialog).queryByRole("link", { name: "Open COR Records" }),
    ).not.toBeInTheDocument()
  })

  it("filters enrollment dashboard and queue by specific department and through View Queue button", async () => {
    const user = userEvent.setup()
    mockDashboardFetch(fetchMock)
    renderWithSession(<EnrollmentDashboardWorkspace />, { session: dean })

    // Verify department filter buttons exist
    const allDeptsBtn = await screen.findByRole("button", {
      name: "All Departments",
    })
    const ccsBtn = screen.getByRole("button", { name: "CCS" })
    const coeBtn = screen.getByRole("button", { name: "COE" })
    expect(allDeptsBtn).toBeInTheDocument()
    expect(ccsBtn).toBeInTheDocument()
    expect(coeBtn).toBeInTheDocument()

    // Initially shows all 20 students
    expect(screen.getAllByText("20").length).toBeGreaterThan(0)

    // Click CCS button to view CCS specific department queue
    await user.click(ccsBtn)
    expect(screen.getAllByText("12").length).toBeGreaterThan(0)
    expect(
      screen.getByText("Showing enrollment status and queue for College of Computer Studies."),
    ).toBeInTheDocument()

    // Click "View Queue →" on the College of Education card
    const viewQueueButtons = screen.getAllByRole("button", {
      name: /View Queue →/i,
    })
    // Second button is for COE
    await user.click(viewQueueButtons[1]!)
    expect(screen.getAllByText("8").length).toBeGreaterThan(0)
    expect(
      screen.getByText("Showing enrollment status and queue for College of Education."),
    ).toBeInTheDocument()

    // Switch back to All Departments
    await user.click(allDeptsBtn)
    expect(screen.getAllByText("20").length).toBeGreaterThan(0)
  })
})
