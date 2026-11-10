import { screen, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import { axe } from "vitest-axe"

import { ClassRostersWorkspace } from "@/features/components/portal/class-rosters-workspace"
import { renderWithSession } from "@/tests/render-app"

function requestUrl(input: RequestInfo | URL): string {
  if (typeof input === "string") return input
  return input instanceof URL ? input.toString() : input.url
}

const facultySession = {
  userId: "5",
  displayName: "Faculty",
  role: "faculty" as const,
  signedInAt: "2026-07-29T12:00:00Z",
}

const ownSection = {
  type: "section",
  id: 44,
  academic_term_id: 1,
  subject_id: 101,
  section_code: "CS101-A",
  professor_id: 5,
  schedule_days: "MWF",
  starts_at_time: "08:00:00",
  ends_at_time: "09:30:00",
  room: "R201",
  capacity: 30,
  capacity_source: "plan",
  viability_threshold: null,
  enrolled_count: 1,
  remaining_seats: 29,
  is_block_exclusive: null,
  status: "published",
  status_label: "Published",
}

function rosterResponse(entries: unknown[]) {
  return {
    data: entries,
    links: {
      first: "http://x/1",
      last: "http://x/1",
      prev: null,
      next: null,
    },
    meta: {
      current_page: 1,
      last_page: 1,
      per_page: 100,
      total: entries.length,
    },
  }
}

const rosterEntry = {
  type: "class_roster_entry",
  id: 1,
  enrollment_id: 10,
  section_id: 44,
  section_code: "CS101-A",
  subject_code: "CS101",
  academic_term_id: 1,
  student_id: 20,
  student_number: "2026-0001",
  student_name: "Juan Dela Cruz",
  status: "enrolled",
  status_label: "Enrolled",
}

describe("ClassRostersWorkspace", () => {
  const fetchMock = vi.fn<typeof fetch>()
  beforeEach(() => vi.stubGlobal("fetch", fetchMock))
  afterEach(() => vi.unstubAllGlobals())

  it("is not available for a non-faculty role", () => {
    renderWithSession(<ClassRostersWorkspace />, {
      session: { ...facultySession, role: "student" },
    })

    expect(
      screen.getByText("This workspace is not available for your role."),
    ).toBeInTheDocument()
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it("lists only the signed-in faculty member's own sections", async () => {
    fetchMock.mockResolvedValue(
      new Response(
        JSON.stringify({
          data: [ownSection, { ...ownSection, id: 45, professor_id: 6 }],
        }),
      ),
    )
    const user = userEvent.setup()
    renderWithSession(<ClassRostersWorkspace />, { session: facultySession })

    const trigger = await screen.findByLabelText("Section")
    await user.click(trigger)
    expect(screen.getByRole("option", { name: /CS101-A/ })).toBeInTheDocument()
    expect(screen.getAllByRole("option")).toHaveLength(1)
  })

  it("shows a clear empty state when no sections are assigned", async () => {
    fetchMock.mockResolvedValue(new Response(JSON.stringify({ data: [] })))
    renderWithSession(<ClassRostersWorkspace />, { session: facultySession })

    expect(
      await screen.findByText(
        "No sections are currently assigned to your faculty account.",
      ),
    ).toBeInTheDocument()
  })

  it("loads and displays the roster once a section is selected", async () => {
    fetchMock.mockImplementation((input) => {
      const url = requestUrl(input)
      if (url.includes("/class-rosters")) {
        return Promise.resolve(
          new Response(JSON.stringify(rosterResponse([rosterEntry]))),
        )
      }
      return Promise.resolve(
        new Response(JSON.stringify({ data: [ownSection] })),
      )
    })
    const user = userEvent.setup()
    renderWithSession(<ClassRostersWorkspace />, { session: facultySession })

    const trigger = await screen.findByLabelText("Section")
    await user.click(trigger)
    await user.click(screen.getByRole("option", { name: /CS101-A/ }))

    const table = await screen.findByRole("table", { name: "Class roster" })
    expect(within(table).getByText("2026-0001")).toBeInTheDocument()
    expect(within(table).getByText("Juan Dela Cruz")).toBeInTheDocument()
    expect(within(table).getByText("Enrolled")).toBeInTheDocument()
  })

  it("shows an empty roster message when the section has no enrolled students", async () => {
    fetchMock.mockImplementation((input) => {
      const url = requestUrl(input)
      if (url.includes("/class-rosters")) {
        return Promise.resolve(new Response(JSON.stringify(rosterResponse([]))))
      }
      return Promise.resolve(
        new Response(JSON.stringify({ data: [ownSection] })),
      )
    })
    const user = userEvent.setup()
    renderWithSession(<ClassRostersWorkspace />, { session: facultySession })

    const trigger = await screen.findByLabelText("Section")
    await user.click(trigger)
    await user.click(screen.getByRole("option", { name: /CS101-A/ }))

    expect(
      await screen.findByText("No students are enrolled in this section yet."),
    ).toBeInTheDocument()
  })

  it("allows switching between class roster and grade sheet tabs", async () => {
    const sectionGradeSheet = {
      type: "section_grade_sheet",
      section: {
        type: "grade_section_summary",
        section_id: 44,
        section_code: "CS101-A",
        subject: {
          id: 101,
          code: "CS101",
          title: "Programming 1",
          is_completion_only: false,
        },
        academic_term: {
          id: 1,
          school_year: "2026-2027",
          semester: "1st",
        },
        schedule: {
          days: "MWF",
          starts_at_time: "08:00:00",
          ends_at_time: "09:30:00",
        },
        enrolled_count: 1,
        recorded_count: 0,
        submitted_count: 0,
        locked_count: 0,
        missing_count: 1,
        state: "not_started",
      },
      rows: [
        {
          enrollment_subject_id: 501,
          student_id: 20,
          student_number: "2026-0001",
          student_name: "Juan Dela Cruz",
          grade_id: null,
          mark: null,
          mark_label: null,
          remarks: null,
          status: "not_recorded",
          status_label: "Not recorded",
        },
      ],
    }

    fetchMock.mockImplementation((input) => {
      const url = requestUrl(input)
      if (url.includes("/grades")) {
        return Promise.resolve(
          new Response(JSON.stringify({ data: sectionGradeSheet })),
        )
      }
      if (url.includes("/class-rosters")) {
        return Promise.resolve(
          new Response(JSON.stringify(rosterResponse([rosterEntry]))),
        )
      }
      return Promise.resolve(
        new Response(JSON.stringify({ data: [ownSection] })),
      )
    })
    const user = userEvent.setup()
    renderWithSession(<ClassRostersWorkspace />, { session: facultySession })

    const trigger = await screen.findByLabelText("Section")
    await user.click(trigger)
    await user.click(screen.getByRole("option", { name: /CS101-A/ }))

    expect(await screen.findByRole("table", { name: "Class roster" })).toBeInTheDocument()

    const gradeTab = screen.getByRole("button", { name: /Grade Sheet & Submission/i })
    await user.click(gradeTab)

    expect(
      await screen.findByRole("table", { name: "Section grade sheet" }),
    ).toBeInTheDocument()
    expect(screen.getByText("Programming 1")).toBeInTheDocument()
  })

  it("has no detectable accessibility violations once loaded", async () => {
    fetchMock.mockResolvedValue(
      new Response(JSON.stringify({ data: [ownSection] })),
    )
    const { container } = renderWithSession(<ClassRostersWorkspace />, {
      session: facultySession,
    })

    await screen.findByLabelText("Section")
    expect(await axe(container)).toHaveNoViolations()
  })
})
