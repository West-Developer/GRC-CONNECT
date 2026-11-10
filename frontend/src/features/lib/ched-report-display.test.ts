import { describe, expect, it } from "vitest"

import {
  chedReportTitle,
  chedStudentName,
  chedTabLabel,
  nstpSubjectOf,
  parseChedSubjects,
} from "@/features/lib/ched-report-display"

describe("parseChedSubjects", () => {
  it("splits the file's 'Title (units), Title (units)' text into subjects", () => {
    expect(
      parseChedSubjects(
        "Intro to Computing (3), Leadership 2 (1.5), Fitness (2)",
      ),
    ).toEqual([
      { title: "Intro to Computing", units: "3" },
      { title: "Leadership 2", units: "1.5" },
      { title: "Fitness", units: "2" },
    ])
  })

  it("keeps a title that has commas, an ampersand or an apostrophe whole", () => {
    expect(
      parseChedSubjects(
        "Science, Technology, and Society (3), Rizal's Life & Works (3)",
      ),
    ).toEqual([
      { title: "Science, Technology, and Society", units: "3" },
      { title: "Rizal's Life & Works", units: "3" },
    ])
  })

  it("keeps a title with its own brackets", () => {
    expect(parseChedSubjects("Physical Education (Swimming) (2)")).toEqual([
      { title: "Physical Education (Swimming)", units: "2" },
    ])
  })

  it("returns nothing for an empty text and one entry for text it cannot split", () => {
    expect(parseChedSubjects("")).toEqual([])
    expect(parseChedSubjects("Thesis Writing")).toEqual([
      { title: "Thesis Writing", units: "" },
    ])
  })
})

describe("chedStudentName", () => {
  it("writes the surname first, then the first and middle name and the extension", () => {
    expect(
      chedStudentName({
        surname: "Dela Cruz",
        first_name: "Juan",
        middle_name: "D.",
        name_extension: "Jr.",
      }),
    ).toBe("Dela Cruz, Juan D. Jr.")
  })

  it("leaves out what is empty", () => {
    expect(
      chedStudentName({
        surname: "Santos",
        first_name: "Ana",
        middle_name: "",
        name_extension: "",
      }),
    ).toBe("Santos, Ana")
    expect(chedStudentName({ surname: "Santos" })).toBe("Santos")
    expect(chedStudentName({})).toBe("Student")
  })
})

describe("the NSTP subject of a semester", () => {
  it("is NSTP 1 in the first semester and NSTP 2 in the second", () => {
    expect(nstpSubjectOf("1st")).toBe("NSTP 1")
    expect(nstpSubjectOf("2nd")).toBe("NSTP 2")
    expect(nstpSubjectOf(" 2ND ")).toBe("NSTP 2")
    expect(nstpSubjectOf("summer")).toBe("NSTP")
    expect(nstpSubjectOf(null)).toBe("NSTP")
  })

  it("names the enrollment tab and title after it, and never calls a first-semester list NSTP 2", () => {
    expect(chedTabLabel("nstp-enrollment", "1st")).toBe("NSTP 1 Enrollment")
    expect(chedTabLabel("nstp-enrollment", "2nd")).toBe("NSTP 2 Enrollment")
    expect(chedReportTitle("nstp-enrollment", "1st")).toBe(
      "NSTP 1 Enrollment (Form 2-B)",
    )
    expect(chedReportTitle("nstp-enrollment", "2nd")).toBe(
      "NSTP 2 Enrollment (Form 2-B)",
    )
    expect(chedTabLabel("nstp-enrollment", null)).toBe("NSTP Enrollment")
  })

  it("keeps the other tabs the same whatever the semester", () => {
    for (const semester of ["1st", "2nd", null]) {
      expect(chedTabLabel("enrollment-list", semester)).toBe("Enrollment List")
      expect(chedTabLabel("nstp-graduates", semester)).toBe("NSTP 2 Graduates")
      expect(chedTabLabel("nstp-summary", semester)).toBe("Form 2-A Summary")
    }
    expect(chedReportTitle("nstp-summary", "1st")).toBe(
      "NSTP Summary (Form 2-A)",
    )
  })
})
