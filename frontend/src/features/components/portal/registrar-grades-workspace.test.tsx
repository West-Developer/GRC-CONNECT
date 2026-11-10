import { screen, waitFor, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import { axe } from "vitest-axe"

import { RegistrarGradesWorkspace } from "@/features/components/portal/registrar-grades-workspace"
import { renderWithSession } from "@/tests/render-app"

function url(input: RequestInfo | URL) {
  if (typeof input === "string") return input
  return input instanceof URL ? input.toString() : input.url
}

const paginationLinks = {
  first: "https://api.test/academic-grades?page=1",
  last: "https://api.test/academic-grades?page=1",
  prev: null,
  next: null,
}
const paginationMeta = {
  current_page: 1,
  last_page: 1,
  per_page: 20,
  total: 1,
}

const submittedGrade = {
  type: "academic_grade",
  id: 3,
  student_id: 4,
  student_number: "2026-0001",
  subject_id: 7,
  subject_code: "CS101",
  professor_id: 20,
  professor_name: "Prof. Reyes",
  section_id: 5,
  academic_term_id: 2,
  mark: "1.50",
  mark_label: "with Distinction",
  final_grade: "1.50",
  remarks: null,
  status: "submitted",
  status_label: "Submitted",
  submitted_at: "2026-07-30T00:00:00Z",
  locked_at: null,
} as const

const approvalProfessor = {
  professor_id: 20,
  professor_name: "Prof. Reyes",
  college: "ccs",
  subject_count: 1,
  grade_count: 1,
} as const

const approvalSection = {
  section_id: 5,
  subject_id: 7,
  subject_code: "CS101",
  subject_title: null,
  section_code: null,
  grade_count: 1,
} as const

function professorsPage(
  rows: readonly { grade_count: number }[],
  meta: Partial<{ current_page: number; last_page: number }> = {},
) {
  return {
    data: rows,
    meta: {
      current_page: 1,
      last_page: 1,
      per_page: 12,
      total: rows.length,
      total_grades: rows.reduce((total, row) => total + row.grade_count, 0),
      ...meta,
    },
  }
}

/**
 * The approvals read three things: professors (one row each, paged), one
 * professor's sections, and one section's grades. Returns the response for the
 * first two (the grade list is mocked by each test), or null for anything else.
 */
function approvalsResponse(
  target: string,
  professors: readonly { grade_count: number }[] = [approvalProfessor],
  sections: readonly unknown[] = [approvalSection],
): Response | null {
  if (target.includes("/academic-grades/approval-professors"))
    return new Response(JSON.stringify(professorsPage(professors)))
  if (target.includes("/academic-grades/approval-sections"))
    return new Response(JSON.stringify({ data: sections }))
  return null
}

const registrarHeadSession = {
  userId: "9",
  displayName: "Registrar Head",
  role: "registrar_head",
  signedInAt: "2026-07-29T12:00:00Z",
} as const

const academicRecord = {
  type: "academic_record",
  student_id: 4,
  student_number: "2026-0001",
  program_code: "BSIT",
  program_name: "BS Information Technology",
  year_level: 2,
  enrollment_category: "regular",
  enrollment_category_label: "Regular",
  terms: [
    {
      academic_term_id: 2,
      school_year: "2026-2027",
      semester: "2nd",
      term_label: "2026-2027 · 2nd",
      rows: [],
      total_academic_units: 0,
      gpa_units: 0,
      gpa: null,
      excluded_from_gpa_count: 0,
    },
  ],
} as const

const prospectus = {
  type: "prospectus",
  student_id: 4,
  student_number: "2026-0001",
  program_code: "BSIT",
  program_name: "BS Information Technology",
  curriculum_id: 1,
  curriculum_name: "BSIT 2023 Curriculum",
  effective_school_year: "2023-2024",
  year_level: 2,
  enrollment_category: "regular",
  enrollment_category_label: "Regular",
  enrollment_category_derived_at: "2026-07-30T00:00:00Z",
  semesters: [],
  unplaced_entries: [],
} as const

describe("RegistrarGradesWorkspace", () => {
  const fetchMock = vi.fn<typeof fetch>()
  beforeEach(() => vi.stubGlobal("fetch", fetchMock))
  afterEach(() => vi.unstubAllGlobals())

  it("does not render for an unauthorized role", () => {
    fetchMock.mockResolvedValue(new Response(JSON.stringify({ data: [] })))
    renderWithSession(
      <RegistrarGradesWorkspace initialModuleId="grade-approvals" />,
      {
        session: {
          userId: "1",
          displayName: "Registrar Staff",
          role: "registrar_staff",
          signedInAt: "2026-07-29T12:00:00Z",
        },
      },
    )
    expect(
      screen.getByText("This workspace is not available for your role."),
    ).toBeInTheDocument()
  })

  it("lists submitted grades and locks one after confirmation", async () => {
    const user = userEvent.setup()
    let lockRequestBody: unknown = null
    fetchMock.mockImplementation((input, init) => {
      const target = url(input)
      if (target.includes("/academic-grades/3") && init?.method === "PATCH") {
        lockRequestBody = init.body ? JSON.parse(init.body as string) : null
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: {
                ...submittedGrade,
                status: "locked",
                locked_at: "2026-07-31T00:00:00Z",
              },
            }),
          ),
        )
      }
      const approvals = approvalsResponse(target)
      if (approvals) return Promise.resolve(approvals)
      if (target.includes("/academic-grades"))
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: [submittedGrade],
              links: paginationLinks,
              meta: paginationMeta,
            }),
          ),
        )
      return Promise.resolve(new Response(JSON.stringify({ data: [] })))
    })

    renderWithSession(
      <RegistrarGradesWorkspace initialModuleId="grade-approvals" />,
      { session: registrarHeadSession },
    )

    await user.click(
      await screen.findByRole("button", { name: /Prof\. Reyes/ }),
    )
    await user.click(await screen.findByRole("button", { name: /CS101/ }))
    const table = await screen.findByRole("table", {
      name: "Submitted grades awaiting lock",
    })
    expect(within(table).getByText("2026-0001")).toBeInTheDocument()

    await user.click(within(table).getByRole("button", { name: "Lock" }))

    const dialog = await screen.findByRole("alertdialog")
    expect(within(dialog).getByText(/permanent/i)).toBeInTheDocument()
    await user.click(within(dialog).getByRole("button", { name: "Lock grade" }))

    await waitFor(() => expect(lockRequestBody).toEqual({ action: "lock" }))
  })

  it("locks all submitted grades for the semester after confirmation", async () => {
    const user = userEvent.setup()
    let lockAllRequestBody: unknown = null
    fetchMock.mockImplementation((input, init) => {
      const target = url(input)
      if (
        target.includes("/academic-grades/lock-all") &&
        init?.method === "POST"
      ) {
        lockAllRequestBody = init.body ? JSON.parse(init.body as string) : null
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: {
                locked_count: 1,
                message: "Successfully locked 1 grades.",
              },
            }),
          ),
        )
      }
      if (target.includes("/academic-terms")) {
        return Promise.resolve(
          new Response(
            JSON.stringify({
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
                  status_label: "Ongoing",
                },
              ],
            }),
          ),
        )
      }
      const approvals = approvalsResponse(target)
      if (approvals) return Promise.resolve(approvals)
      if (target.includes("/academic-grades"))
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: [submittedGrade],
              links: paginationLinks,
              meta: paginationMeta,
            }),
          ),
        )
      return Promise.resolve(new Response(JSON.stringify({ data: [] })))
    })

    renderWithSession(
      <RegistrarGradesWorkspace initialModuleId="grade-approvals" />,
      { session: registrarHeadSession },
    )

    const lockAllBtn = await screen.findByRole("button", {
      name: /lock all grades for this semester/i,
    })
    expect(lockAllBtn).toBeInTheDocument()

    await user.click(lockAllBtn)

    const dialog = await screen.findByRole("alertdialog")
    expect(
      within(dialog).getByRole("heading", {
        name: "Lock all submitted grades for this semester?",
      }),
    ).toBeInTheDocument()
    expect(within(dialog).getByText(/permanent/i)).toBeInTheDocument()

    await user.click(
      within(dialog).getByRole("button", { name: "Lock all grades" }),
    )

    await waitFor(() => {
      expect(lockAllRequestBody).toBeDefined()
    })
  })

  it("looks up a student by student number and renders their transcript", async () => {
    const user = userEvent.setup()
    const studentCandidate = {
      type: "academic_record_student",
      id: 1508,
      student_id: 1508,
      student_number: "2024-06-01298",
      name: "Bonifacio B. Pangilinan",
      first_name: "Bonifacio",
      last_name: "Pangilinan",
      email: "bonifacio.pangilinan@grc.com",
      program_code: "BSIT",
      program_name: "BS Information Technology",
      year_level: 3,
      enrollment_category: "regular",
      enrollment_category_label: "Regular",
      academic_standing: "good",
      academic_standing_label: "Good Standing",
    }

    fetchMock.mockImplementation((input) => {
      const target = url(input)
      if (target.includes("/academic-record/students"))
        return Promise.resolve(
          new Response(JSON.stringify({ data: [studentCandidate] })),
        )
      if (target.includes("/academic-record"))
        return Promise.resolve(
          new Response(JSON.stringify({ data: academicRecord })),
        )
      if (target.includes("/prospectus"))
        return Promise.resolve(
          new Response(JSON.stringify({ data: prospectus })),
        )
      return Promise.resolve(new Response(JSON.stringify({ data: [] })))
    })

    renderWithSession(
      <RegistrarGradesWorkspace initialModuleId="academic-transcripts" />,
      { session: registrarHeadSession },
    )

    await user.type(screen.getByLabelText("Student ID"), "2024-06-01298")
    await user.click(screen.getByRole("button", { name: "View records" }))

    expect(
      await screen.findByText("Bonifacio B. Pangilinan"),
    ).toBeInTheDocument()
    expect(
      await screen.findByText("Search another student"),
    ).toBeInTheDocument()
    expect(
      url(
        fetchMock.mock.calls.find((call) =>
          url(call[0]).includes("/academic-record?"),
        )![0],
      ),
    ).toContain("student_id=1508")
  })

  it("searches students by name and renders transcript upon selection", async () => {
    const user = userEvent.setup()
    const studentCandidate = {
      type: "academic_record_student",
      id: 1508,
      student_id: 1508,
      student_number: "2024-06-01298",
      name: "Bonifacio B. Pangilinan",
      first_name: "Bonifacio",
      last_name: "Pangilinan",
      email: "bonifacio.pangilinan@grc.com",
      program_code: "BSIT",
      program_name: "BS Information Technology",
      year_level: 3,
      enrollment_category: "regular",
      enrollment_category_label: "Regular",
      academic_standing: "good",
      academic_standing_label: "Good Standing",
    }

    fetchMock.mockImplementation((input) => {
      const target = url(input)
      if (target.includes("/academic-record/students"))
        return Promise.resolve(
          new Response(JSON.stringify({ data: [studentCandidate] })),
        )
      if (target.includes("/academic-record"))
        return Promise.resolve(
          new Response(JSON.stringify({ data: academicRecord })),
        )
      if (target.includes("/prospectus"))
        return Promise.resolve(
          new Response(JSON.stringify({ data: prospectus })),
        )
      return Promise.resolve(new Response(JSON.stringify({ data: [] })))
    })

    renderWithSession(
      <RegistrarGradesWorkspace initialModuleId="academic-transcripts" />,
      { session: registrarHeadSession },
    )

    // Switch to By Student Name tab
    await user.click(screen.getByRole("tab", { name: "By Student Name" }))

    await user.type(screen.getByLabelText("Student Name"), "Bonifacio")
    await user.click(screen.getByRole("button", { name: "Search" }))

    expect(await screen.findByText("Matching Students (1)")).toBeInTheDocument()
    expect(screen.getByText("Bonifacio B. Pangilinan")).toBeInTheDocument()

    // Click view transcript
    await user.click(screen.getByRole("button", { name: "View transcript" }))

    expect(
      await screen.findByText("Search another student"),
    ).toBeInTheDocument()
    expect(
      url(
        fetchMock.mock.calls.find((call) =>
          url(call[0]).includes("/academic-record?"),
        )![0],
      ),
    ).toContain("student_id=1508")
  })

  it("rejects a non-numeric student ID without querying the API", async () => {
    const user = userEvent.setup()
    fetchMock.mockResolvedValue(new Response(JSON.stringify({ data: [] })))

    renderWithSession(
      <RegistrarGradesWorkspace initialModuleId="academic-transcripts" />,
      { session: registrarHeadSession },
    )

    await user.type(screen.getByLabelText("Student ID"), "abc")
    await user.click(screen.getByRole("button", { name: "View records" }))

    expect(
      await screen.findByText("Enter a valid student ID."),
    ).toBeInTheDocument()
  })

  it("drills Professor -> Subjects -> Students -> a student's grades, with Department as a filter", async () => {
    const user = userEvent.setup()
    const coeGrade = {
      ...submittedGrade,
      id: 10,
      student_id: 40,
      student_number: "2026-0100",
      student_name: "Mercedes C. Ramos",
      subject_code: "ACC101",
      subject_title: "Accounting 1",
      section_code: "ACC101-A",
      professor_id: 425,
      professor_name: "Henry Nieva Corrales",
      college: "coe",
    }
    const ccsGrade = {
      ...submittedGrade,
      id: 11,
      student_id: 41,
      student_number: "2026-0200",
      student_name: "Alan Turing",
      subject_code: "CS201",
      subject_title: "Object-Oriented Programming",
      section_code: "CS201-A",
      professor_id: 430,
      professor_name: "Maria Delos Santos",
      college: "ccs",
    }

    const coeProfessor = {
      professor_id: 425,
      professor_name: "Henry Nieva Corrales",
      college: "coe",
      subject_count: 1,
      grade_count: 1,
    }
    const ccsProfessor = {
      professor_id: 430,
      professor_name: "Maria Delos Santos",
      college: "ccs",
      subject_count: 1,
      grade_count: 1,
    }
    const coeSection = {
      section_id: 50,
      subject_id: 7,
      subject_code: "ACC101",
      subject_title: "Accounting 1",
      section_code: "ACC101-A",
      grade_count: 1,
    }
    const ccsSection = {
      section_id: 51,
      subject_id: 8,
      subject_code: "CS201",
      subject_title: "Object-Oriented Programming",
      section_code: "CS201-A",
      grade_count: 1,
    }

    fetchMock.mockImplementation((input) => {
      const target = url(input)
      if (target.includes("/academic-grades/approval-professors")) {
        // The department filter is applied by the server.
        return Promise.resolve(
          new Response(
            JSON.stringify(
              professorsPage(
                target.includes("college=ccs")
                  ? [ccsProfessor]
                  : [coeProfessor, ccsProfessor],
              ),
            ),
          ),
        )
      }
      if (target.includes("/academic-grades/approval-sections")) {
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: target.includes("professor_id=425")
                ? [coeSection]
                : [ccsSection],
            }),
          ),
        )
      }
      if (target.includes("/academic-grades")) {
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: target.includes("section_id=50") ? [coeGrade] : [ccsGrade],
              links: paginationLinks,
              meta: { ...paginationMeta, total: 1 },
            }),
          ),
        )
      }
      if (target.includes("/academic-record")) {
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: {
                ...academicRecord,
                student_id: 40,
                student_number: "2026-0100",
              },
            }),
          ),
        )
      }
      return Promise.resolve(new Response(JSON.stringify({ data: [] })))
    })

    renderWithSession(
      <RegistrarGradesWorkspace initialModuleId="grade-approvals" />,
      { session: registrarHeadSession },
    )

    // Level 1: professors only. No subject or student is listed yet.
    expect(
      await screen.findByRole("button", { name: /Henry Nieva Corrales/ }),
    ).toBeInTheDocument()
    expect(
      screen.getByRole("button", { name: /Maria Delos Santos/ }),
    ).toBeInTheDocument()
    expect(screen.queryByText(/ACC101 — Accounting 1/)).not.toBeInTheDocument()
    expect(screen.queryByText("Mercedes C. Ramos")).not.toBeInTheDocument()

    // Department is a filter: CCS leaves only its professor.
    const filterGroup = screen.getByRole("group", {
      name: "Filter by department",
    })
    await user.click(within(filterGroup).getByRole("button", { name: "CCS" }))
    expect(
      await screen.findByRole("button", { name: /Maria Delos Santos/ }),
    ).toBeInTheDocument()
    expect(
      screen.queryByRole("button", { name: /Henry Nieva Corrales/ }),
    ).not.toBeInTheDocument()
    await user.click(
      within(filterGroup).getByRole("button", { name: "All Departments" }),
    )

    // Level 2: this professor's subjects.
    await user.click(
      await screen.findByRole("button", { name: /Henry Nieva Corrales/ }),
    )
    expect(
      await screen.findByRole("button", { name: /ACC101 — Accounting 1/ }),
    ).toBeInTheDocument()
    expect(screen.queryByText(/CS201/)).not.toBeInTheDocument()

    // Level 3: the students of that subject.
    await user.click(
      screen.getByRole("button", { name: /ACC101 — Accounting 1/ }),
    )
    const table = await screen.findByRole("table", {
      name: "Submitted grades awaiting lock",
    })
    expect(within(table).getByText("Mercedes C. Ramos")).toBeInTheDocument()

    // Level 4: one student's grades.
    await user.click(
      within(table).getByRole("button", { name: /Mercedes C\. Ramos/ }),
    )
    expect(
      await screen.findByRole("button", { name: /Back to students/ }),
    ).toBeInTheDocument()
    await waitFor(() =>
      expect(
        fetchMock.mock.calls.some(([input]) =>
          url(input).includes("/academic-record"),
        ),
      ).toBe(true),
    )

    // Back out one level at a time.
    await user.click(screen.getByRole("button", { name: /Back to students/ }))
    await user.click(
      await screen.findByRole("button", {
        name: /Back to Henry Nieva Corrales's subjects/,
      }),
    )
    await user.click(
      await screen.findByRole("button", { name: /Back to professors/ }),
    )
    expect(
      await screen.findByRole("button", { name: /Maria Delos Santos/ }),
    ).toBeInTheDocument()
  })
  it("pages over professors, each shown once with their real grade total", async () => {
    const user = userEvent.setup()
    const alpha = { ...approvalProfessor, professor_id: 31, professor_name: "Prof. Alpha", subject_count: 7, grade_count: 175 }
    const beta = { ...approvalProfessor, professor_id: 32, professor_name: "Prof. Beta", subject_count: 1, grade_count: 24 }
    fetchMock.mockImplementation((input) => {
      const target = url(input)
      if (target.includes("/academic-grades/approval-professors"))
        return Promise.resolve(
          new Response(
            JSON.stringify(
              target.includes("page=2")
                ? professorsPage([beta], { current_page: 2, last_page: 2 })
                : professorsPage([alpha], { current_page: 1, last_page: 2 }),
            ),
          ),
        )
      return Promise.resolve(new Response(JSON.stringify({ data: [] })))
    })

    renderWithSession(
      <RegistrarGradesWorkspace initialModuleId="grade-approvals" />,
      { session: registrarHeadSession },
    )

    // The whole 175, not the part of it that happened to fall on one page of grades.
    expect(
      await screen.findByRole("button", { name: /Prof\. Alpha.*175 grade\(s\) awaiting lock/ }),
    ).toBeInTheDocument()
    expect(screen.getByText("Page 1 of 2")).toBeInTheDocument()

    await user.click(screen.getByRole("button", { name: "Next page" }))

    expect(
      await screen.findByRole("button", { name: /Prof\. Beta.*24 grade\(s\) awaiting lock/ }),
    ).toBeInTheDocument()
    expect(screen.queryByRole("button", { name: /Prof\. Alpha/ })).not.toBeInTheDocument()
    expect(screen.getByText("Page 2 of 2")).toBeInTheDocument()
    expect(
      fetchMock.mock.calls.some(([input]) =>
        url(input).includes("/academic-grades/approval-professors?") && url(input).includes("page=2"),
      ),
    ).toBe(true)
  })

  it("switches to Grade History tab and searches historical locked records", async () => {
    const user = userEvent.setup()
    const historicalGrade = {
      ...submittedGrade,
      id: 99,
      student_number: "2018-0055",
      student_name: "Historical Student",
      subject_code: "MATH101",
      subject_title: "College Algebra",
      section_code: "MATH101-A",
      professor_id: 425,
      professor_name: "Henry Nieva Corrales",
      college: "cbae",
      school_year: "2018-2019",
      semester: "1st",
      status: "locked",
      status_label: "Official / Locked",
    }

    fetchMock.mockImplementation((input) => {
      const target = url(input)
      if (target.includes("/academic-grades")) {
        if (target.includes("status=locked")) {
          return Promise.resolve(
            new Response(
              JSON.stringify({
                data: [historicalGrade],
                links: paginationLinks,
                meta: { ...paginationMeta, total: 1 },
              }),
            ),
          )
        }
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: [submittedGrade],
              links: paginationLinks,
              meta: paginationMeta,
            }),
          ),
        )
      }
      return Promise.resolve(new Response(JSON.stringify({ data: [] })))
    })

    renderWithSession(
      <RegistrarGradesWorkspace initialModuleId="grade-approvals" />,
      { session: registrarHeadSession },
    )

    // Switch to Grade history tab
    const viewGroup = screen.getByRole("group", { name: "Approvals view" })
    await user.click(
      within(viewGroup).getByRole("button", { name: /Grade history/i }),
    )

    expect(
      await screen.findByRole("heading", { name: "Official Grade History" }),
    ).toBeInTheDocument()

    const historyTable = screen.getByRole("table", {
      name: "Official Grade History",
    })
    expect(
      within(historyTable).getByText("Historical Student"),
    ).toBeInTheDocument()
    expect(
      within(historyTable).getByText("2018-2019 · 1st"),
    ).toBeInTheDocument()
    expect(
      within(historyTable).getByText("College Algebra"),
    ).toBeInTheDocument()

    // Test search
    const searchInput = screen.getByLabelText("Search grade history")
    await user.type(searchInput, "2018-0055")
    await user.click(screen.getByRole("button", { name: "Search" }))

    expect(
      url(
        fetchMock.mock.calls.find((call) =>
          url(call[0]).includes("search=2018-0055"),
        )![0],
      ),
    ).toContain("status=locked")
  })

  it("has no detectable accessibility violations on the grade-approvals list", async () => {
    fetchMock.mockImplementation((input) => {
      const target = url(input)
      const approvals = approvalsResponse(target)
      if (approvals) return Promise.resolve(approvals)
      if (target.includes("/academic-grades"))
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: [submittedGrade],
              links: paginationLinks,
              meta: paginationMeta,
            }),
          ),
        )
      return Promise.resolve(new Response(JSON.stringify({ data: [] })))
    })

    const { container } = renderWithSession(
      <RegistrarGradesWorkspace initialModuleId="grade-approvals" />,
      { session: registrarHeadSession },
    )

    await screen.findByRole("button", { name: /Prof\. Reyes/ })
    expect(await axe(container)).toHaveNoViolations()
  })
})
