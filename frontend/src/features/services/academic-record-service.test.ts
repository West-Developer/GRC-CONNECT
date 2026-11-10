import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import { ApiClientError } from "@/features/services/api-client"
import {
  getAcademicRecord,
  getGradeSlip,
  getProspectus,
  searchAcademicRecordStudents,
} from "@/features/services/academic-record-service"

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
  transferee_credits: [],
  semesters: [],
  unplaced_entries: [],
} as const

const gradeSlip = {
  type: "grade_slip",
  student_id: 4,
  student_number: "2026-0001",
  program_code: "BSIT",
  program_name: "BS Information Technology",
  year_level: 2,
  enrollment_category: "regular",
  enrollment_category_label: "Regular",
  academic_term_id: 2,
  school_year: "2026-2027",
  semester: "2nd",
  term_label: "2026-2027 2nd Semester",
  rows: [],
  total_academic_units: 0,
  gpa_units: 0,
  gpa: null,
  excluded_from_gpa_count: 0,
  generated_at: "2026-07-30T00:00:00Z",
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
      term_label: "2026-2027 2nd Semester",
      rows: [],
      total_academic_units: 0,
      gpa_units: 0,
      gpa: null,
      excluded_from_gpa_count: 0,
    },
  ],
} as const

describe("academic-record-service", () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(() => vi.stubGlobal("fetch", fetchMock))
  afterEach(() => vi.unstubAllGlobals())

  it("fetches the caller's own prospectus without a student_id query", async () => {
    fetchMock.mockResolvedValueOnce(
      new Response(JSON.stringify({ data: prospectus })),
    )

    const result = await getProspectus()

    expect(result).toEqual(prospectus)
    expect(fetchMock.mock.calls[0]?.[0]).not.toContain("student_id")
  })

  it("fetches another student's prospectus with a student_id query", async () => {
    fetchMock.mockResolvedValueOnce(
      new Response(JSON.stringify({ data: prospectus })),
    )

    await getProspectus(4)

    expect(fetchMock.mock.calls[0]?.[0]).toContain("student_id=4")
  })

  it("rejects a prospectus payload that does not match the v1 contract", async () => {
    fetchMock.mockResolvedValueOnce(
      new Response(JSON.stringify({ data: { ...prospectus, type: "wrong" } })),
    )

    await expect(getProspectus()).rejects.toBeInstanceOf(ApiClientError)
  })

  it("fetches a grade slip for a given academic term", async () => {
    fetchMock.mockResolvedValueOnce(
      new Response(JSON.stringify({ data: gradeSlip })),
    )

    const result = await getGradeSlip(2)

    expect(result).toEqual(gradeSlip)
    expect(fetchMock.mock.calls[0]?.[0]).toContain("academic_term_id=2")
  })

  it("includes student_id when fetching another student's grade slip", async () => {
    fetchMock.mockResolvedValueOnce(
      new Response(JSON.stringify({ data: gradeSlip })),
    )

    await getGradeSlip(2, 4)

    const url = fetchMock.mock.calls[0]?.[0] as string
    expect(url).toContain("academic_term_id=2")
    expect(url).toContain("student_id=4")
  })

  it("rejects a grade slip payload that does not match the v1 contract", async () => {
    fetchMock.mockResolvedValueOnce(
      new Response(JSON.stringify({ data: { ...gradeSlip, type: "wrong" } })),
    )

    await expect(getGradeSlip(2)).rejects.toBeInstanceOf(ApiClientError)
  })

  it("fetches the caller's own academic record without a student_id query", async () => {
    fetchMock.mockResolvedValueOnce(
      new Response(JSON.stringify({ data: academicRecord })),
    )

    const result = await getAcademicRecord()

    expect(result).toEqual(academicRecord)
    expect(fetchMock.mock.calls[0]?.[0]).not.toContain("student_id")
  })

  it("fetches another student's academic record with a student_id query", async () => {
    fetchMock.mockResolvedValueOnce(
      new Response(JSON.stringify({ data: academicRecord })),
    )

    await getAcademicRecord(4)

    expect(fetchMock.mock.calls[0]?.[0]).toContain("student_id=4")
  })

  it("rejects an academic record payload that does not match the v1 contract", async () => {
    fetchMock.mockResolvedValueOnce(
      new Response(
        JSON.stringify({ data: { ...academicRecord, type: "wrong" } }),
      ),
    )

    await expect(getAcademicRecord()).rejects.toBeInstanceOf(ApiClientError)
  })

  it("searches students for academic records with query parameters", async () => {
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
    fetchMock.mockResolvedValueOnce(
      new Response(JSON.stringify({ data: [studentCandidate] })),
    )

    const result = await searchAcademicRecordStudents({
      search: "Bonifacio",
      by: "name",
      limit: 10,
    })

    expect(result).toHaveLength(1)
    expect(result[0]?.name).toBe("Bonifacio B. Pangilinan")
    const callUrl = fetchMock.mock.calls[0]?.[0] as string
    expect(callUrl).toContain("/api/v1/academic-record/students")
    expect(callUrl).toContain("search=Bonifacio")
    expect(callUrl).toContain("by=name")
    expect(callUrl).toContain("limit=10")
  })
})
