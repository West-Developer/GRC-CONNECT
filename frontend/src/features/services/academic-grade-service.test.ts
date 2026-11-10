import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import {
  createAcademicGrade,
  listAcademicGrades,
  lockAllAcademicGrades,
  updateAcademicGrade,
} from "@/features/services/academic-grade-service"

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

const grade = {
  type: "academic_grade",
  id: 3,
  student_id: 4,
  student_number: "2026-0001",
  subject_id: 7,
  subject_code: "CS101",
  section_id: 5,
  academic_term_id: 2,
  mark: "1.50",
  mark_label: "with Distinction",
  final_grade: "1.50",
  remarks: null,
  status: "draft",
  status_label: "Draft",
  submitted_at: null,
  locked_at: null,
} as const

describe("academic-grade-service", () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(() => vi.stubGlobal("fetch", fetchMock))
  afterEach(() => vi.unstubAllGlobals())

  it("lists grades with filters and pagination", async () => {
    fetchMock.mockResolvedValueOnce(
      new Response(
        JSON.stringify({
          data: [grade],
          links: paginationLinks,
          meta: paginationMeta,
        }),
      ),
    )

    const result = await listAcademicGrades({
      status: "draft",
      page: 1,
      per_page: 20,
    })

    expect(result.data).toEqual([grade])
    expect(fetchMock.mock.calls[0]?.[0]).toContain(
      "status=draft&page=1&per_page=20",
    )
  })

  it("creates a draft grade", async () => {
    fetchMock.mockResolvedValueOnce(
      new Response(JSON.stringify({ data: grade }), { status: 201 }),
    )

    const result = await createAcademicGrade({
      student_id: 4,
      subject_id: 7,
      section_id: 5,
      academic_term_id: 2,
      mark: "1.50",
    })

    expect(result).toEqual(grade)
    expect(fetchMock.mock.calls[0]?.[1]?.method).toBe("POST")
  })

  it("submits a draft grade", async () => {
    fetchMock.mockResolvedValueOnce(
      new Response(JSON.stringify({ data: { ...grade, status: "submitted" } })),
    )

    const result = await updateAcademicGrade(3, { action: "submit" })

    expect(result.status).toBe("submitted")
    expect(fetchMock.mock.calls[0]?.[0]).toContain("/academic-grades/3")
    expect(fetchMock.mock.calls[0]?.[1]?.method).toBe("PATCH")
  })

  it("locks all submitted grades", async () => {
    fetchMock.mockResolvedValueOnce(
      new Response(
        JSON.stringify({
          data: {
            locked_count: 5,
            message: "Successfully locked 5 grades.",
          },
        }),
      ),
    )

    const result = await lockAllAcademicGrades({
      academic_term_id: 2,
      college: "CCS",
    })

    expect(result.locked_count).toBe(5)
    expect(fetchMock.mock.calls[0]?.[0]).toContain("/academic-grades/lock-all")
    expect(fetchMock.mock.calls[0]?.[1]?.method).toBe("POST")
    expect(JSON.parse(String(fetchMock.mock.calls[0]?.[1]?.body))).toEqual({
      academic_term_id: 2,
      college: "CCS",
    })
  })
})

