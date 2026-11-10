import { describe, expect, it } from "vitest"

import {
  formatYearLevelOrdinal,
  isAdvanceSubject,
  isBacklogSubject,
  semesterOrdinal,
} from "@/features/lib/curriculum-ordinal"

describe("semesterOrdinal", () => {
  it("gives year 1 1st semester the first ordinal", () => {
    expect(semesterOrdinal(1, "1st")).toBe(1)
  })

  it("gives year 1 2nd semester the second ordinal", () => {
    expect(semesterOrdinal(1, "2nd")).toBe(2)
  })

  it("gives year 3 1st semester the fifth ordinal", () => {
    expect(semesterOrdinal(3, "1st")).toBe(5)
  })

  it("gives year 4 2nd semester the eighth ordinal", () => {
    expect(semesterOrdinal(4, "2nd")).toBe(8)
  })
})

describe("isBacklogSubject", () => {
  it("is backlog when the subject's year is earlier than the student's", () => {
    expect(isBacklogSubject(1, "1st", 3, "1st")).toBe(true)
  })

  it("is backlog when same year but an earlier semester", () => {
    expect(isBacklogSubject(3, "1st", 3, "2nd")).toBe(true)
  })

  it("is not backlog for the student's current semester", () => {
    expect(isBacklogSubject(3, "2nd", 3, "2nd")).toBe(false)
  })

  it("is not backlog for a future semester", () => {
    expect(isBacklogSubject(4, "1st", 3, "2nd")).toBe(false)
  })
})

describe("isAdvanceSubject", () => {
  it("is advance when exactly one year ahead, same semester", () => {
    expect(isAdvanceSubject(2, "2nd", 1, "2nd")).toBe(true)
  })

  it("is not advance for the student's current semester", () => {
    expect(isAdvanceSubject(1, "2nd", 1, "2nd")).toBe(false)
  })

  it("is not advance for a backlog (earlier) semester", () => {
    expect(isAdvanceSubject(1, "1st", 1, "2nd")).toBe(false)
  })

  it("is not advance when two or more years ahead", () => {
    expect(isAdvanceSubject(3, "2nd", 1, "2nd")).toBe(false)
  })
})

describe("formatYearLevelOrdinal", () => {
  it("formats year levels as institutional ordinals", () => {
    expect(formatYearLevelOrdinal(1)).toBe("1ST YEAR")
    expect(formatYearLevelOrdinal(2)).toBe("2ND YEAR")
    expect(formatYearLevelOrdinal(3)).toBe("3RD YEAR")
    expect(formatYearLevelOrdinal(4)).toBe("4TH YEAR")
    expect(formatYearLevelOrdinal(5)).toBe("YEAR 5")
    expect(formatYearLevelOrdinal(null)).toBe("—")
    expect(formatYearLevelOrdinal(undefined)).toBe("—")
  })
})
