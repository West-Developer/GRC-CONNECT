import { screen, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import { StudentScheduleWorkspace } from "@/features/components/portal/student-schedule-workspace"
import { renderWithSession } from "@/tests/render-app"

const studentSession = {
  userId: "101",
  displayName: "Juan Dela Cruz",
  role: "student" as const,
  signedInAt: "2026-09-07T12:00:00Z",
}

const termsFixture = {
  data: [
    {
      type: "academic-term",
      id: 1,
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

const enrolledSubjectsFixture = [
  {
    section_id: 11,
    section_code: "IT101",
    subject_id: 501,
    subject_code: "CS101",
    paired_subject_id: null as number | null,
    subject_title: "Introduction to Computing",
    units: 3,
    schedule_days: "MWF",
    starts_at_time: "08:00:00",
    ends_at_time: "09:00:00",
    room: "LAB-1",
    modality: "f2f" as const,
    professor_name: "Dr. Cruz",
    status: "enrolled" as const,
    status_label: "Enrolled",
  },
  {
    section_id: 12,
    section_code: "IT101",
    subject_id: 502,
    subject_code: "GE101",
    paired_subject_id: null,
    subject_title: "Understanding the Self",
    units: 3,
    schedule_days: "TTh",
    starts_at_time: "10:00:00",
    ends_at_time: "11:30:00",
    room: "R201",
    modality: "f2f" as const,
    professor_name: "Prof. Reyes",
    status: "enrolled" as const,
    status_label: "Enrolled",
  },
]

const lecLabSubjectsFixture = [
  {
    section_id: 21,
    section_code: "IT201",
    subject_id: 601,
    subject_code: "ITP1 LEC",
    paired_subject_id: 602,
    subject_title: "Platform Technologies Lecture",
    units: 2,
    schedule_days: "M",
    starts_at_time: "08:00:00",
    ends_at_time: "09:00:00",
    room: "R301",
    modality: "f2f" as const,
    professor_name: "Dr. Cruz",
    status: "enrolled" as const,
    status_label: "Enrolled",
  },
  {
    section_id: 22,
    section_code: "IT101",
    subject_id: 502,
    subject_code: "GE101",
    paired_subject_id: null,
    subject_title: "Understanding the Self",
    units: 3,
    schedule_days: "TTh",
    starts_at_time: "10:00:00",
    ends_at_time: "11:30:00",
    room: "R201",
    modality: "f2f" as const,
    professor_name: "Prof. Reyes",
    status: "enrolled" as const,
    status_label: "Enrolled",
  },
  {
    section_id: 23,
    section_code: "IT201",
    subject_id: 602,
    subject_code: "ITP1 LAB",
    paired_subject_id: 601,
    subject_title: "Platform Technologies Laboratory",
    units: 1,
    schedule_days: "F",
    starts_at_time: "13:00:00",
    ends_at_time: "15:00:00",
    room: "LAB-2",
    modality: "f2f" as const,
    professor_name: "Dr. Cruz",
    status: "enrolled" as const,
    status_label: "Enrolled",
  },
]

const enrollmentFixture = {
  data: [
    {
      type: "enrollment",
      id: 42,
      student_id: 101,
      student_number: "2026-0001",
      student_name: null,
      student_year_level: null,
      student_financial_status: null,
      student_financial_status_label: null,
      academic_term_id: 1,
      status: "enrolled",
      status_label: "Enrolled",
      total_units: 6,
      requires_overload_approval: false,
      program_head_comment: null,
      submitted_at: "2026-09-01T08:00:00Z",
      program_head_decided_at: null,
      registrar_decided_at: "2026-09-02T09:00:00Z",
      payment_confirmed_at: "2026-09-03T10:00:00Z",
      enrolled_at: "2026-09-03T10:05:00Z",
      subjects: enrolledSubjectsFixture,
      queue_ticket: null,
      assessment: null,
    },
  ],
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

function mockFetchRoutes(
  customEnrollments = enrollmentFixture.data,
  customTerms: readonly unknown[] = termsFixture.data,
) {
  vi.stubGlobal(
    "fetch",
    vi.fn<typeof fetch>((input) => {
      const url = typeof input === "string" ? input : "url" in input ? input.url : String(input)
      if (url.includes("/api/v1/academic-terms")) {
        return Promise.resolve(new Response(JSON.stringify({ data: customTerms })))
      }
      if (url.includes("/api/v1/enrollments")) {
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: customEnrollments,
              links: paginationLinks,
              meta: { ...paginationMeta, total: customEnrollments.length },
            }),
          ),
        )
      }
      if (url.includes("/api/v1/student-profiles/me/account")) {
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: {
                student_number: "2026-0001",
                year_level: 1,
                assessment_amount: "15000.00",
                remaining_balance: "0.00",
                financial_status: "cleared",
                financial_status_label: "Cleared",
              },
            }),
          ),
        )
      }
      return Promise.resolve(new Response(JSON.stringify({ data: [] })))
    }),
  )
}

describe("StudentScheduleWorkspace", () => {
  beforeEach(() => {
    mockFetchRoutes()
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it("renders student schedule workspace with title and description", async () => {
    renderWithSession(<StudentScheduleWorkspace />, { session: studentSession })

    expect(
      await screen.findByRole("heading", { name: "Schedule", level: 1 }),
    ).toBeInTheDocument()
    expect(
      screen.getByText(
        "View your weekly class timetable, professor assignments, and room allocations.",
      ),
    ).toBeInTheDocument()
  })

  it("renders empty state with link to enrollment when student has no active enrollment", async () => {
    mockFetchRoutes([])
    renderWithSession(<StudentScheduleWorkspace />, { session: studentSession })

    expect(
      await screen.findByText("No class schedule for this term"),
    ).toBeInTheDocument()
    const enrollmentLink = screen.getByRole("link", { name: /Go to Enrollment/i })
    expect(enrollmentLink).toBeInTheDocument()
    expect(enrollmentLink).toHaveAttribute("href", "/portal/enrollment")
  })

  it("renders enrolled class schedule with professor names and supports dual view toggling", async () => {
    const user = userEvent.setup()
    renderWithSession(<StudentScheduleWorkspace />, { session: studentSession })

    // Overview cards
    expect(await screen.findByText("6 units")).toBeInTheDocument()
    expect(screen.getByText("2 enrolled classes")).toBeInTheDocument()
    expect(screen.getByText("IT101")).toBeInTheDocument()

    // Default is Calendar view (multi-day slots like MWF appear on multiple day columns)
    expect(screen.getByRole("region", { name: "Weekly class schedule" })).toBeInTheDocument()
    expect(screen.getAllByText("CS101").length).toBeGreaterThanOrEqual(1)
    expect(screen.getAllByText("Dr. Cruz").length).toBeGreaterThanOrEqual(1)
    expect(screen.getAllByText("Prof. Reyes").length).toBeGreaterThanOrEqual(1)

    // Switch to Table view
    const tableViewBtn = screen.getByRole("radio", { name: "Schedule list" })
    await user.click(tableViewBtn)

    // Table headers and data
    const table = await screen.findByRole("table", { name: "Student weekly class schedule" })
    expect(table).toBeInTheDocument()
    expect(within(table).getByText("Introduction to Computing")).toBeInTheDocument()
    expect(within(table).getByText("Understanding the Self")).toBeInTheDocument()
    expect(within(table).getByText("LAB-1")).toBeInTheDocument()
    expect(within(table).getByText("R201")).toBeInTheDocument()
  })

  describe("school year and semester picker", () => {
    const term = (id: number, schoolYear: string, semester: string, status: string) => ({
      ...termsFixture.data[0],
      id,
      school_year: schoolYear,
      semester,
      status,
      status_label: status,
    })
    const allTerms = [
      term(1, "2026-2027", "1st", "semester_ongoing"),
      term(2, "2025-2026", "2nd", "archived"),
      term(3, "2024-2025", "1st", "archived"),
      term(4, "2023-2024", "2nd", "archived"),
    ]

    it("lists only the terms the student has data for, plus the current term", async () => {
      // Owner, 2026-10-13: the dropdown listed every school year back to 2017; the student only has
      // an enrollment in 2025-2026 · 2nd (and a withdrawn one with no subjects in 2023-2024 · 2nd).
      mockFetchRoutes(
        [
          { ...enrollmentFixture.data[0], id: 43, academic_term_id: 2 },
          { ...enrollmentFixture.data[0], id: 44, academic_term_id: 4, status: "withdrawn", subjects: [] },
        ],
        allTerms,
      )
      renderWithSession(<StudentScheduleWorkspace />, { session: studentSession })

      const picker = await screen.findByRole("combobox")
      await screen.findByRole("option", { name: /2025-2026 · 2nd/ })
      const labels = within(picker)
        .getAllByRole("option")
        .map((option) => option.textContent)
      expect(labels).toEqual(["2026-2027 · 1st", "2025-2026 · 2nd (Archived)"])
    })

    it("shows only the current term to a student with no enrollment yet", async () => {
      mockFetchRoutes([], allTerms)
      renderWithSession(<StudentScheduleWorkspace />, { session: studentSession })

      await screen.findByText("No class schedule for this term")
      const labels = within(screen.getByRole("combobox"))
        .getAllByRole("option")
        .map((option) => option.textContent)
      expect(labels).toEqual(["2026-2027 · 1st"])
    })

    it("lets the student switch to a past term they were enrolled in", async () => {
      const user = userEvent.setup()
      mockFetchRoutes([{ ...enrollmentFixture.data[0], id: 43, academic_term_id: 2 }], allTerms)
      renderWithSession(<StudentScheduleWorkspace />, { session: studentSession })

      const picker = await screen.findByRole("combobox")
      await screen.findByRole("option", { name: /2025-2026 · 2nd/ })
      await user.selectOptions(picker, screen.getByRole("option", { name: /2025-2026 · 2nd/ }))

      expect(await screen.findByText("6 units")).toBeInTheDocument()
    })
  })

  it("keeps a LEC immediately followed by its paired LAB in the table view, regardless of schedule order", async () => {
    mockFetchRoutes([
      { ...enrollmentFixture.data[0], subjects: lecLabSubjectsFixture },
    ])
    const user = userEvent.setup()
    renderWithSession(<StudentScheduleWorkspace />, { session: studentSession })

    await screen.findByText("6 units")
    await user.click(screen.getByRole("radio", { name: "Schedule list" }))
    const table = await screen.findByRole("table", {
      name: "Student weekly class schedule",
    })

    const rowLabels = within(table)
      .getAllByRole("row")
      .map((row) => row.textContent ?? "")
    const lecIndex = rowLabels.findIndex((text) => text.includes("ITP1 LEC"))
    const labIndex = rowLabels.findIndex((text) => text.includes("ITP1 LAB"))
    const ge101Index = rowLabels.findIndex((text) => text.includes("GE101"))

    expect(lecIndex).toBeGreaterThanOrEqual(0)
    expect(labIndex).toBe(lecIndex + 1)
    expect(ge101Index).toBeGreaterThan(labIndex)
  })
})
