import { describe, expect, it } from "vitest"

import { enrollmentStatusStudentsPath } from "@/features/services/dashboard-service"

describe("enrollmentStatusStudentsPath", () => {
  it("asks for a whole department by default, first page of 15", () => {
    expect(enrollmentStatusStudentsPath({ department: "ccs" })).toBe(
      "/api/v1/dashboards/enrollment-status/students?department=ccs&page=1&per_page=15",
    )
  })

  it("names a section by its code", () => {
    const path = enrollmentStatusStudentsPath({
      department: "ccs",
      sectionCode: "IT201",
      group: "enrolled",
      academicTermId: 37,
      page: 2,
    })
    const parameters = new URL(path, "http://localhost").searchParams

    expect(parameters.get("section_code")).toBe("IT201")
    expect(parameters.get("group")).toBe("enrolled")
    expect(parameters.get("academic_term_id")).toBe("37")
    expect(parameters.get("page")).toBe("2")
    expect(parameters.has("without_section")).toBe(false)
  })

  it("asks for students with no section yet when the section is null", () => {
    const parameters = new URL(
      enrollmentStatusStudentsPath({ department: "coe", sectionCode: null }),
      "http://localhost",
    ).searchParams

    expect(parameters.get("without_section")).toBe("1")
    expect(parameters.has("section_code")).toBe(false)
  })
})
