import { describe, expect, it } from "vitest"

import { sortTermsNewestFirst } from "@/features/lib/academic-term-order"

const term = (id: number, school_year: string, semester: string) => ({
  id,
  school_year,
  semester,
})

describe("sortTermsNewestFirst", () => {
  it("orders by school year, then semester, newest first, whatever order the terms were created in", () => {
    // Ids are creation order: the older school years were added after 2025-2026.
    const terms = [
      term(6, "2025-2026", "2nd"),
      term(5, "2025-2026", "1st"),
      term(19, "2017-2018", "1st"),
      term(20, "2017-2018", "2nd"),
      term(30, "2022-2023", "2nd"),
      term(22, "2024-2025", "1st"),
      term(23, "2024-2025", "2nd"),
    ]

    expect(
      sortTermsNewestFirst(terms).map(
        (item) => `${item.school_year} ${item.semester}`,
      ),
    ).toEqual([
      "2025-2026 2nd",
      "2025-2026 1st",
      "2024-2025 2nd",
      "2024-2025 1st",
      "2022-2023 2nd",
      "2017-2018 2nd",
      "2017-2018 1st",
    ])
  })

  it("puts a summer term after the second semester of its school year", () => {
    expect(
      sortTermsNewestFirst([
        term(1, "2025-2026", "1st"),
        term(2, "2025-2026", "summer"),
        term(3, "2025-2026", "2nd"),
      ]).map((item) => item.semester),
    ).toEqual(["summer", "2nd", "1st"])
  })

  it("leaves the caller's array alone and breaks a tie by the newer id", () => {
    const terms = [term(1, "2025-2026", "1st"), term(2, "2025-2026", "1st")]

    const sorted = sortTermsNewestFirst(terms)

    expect(sorted.map((item) => item.id)).toEqual([2, 1])
    expect(terms.map((item) => item.id)).toEqual([1, 2])
  })
})
