import { screen, waitFor, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import { toast } from "sonner"
import { axe } from "vitest-axe"

import {
  GradeSubmissionWorkspace,
  parseGradesCsv,
  downloadCsvTemplate,
} from "@/features/components/portal/grade-submission-workspace"
import type {
  GradeSectionSummary,
  SectionGradeRow,
  SectionGradeSheet,
} from "@/features/schemas/section-grade-schema"
import { renderWithSession } from "@/tests/render-app"
import { buildXlsx } from "@/tests/xlsx-fixture"

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), warning: vi.fn() },
}))

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

const sectionSummary: GradeSectionSummary = {
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
  enrolled_count: 2,
  recorded_count: 0,
  submitted_count: 0,
  locked_count: 0,
  missing_count: 2,
  state: "not_started",
}

const leadershipSummary: GradeSectionSummary = {
  ...sectionSummary,
  section_id: 45,
  section_code: "LEAD1-A",
  subject: {
    id: 102,
    code: "LEAD 1",
    title: "Leadership 1",
    is_completion_only: true,
  },
}

const adaRow: SectionGradeRow = {
  enrollment_subject_id: 501,
  student_id: 20,
  student_number: "2026-0001",
  student_name: "Ada Lovelace",
  grade_id: null,
  mark: null,
  mark_label: null,
  remarks: null,
  status: "not_recorded",
  status_label: "Not recorded",
}

const graceRow: SectionGradeRow = {
  ...adaRow,
  enrollment_subject_id: 502,
  student_id: 21,
  student_number: "2026-0002",
  student_name: "Grace Hopper",
}

interface StubSectionGradeRouteOptions {
  summaries?: readonly GradeSectionSummary[]
  sheets?: Record<number, unknown>
  saveResponse?: unknown
  submitResponse?: unknown
  saveFails?: boolean
  summaryFailures?: number
}

function gradeSheet(
  section: GradeSectionSummary = sectionSummary,
  rows: readonly SectionGradeRow[] = [adaRow, graceRow],
) {
  return {
    type: "section_grade_sheet",
    section,
    rows,
  }
}

function submittedSheet() {
  return gradeSheet(
    {
      ...sectionSummary,
      recorded_count: 2,
      submitted_count: 2,
      missing_count: 0,
      state: "submitted" as const,
    },
    [
      {
        ...adaRow,
        grade_id: 901,
        mark: "1.50",
        mark_label: "with Distinction",
        status: "submitted",
        status_label: "Submitted",
      },
      {
        ...graceRow,
        grade_id: 902,
        mark: "2.00",
        mark_label: "Good",
        status: "submitted",
        status_label: "Submitted",
      },
    ],
  )
}

function stubSectionGradeRoutes(
  fetchMock: ReturnType<typeof vi.fn<typeof fetch>>,
  {
    summaries = [sectionSummary, leadershipSummary],
    sheets = {
      44: gradeSheet(),
      45: gradeSheet(leadershipSummary, [adaRow]),
    },
    saveResponse = gradeSheet(),
    submitResponse = submittedSheet(),
    saveFails = false,
    summaryFailures = 0,
  }: StubSectionGradeRouteOptions = {},
) {
  let remainingSummaryFailures = summaryFailures

  fetchMock.mockImplementation((input, init) => {
    const url = requestUrl(input)
    const method = init?.method ?? "GET"

    if (url.endsWith("/sections/grade-submission")) {
      if (remainingSummaryFailures > 0) {
        remainingSummaryFailures -= 1
        return Promise.resolve(
          new Response(
            JSON.stringify({
              error: {
                code: "conflict",
                message: "The assigned-class list changed.",
                request_id: "test-request",
              },
            }),
            { status: 409 },
          ),
        )
      }
      return Promise.resolve(new Response(JSON.stringify({ data: summaries })))
    }
    if (url.endsWith("/grades/submit") && method === "POST") {
      return Promise.resolve(
        new Response(JSON.stringify({ data: submitResponse })),
      )
    }
    if (/\/sections\/\d+\/grades$/.test(url) && method === "POST") {
      if (saveFails) {
        return Promise.resolve(
          new Response(
            JSON.stringify({
              error: {
                code: "validation_failed",
                message: "The given data was invalid.",
                errors: { grades: ["The roster changed. Reload and retry."] },
                request_id: "test-request",
              },
            }),
            { status: 422 },
          ),
        )
      }
      return Promise.resolve(
        new Response(JSON.stringify({ data: saveResponse })),
      )
    }

    const match = /\/sections\/(\d+)\/grades$/.exec(url)
    if (match) {
      return Promise.resolve(
        new Response(JSON.stringify({ data: sheets[Number(match[1])] })),
      )
    }
    return Promise.resolve(new Response(JSON.stringify({ data: [] })))
  })
}

async function openClass(
  user: ReturnType<typeof userEvent.setup>,
  name = /CS101.*Programming 1/i,
) {
  const classCard = await screen.findByRole("button", { name })
  await user.click(classCard)
  return screen.findByRole("table", { name: "Section grade sheet" })
}

describe("GradeSubmissionWorkspace", () => {
  const fetchMock = vi.fn<typeof fetch>()
  beforeEach(() => vi.stubGlobal("fetch", fetchMock))
  afterEach(() => vi.unstubAllGlobals())

  it("is not available for a non-faculty role", () => {
    renderWithSession(<GradeSubmissionWorkspace />, {
      session: { ...facultySession, role: "student" },
    })

    expect(
      screen.getByText("This workspace is not available for your role."),
    ).toBeInTheDocument()
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it("shows assigned class cards with subject, section, term, schedule, and progress", async () => {
    stubSectionGradeRoutes(fetchMock)
    renderWithSession(<GradeSubmissionWorkspace />, { session: facultySession })

    const classCard = await screen.findByRole("button", {
      name: /CS101.*Programming 1/i,
    })
    expect(within(classCard).getByText("Section CS101-A")).toBeInTheDocument()
    expect(within(classCard).getByText("2026-2027 · 1st")).toBeInTheDocument()
    expect(
      within(classCard).getByText("MWF · 08:00 AM–09:30 AM"),
    ).toBeInTheDocument()
    expect(within(classCard).getByText("0 / 2 recorded")).toBeInTheDocument()
  })

  it("announces loading while assigned classes are pending", () => {
    fetchMock.mockImplementation(() => new Promise<Response>(() => undefined))
    renderWithSession(<GradeSubmissionWorkspace />, { session: facultySession })

    expect(
      screen.getByText("Loading your assigned classes…"),
    ).toBeInTheDocument()
  })

  it("shows the no-assigned-sections state", async () => {
    stubSectionGradeRoutes(fetchMock, { summaries: [] })
    renderWithSession(<GradeSubmissionWorkspace />, { session: facultySession })

    expect(
      await screen.findByText(
        "No sections are currently assigned to your faculty account.",
      ),
    ).toBeInTheDocument()
  })

  it("retries the assigned-class list without losing the workspace", async () => {
    stubSectionGradeRoutes(fetchMock, { summaryFailures: 1 })
    const user = userEvent.setup()
    renderWithSession(<GradeSubmissionWorkspace />, { session: facultySession })

    expect(await screen.findByText("Conflict")).toBeInTheDocument()
    await user.click(screen.getByRole("button", { name: "Try again" }))

    // After retry, assigned classes should appear
    expect(
      await screen.findByRole("button", { name: /CS101.*Programming 1/i }),
    ).toBeInTheDocument()
  })

  it("opens an assigned class from the keyboard", async () => {
    stubSectionGradeRoutes(fetchMock)
    const user = userEvent.setup()
    renderWithSession(<GradeSubmissionWorkspace />, { session: facultySession })

    const classCard = await screen.findByRole("button", {
      name: /CS101.*Programming 1/i,
    })
    classCard.focus()
    expect(classCard).toHaveFocus()
    await user.keyboard("{Enter}")

    expect(
      await screen.findByRole("table", { name: "Section grade sheet" }),
    ).toBeInTheDocument()
  })

  it("shows enrolled student names and numbers after selecting a class", async () => {
    stubSectionGradeRoutes(fetchMock)
    const user = userEvent.setup()
    renderWithSession(<GradeSubmissionWorkspace />, { session: facultySession })

    const table = await openClass(user)
    expect(within(table).getByText("Ada Lovelace")).toBeInTheDocument()
    expect(within(table).getByText("2026-0001")).toBeInTheDocument()
    expect(within(table).getByText("Grace Hopper")).toBeInTheDocument()
    expect(within(table).getByText("2026-0002")).toBeInTheDocument()
  })

  it("on a phone shows each student as a card with Grade and Remarks stacked under their own labels", async () => {
    stubSectionGradeRoutes(fetchMock)
    const user = userEvent.setup()
    renderWithSession(<GradeSubmissionWorkspace />, { session: facultySession })
    await openClass(user)

    // The card list (the narrow-viewport half of the grade sheet) has one card per student,
    // each with the student first, then Grade and Remarks as labelled full-width fields.
    const card = screen
      .getByRole("heading", { level: 3, name: "Ada Lovelace" })
      .closest("div[data-slot='card']") as HTMLElement
    expect(card).not.toBeNull()
    expect(within(card).getByText("2026-0001")).toBeInTheDocument()
    expect(within(card).getByText("Grade")).toBeInTheDocument()
    expect(within(card).getByText("Remarks")).toBeInTheDocument()
    expect(within(card).getByLabelText("Grade for Ada Lovelace")).toBeInTheDocument()
    expect(within(card).getByLabelText("Remarks for Ada Lovelace")).toBeInTheDocument()

    // It is the real, editable control: choosing a grade works from the card.
    await user.click(within(card).getByLabelText("Grade for Ada Lovelace"))
    await user.click(screen.getByRole("option", { name: /1.75/ }))
    expect(within(card).getByLabelText("Grade for Ada Lovelace")).toHaveTextContent(/1.75/)
  })

  it("offers ordinary marks without completion-only choices", async () => {
    stubSectionGradeRoutes(fetchMock)
    const user = userEvent.setup()
    renderWithSession(<GradeSubmissionWorkspace />, { session: facultySession })

    const table = await openClass(user)
    await user.click(within(table).getByLabelText("Grade for Ada Lovelace"))

    expect(
      screen.getByRole("option", { name: /1.00.*Excellent/ }),
    ).toBeInTheDocument()
    expect(
      screen.getByRole("option", { name: /5.00.*Failed/ }),
    ).toBeInTheDocument()
    expect(
      screen.getByRole("option", { name: /INC.*Incomplete/ }),
    ).toBeInTheDocument()
    expect(
      screen.queryByRole("option", { name: /^C —/ }),
    ).not.toBeInTheDocument()
  })

  it("offers only Complete and Incomplete for completion-only subjects", async () => {
    stubSectionGradeRoutes(fetchMock)
    const user = userEvent.setup()
    renderWithSession(<GradeSubmissionWorkspace />, { session: facultySession })

    const table = await openClass(user, /LEAD 1.*Leadership 1/i)
    await user.click(within(table).getByLabelText("Grade for Ada Lovelace"))

    expect(
      screen.getByRole("option", { name: "C — Complete" }),
    ).toBeInTheDocument()
    expect(
      screen.getByRole("option", { name: "INC — Incomplete" }),
    ).toBeInTheDocument()
    expect(
      screen.queryByRole("option", { name: /Excellent/ }),
    ).not.toBeInTheDocument()
  })

  it("saves only changed rows that have a selected grade", async () => {
    stubSectionGradeRoutes(fetchMock)
    const user = userEvent.setup()
    renderWithSession(<GradeSubmissionWorkspace />, { session: facultySession })

    const table = await openClass(user)
    await user.click(within(table).getByLabelText("Grade for Ada Lovelace"))
    await user.click(
      screen.getByRole("option", { name: /1.50.*with Distinction/ }),
    )
    await user.type(
      within(table).getByLabelText("Remarks for Ada Lovelace"),
      "Strong work",
    )
    await user.click(screen.getByRole("button", { name: "Save draft" }))

    await waitFor(() => {
      const saveCall = fetchMock.mock.calls.find(
        ([input, init]) =>
          requestUrl(input).endsWith("/sections/44/grades") &&
          init?.method === "POST",
      )
      expect(saveCall).toBeDefined()
      const body = saveCall?.[1]?.body
      expect(typeof body).toBe("string")
      if (typeof body !== "string") {
        throw new Error("Expected the saved grade request body to be JSON.")
      }
      expect(JSON.parse(body)).toEqual({
        grades: [{ student_id: 20, mark: "1.50", remarks: "Strong work" }],
      })
    })
  })

  it("requires complete grades and confirmation before final submission", async () => {
    stubSectionGradeRoutes(fetchMock)
    const user = userEvent.setup()
    renderWithSession(<GradeSubmissionWorkspace />, { session: facultySession })

    const table = await openClass(user)
    const submit = screen.getByRole("button", { name: "Submit final grades" })
    expect(submit).toBeDisabled()

    await user.click(within(table).getByLabelText("Grade for Ada Lovelace"))
    await user.click(
      screen.getByRole("option", { name: /1.50.*with Distinction/ }),
    )
    await user.click(within(table).getByLabelText("Grade for Grace Hopper"))
    await user.click(screen.getByRole("option", { name: /2.00.*Good/ }))
    expect(submit).toBeEnabled()

    await user.click(submit)
    expect(
      screen.getByRole("alertdialog", { name: "Submit final grades?" }),
    ).toBeInTheDocument()
    expect(
      fetchMock.mock.calls.some(([input]) =>
        requestUrl(input).endsWith("/grades/submit"),
      ),
    ).toBe(false)

    await user.click(screen.getByRole("button", { name: "Submit section" }))
    await waitFor(() =>
      expect(
        fetchMock.mock.calls.some(
          ([input, init]) =>
            requestUrl(input).endsWith("/sections/44/grades/submit") &&
            init?.method === "POST",
        ),
      ).toBe(true),
    )
  })

  it("renders submitted sheets read-only while awaiting Registrar locking", async () => {
    stubSectionGradeRoutes(fetchMock, {
      sheets: { 44: submittedSheet() },
      summaries: [submittedSheet().section],
    })
    const user = userEvent.setup()
    renderWithSession(<GradeSubmissionWorkspace />, { session: facultySession })

    const table = await openClass(user)
    expect(
      screen.getByText(/awaiting Registrar Head locking/i),
    ).toBeInTheDocument()
    expect(within(table).queryByRole("combobox")).not.toBeInTheDocument()
    expect(within(table).queryByRole("textbox")).not.toBeInTheDocument()
    expect(
      screen.queryByRole("button", { name: "Save draft" }),
    ).not.toBeInTheDocument()
    expect(
      screen.queryByRole("button", { name: "Submit final grades" }),
    ).not.toBeInTheDocument()
  })

  it("keeps unsaved input when a draft save fails", async () => {
    stubSectionGradeRoutes(fetchMock, { saveFails: true })
    const user = userEvent.setup()
    renderWithSession(<GradeSubmissionWorkspace />, { session: facultySession })

    const table = await openClass(user)
    await user.click(within(table).getByLabelText("Grade for Ada Lovelace"))
    await user.click(screen.getByRole("option", { name: /1.75.*Very Good/ }))
    const remarks = within(table).getByLabelText("Remarks for Ada Lovelace")
    await user.type(remarks, "Keep this note")
    await user.click(screen.getByRole("button", { name: "Save draft" }))

    expect(await screen.findByText(/could not be saved/i)).toBeInTheDocument()
    expect(remarks).toHaveValue("Keep this note")
    expect(
      within(table).getByLabelText("Grade for Ada Lovelace"),
    ).toHaveTextContent("1.75")
  })

  it("shows an empty roster without enabling submission", async () => {
    stubSectionGradeRoutes(fetchMock, {
      sheets: { 44: gradeSheet({ ...sectionSummary, enrolled_count: 0 }, []) },
      summaries: [{ ...sectionSummary, enrolled_count: 0, missing_count: 0 }],
    })
    const user = userEvent.setup()
    renderWithSession(<GradeSubmissionWorkspace />, { session: facultySession })

    await user.click(
      await screen.findByRole("button", { name: /CS101.*Programming 1/i }),
    )
    expect(
      await screen.findByText("No enrolled students are in this section yet."),
    ).toBeInTheDocument()
    expect(
      screen.queryByRole("button", { name: "Submit final grades" }),
    ).not.toBeInTheDocument()
  })

  it("separates current semester classes and navigates into previous semesters via lower right button", async () => {
    const term2Summary: GradeSectionSummary = {
      ...sectionSummary,
      section_id: 46,
      section_code: "CS102-B",
      subject: {
        id: 103,
        code: "CS102",
        title: "Data Structures",
        is_completion_only: false,
      },
      academic_term: {
        id: 2,
        school_year: "2025-2026",
        semester: "2nd",
      },
    }

    stubSectionGradeRoutes(fetchMock, {
      summaries: [sectionSummary, term2Summary],
    })
    const user = userEvent.setup()
    renderWithSession(<GradeSubmissionWorkspace />, { session: facultySession })

    // Default view: Current semester classes (2026-2027 · 1st) are shown directly
    expect(
      await screen.findByRole("button", { name: /CS101.*Programming 1/i }),
    ).toBeInTheDocument()
    // Past semester classes are NOT shown in the current view
    expect(
      screen.queryByRole("button", { name: /CS102.*Data Structures/i }),
    ).not.toBeInTheDocument()

    // "Previous Semesters" button appears in the lower right
    const prevSemestersBtn = screen.getByRole("button", { name: /Previous Semesters/i })
    expect(prevSemestersBtn).toBeInTheDocument()

    // Click Previous Semesters
    await user.click(prevSemestersBtn)

    // Now in Previous Semesters view: shows 2025-2026 folder
    expect(await screen.findByText("2025-2026")).toBeInTheDocument()
    expect(screen.getByRole("button", { name: /2nd Semester/i })).toBeInTheDocument()

    // Select 2025-2026 · 2nd Semester
    await user.click(screen.getByRole("button", { name: /2nd Semester/i }))
    expect(
      await screen.findByRole("button", { name: /CS102.*Data Structures/i }),
    ).toBeInTheDocument()
    expect(
      screen.queryByRole("button", { name: /CS101.*Programming 1/i }),
    ).not.toBeInTheDocument()

    // Click Return to Current Semester
    await user.click(screen.getByRole("button", { name: /Return to Current Semester/i }))

    // Back to current semester classes
    expect(
      await screen.findByRole("button", { name: /CS101.*Programming 1/i }),
    ).toBeInTheDocument()
  })

  it("allows faculty to update and save completion grades for locked INC students", async () => {
    const lockedSectionSummary: GradeSectionSummary = {
      ...sectionSummary,
      state: "locked",
    }
    const incRow: SectionGradeRow = {
      ...adaRow,
      grade_id: 88,
      mark: "INC",
      mark_label: "Incomplete",
      status: "locked",
      status_label: "Official / Locked",
    }
    const passingRow: SectionGradeRow = {
      ...graceRow,
      grade_id: 89,
      mark: "1.50",
      mark_label: "Very Good",
      status: "locked",
      status_label: "Official / Locked",
    }

    stubSectionGradeRoutes(fetchMock, {
      summaries: [lockedSectionSummary],
      sheets: {
        44: gradeSheet(lockedSectionSummary, [incRow, passingRow]),
      },
      saveResponse: gradeSheet(lockedSectionSummary, [
        { ...incRow, mark: "2.00", mark_label: "Good" },
        passingRow,
      ]),
    })

    const user = userEvent.setup()
    renderWithSession(<GradeSubmissionWorkspace />, { session: facultySession })

    const table = await openClass(user)

    // Check INC badge
    expect(
      await within(table).findByText("INC · Awaiting completion"),
    ).toBeInTheDocument()

    // The passing row should be read-only text, not a select
    expect(
      within(table).queryByLabelText("Grade for Grace Hopper"),
    ).not.toBeInTheDocument()
    expect(within(table).getByText(/1.50 — Very Good/)).toBeInTheDocument()

    // The INC row should have an editable Select
    const incSelect = within(table).getByLabelText("Grade for Ada Lovelace")
    await user.click(incSelect)
    await user.click(screen.getByRole("option", { name: /2.00.*Good/ }))

    // Save completion grade button appears
    const saveButton = screen.getByRole("button", {
      name: /Save completion grade/i,
    })
    expect(saveButton).toBeInTheDocument()
    await user.click(saveButton)

    await waitFor(() => {
      expect(
        screen.queryByRole("button", { name: /Save completion grade/i }),
      ).not.toBeInTheDocument()
    })
  })

  it("has no detectable accessibility violations once a grade sheet is loaded", async () => {
    stubSectionGradeRoutes(fetchMock)
    const user = userEvent.setup()
    const { container } = renderWithSession(<GradeSubmissionWorkspace />, {
      session: facultySession,
    })

    await openClass(user)
    expect(await axe(container)).toHaveNoViolations()
  })

  it("renders Download CSV Template and Upload Grades (Excel or CSV) buttons on editable grade sheets", async () => {
    stubSectionGradeRoutes(fetchMock)
    const user = userEvent.setup()
    renderWithSession(<GradeSubmissionWorkspace />, {
      session: facultySession,
    })

    await openClass(user)
    expect(
      screen.getByRole("button", { name: /Download CSV Template/i }),
    ).toBeInTheDocument()
    expect(
      screen.getByRole("button", { name: /Upload Grades \(Excel or CSV\)/i }),
    ).toBeInTheDocument()
  })

  describe("uploading a grade sheet", () => {
    const XLSX_TYPE =
      "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"

    beforeEach(() => {
      vi.mocked(toast.success).mockClear()
      vi.mocked(toast.error).mockClear()
      vi.mocked(toast.warning).mockClear()
    })

    it("fills the grades from an Excel grading sheet and warns about marks it had to skip", async () => {
      stubSectionGradeRoutes(fetchMock)
      const user = userEvent.setup()
      renderWithSession(<GradeSubmissionWorkspace />, {
        session: facultySession,
      })
      const table = await openClass(user)

      // The school's Grading Sheet layout: header lines, the header row, then the students.
      const workbook = buildXlsx([
        ["GRADING SHEET"],
        ["SCHEDULE ID: 44 08:00 AM-09:30 AM MWF"],
        [],
        ["StudentID", "Student Name", "Grade", "Numeric Grade", "Semester Numeric Grade"],
        ["2026-0001", "LOVELACE, ADA", "", "", 1.25],
        ["2026-0002", "HOPPER, GRACE", "", "", "Drop"],
      ])
      await user.upload(
        screen.getByLabelText("Upload grade sheet (Excel or CSV)"),
        new File([workbook], "GradingSheet_2026-2027_001_296_CS101_44_37411.xlsx", {
          type: XLSX_TYPE,
        }),
      )

      await waitFor(() =>
        expect(
          within(table).getByLabelText("Grade for Ada Lovelace"),
        ).toHaveTextContent(/1\.25/),
      )
      expect(toast.success).toHaveBeenCalledWith(
        expect.stringContaining("Imported grades for 1 student(s)"),
      )
      expect(toast.warning).toHaveBeenCalledWith(
        expect.stringContaining("1 grade(s) had invalid marks"),
      )
      // Nothing is sent to the server by an upload: the professor still saves or submits.
      expect(
        fetchMock.mock.calls.some(([, init]) => init?.method === "PUT" || init?.method === "POST"),
      ).toBe(false)
    })

    it("warns when the sheet was made for a different schedule", async () => {
      stubSectionGradeRoutes(fetchMock)
      const user = userEvent.setup()
      renderWithSession(<GradeSubmissionWorkspace />, {
        session: facultySession,
      })
      await openClass(user)

      const workbook = buildXlsx([
        ["SCHEDULE ID: 99999 08:00 AM-09:30 AM MWF"],
        [],
        ["StudentID", "Student Name", "Semester Numeric Grade"],
        ["2026-0001", "LOVELACE, ADA", 2],
      ])
      await user.upload(
        screen.getByLabelText("Upload grade sheet (Excel or CSV)"),
        new File([workbook], "sheet.xlsx", { type: XLSX_TYPE }),
      )

      await waitFor(() =>
        expect(toast.warning).toHaveBeenCalledWith(
          expect.stringContaining("Schedule ID 99999"),
        ),
      )
    })

    it("still reads the simple CSV template", async () => {
      stubSectionGradeRoutes(fetchMock)
      const user = userEvent.setup()
      renderWithSession(<GradeSubmissionWorkspace />, {
        session: facultySession,
      })
      const table = await openClass(user)

      await user.upload(
        screen.getByLabelText("Upload grade sheet (Excel or CSV)"),
        new File(
          ["Student Number,Student Name,Grade,Remarks\n2026-0002,Grace Hopper,2.00,\n"],
          "grades.csv",
          { type: "text/csv" },
        ),
      )

      await waitFor(() =>
        expect(
          within(table).getByLabelText("Grade for Grace Hopper"),
        ).toHaveTextContent(/2\.00/),
      )
    })

    it("tells the professor to re-save an old .xls file instead of failing silently", async () => {
      stubSectionGradeRoutes(fetchMock)
      const user = userEvent.setup({ applyAccept: false })
      renderWithSession(<GradeSubmissionWorkspace />, {
        session: facultySession,
      })
      await openClass(user)

      await user.upload(
        screen.getByLabelText("Upload grade sheet (Excel or CSV)"),
        new File(["not read"], "old-sheet.xls", {
          type: "application/vnd.ms-excel",
        }),
      )

      await waitFor(() =>
        expect(toast.error).toHaveBeenCalledWith(
          expect.stringContaining("old .xls file"),
        ),
      )
    })

    it("says so when a grading sheet has no final grades yet", async () => {
      stubSectionGradeRoutes(fetchMock)
      const user = userEvent.setup()
      renderWithSession(<GradeSubmissionWorkspace />, {
        session: facultySession,
      })
      await openClass(user)

      const workbook = buildXlsx([
        ["SCHEDULE ID: 44 08:00 AM-09:30 AM MWF"],
        [],
        ["StudentID", "Student Name", "Numeric Grade", "Semester Numeric Grade"],
        ["2026-0001", "LOVELACE, ADA", "", ""],
      ])
      await user.upload(
        screen.getByLabelText("Upload grade sheet (Excel or CSV)"),
        new File([workbook], "sheet.xlsx", { type: XLSX_TYPE }),
      )

      await waitFor(() =>
        expect(toast.error).toHaveBeenCalledWith(
          expect.stringContaining("No final grades were found"),
        ),
      )
    })
  })

  it("parses CSV grades accurately, normalizes marks, and excludes invalid marks such as DRP", () => {
    const testSheet = gradeSheet() as SectionGradeSheet
    const allowedMarks = ["1.00", "1.25", "1.50", "1.75", "2.00", "2.25", "2.50", "2.75", "3.00", "5.00", "INC"] as const

    const csvContent = [
      "Student Number,Student Name,Grade,Remarks",
      '"2026-0001","Lovelace, Ada",1.25,"Excellent work"',
      '"2026-0002","Hopper, Grace",DRP,"Dropped"',
      '"9999-9999","Unknown Student",2.00,"Not in section"',
    ].join("\n")

    const result = parseGradesCsv(csvContent, testSheet, allowedMarks)

    // Ada Lovelace (student_id: 20) should be matched and set to 1.25
    expect(result.matchedCount).toBe(1)
    expect(result.drafts[20]).toEqual({
      mark: "1.25",
      remarks: "Excellent work",
    })

    // Grace Hopper had "DRP" which is not in allowedMarks -> invalidCount
    expect(result.invalidCount).toBe(1)
    expect(result.drafts[21]).toBeUndefined()

    // Unknown student -> unmatchedCount
    expect(result.unmatchedCount).toBe(1)
  })

  it("generates a downloadable CSV template with enrolled students", () => {
    const testSheet = gradeSheet() as SectionGradeSheet
    const createObjectURLMock = vi.fn().mockReturnValue("blob:test")
    const revokeObjectURLMock = vi.fn()
    window.URL.createObjectURL = createObjectURLMock
    window.URL.revokeObjectURL = revokeObjectURLMock

    const clickMock = vi.fn()
    vi.spyOn(document, "createElement").mockImplementation((tag: string) => {
      const el = document.createElementNS("http://www.w3.org/1999/xhtml", tag)
      if (tag === "a") {
        el.click = clickMock
      }
      return el
    })

    downloadCsvTemplate(testSheet, {})
    expect(createObjectURLMock).toHaveBeenCalled()
    expect(clickMock).toHaveBeenCalled()
    expect(revokeObjectURLMock).toHaveBeenCalledWith("blob:test")
  })
})
