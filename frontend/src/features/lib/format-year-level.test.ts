import { describe, expect, it } from "vitest"

import { formatYearLevel } from "@/features/lib/format-year-level"

describe("formatYearLevel", () => {
  it("formats numeric year levels 1 to 4 with ordinal suffixes", () => {
    expect(formatYearLevel(1)).toBe("1st Year")
    expect(formatYearLevel(2)).toBe("2nd Year")
    expect(formatYearLevel(3)).toBe("3rd Year")
    expect(formatYearLevel(4)).toBe("4th Year")
  })

  it("formats string inputs like 'Year 1' or '1' to ordinal representation", () => {
    expect(formatYearLevel("1")).toBe("1st Year")
    expect(formatYearLevel("Year 1")).toBe("1st Year")
    expect(formatYearLevel("Year 2")).toBe("2nd Year")
    expect(formatYearLevel("1st Year")).toBe("1st Year")
  })

  it("returns dash for null, undefined, 0, or empty values", () => {
    expect(formatYearLevel(null)).toBe("—")
    expect(formatYearLevel(undefined)).toBe("—")
    expect(formatYearLevel(0)).toBe("—")
    expect(formatYearLevel("")).toBe("—")
  })
})

