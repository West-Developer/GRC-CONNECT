import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import { listRegistrarStudents } from "@/features/services/registrar-student-service"

function urlOf(input: RequestInfo | URL | undefined): string {
  return typeof input === "string"
    ? input
    : input instanceof URL
      ? input.toString()
      : (input?.url ?? "")
}

const student = {
  type: "registrar_student",
  id: 12,
  student_number: "HIST-2016-10806",
  name: "Raymond Q. Ignacio",
  program_code: "BSED-ENG",
  year_level: 4,
  enrollment_category: "regular",
  academic_standing: "good",
  academic_standing_label: "Good standing",
}

const links = {
  first: "http://localhost/api/v1/students?page=1",
  last: "http://localhost/api/v1/students?page=1",
  prev: null,
  next: null,
}

describe("registrar-student-service", () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(() => vi.stubGlobal("fetch", fetchMock))
  afterEach(() => {
    vi.unstubAllGlobals()
    fetchMock.mockReset()
  })

  it("lists the students with the search and the page", async () => {
    fetchMock.mockResolvedValueOnce(
      new Response(
        JSON.stringify({
          data: [student],
          links,
          meta: { current_page: 2, last_page: 3, per_page: 20, total: 41 },
        }),
      ),
    )

    const page = await listRegistrarStudents({ search: "Ignacio", page: 2 })

    expect(page.data[0]?.student_number).toBe("HIST-2016-10806")
    expect(page.meta.total).toBe(41)
    const [url] = fetchMock.mock.calls[0] ?? []
    expect(urlOf(url)).toContain("/api/v1/students?")
    expect(urlOf(url)).toContain("search=Ignacio")
    expect(urlOf(url)).toContain("page=2")
  })

  it("accepts a student with no category yet", async () => {
    fetchMock.mockResolvedValueOnce(
      new Response(
        JSON.stringify({
          data: [{ ...student, enrollment_category: null }],
          links,
          meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
        }),
      ),
    )

    const page = await listRegistrarStudents({})

    expect(page.data[0]?.enrollment_category).toBeNull()
  })

  it("rejects a response that does not match the contract", async () => {
    fetchMock.mockResolvedValueOnce(
      new Response(
        JSON.stringify({
          data: [{ ...student, surprise: true }],
          links,
          meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
        }),
      ),
    )

    await expect(listRegistrarStudents({})).rejects.toThrow(/contract/)
  })
})
