import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import {
  getSectionGradeSheet,
  listGradeSubmissionSections,
  saveSectionGradeDrafts,
  submitSectionGrades,
} from "@/features/services/section-grade-service"

const summary = {
  type: "grade_section_summary",
  section_id: 44,
  section_code: "BSCS-1A",
  subject: {
    id: 101,
    code: "CS101",
    title: "Programming 1",
    is_completion_only: false,
  },
  academic_term: { id: 1, school_year: "2026-2027", semester: "1st" },
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
} as const

const sheet = {
  type: "section_grade_sheet",
  section: summary,
  rows: [
    {
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
    },
  ],
} as const

describe("section-grade-service", () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(() => vi.stubGlobal("fetch", fetchMock))
  afterEach(() => vi.unstubAllGlobals())

  it("lists the authenticated professor's assigned grade sections", async () => {
    fetchMock.mockResolvedValueOnce(
      new Response(JSON.stringify({ data: [summary] })),
    )

    await expect(listGradeSubmissionSections()).resolves.toEqual([summary])
    expect(fetchMock.mock.calls[0]?.[0]).toContain(
      "/api/v1/sections/grade-submission",
    )
    expect(fetchMock.mock.calls[0]?.[1]?.method).toBe("GET")
  })

  it("gets the protected section grade sheet", async () => {
    fetchMock.mockResolvedValueOnce(
      new Response(JSON.stringify({ data: sheet })),
    )

    await expect(getSectionGradeSheet(44)).resolves.toEqual(sheet)
    expect(fetchMock.mock.calls[0]?.[0]).toContain("/api/v1/sections/44/grades")
  })

  it("posts only the bulk draft rows supplied by the workspace", async () => {
    fetchMock.mockResolvedValueOnce(
      new Response(JSON.stringify({ data: sheet })),
    )

    await saveSectionGradeDrafts(44, {
      grades: [{ student_id: 20, mark: "1.50", remarks: "Strong work" }],
    })

    expect(fetchMock.mock.calls[0]?.[1]?.method).toBe("POST")
    const body = fetchMock.mock.calls[0]?.[1]?.body
    expect(typeof body).toBe("string")
    if (typeof body !== "string") {
      throw new Error("Expected the saved grade request body to be JSON.")
    }
    expect(JSON.parse(body)).toEqual({
      grades: [{ student_id: 20, mark: "1.50", remarks: "Strong work" }],
    })
  })

  it("submits the complete section through the dedicated endpoint", async () => {
    fetchMock.mockResolvedValueOnce(
      new Response(JSON.stringify({ data: sheet })),
    )

    await submitSectionGrades(44)

    expect(fetchMock.mock.calls[0]?.[0]).toContain(
      "/api/v1/sections/44/grades/submit",
    )
    expect(fetchMock.mock.calls[0]?.[1]?.method).toBe("POST")
    expect(fetchMock.mock.calls[0]?.[1]?.body).toBe("{}")
  })
})
