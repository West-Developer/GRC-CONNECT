import { describe, expect, it } from "vitest"

import {
  limitSourceLabel,
  loadSummary,
} from "@/features/lib/faculty-load-presentation"

describe("faculty load presentation", () => {
  it("names where a maximum comes from", () => {
    expect(limitSourceLabel("override", "Full-time")).toBe("Own max load")
    expect(limitSourceLabel("employment_type", "Part-time")).toBe(
      "Part-time limit",
    )
    expect(limitSourceLabel("college_default", null)).toBe("College default")
    expect(limitSourceLabel(null, "Full-time")).toBe("No limit set")
  })

  it("summarises a load against its maximum, or alone when there is none", () => {
    expect(loadSummary(9, 12)).toBe("9 of 12 units")
    expect(loadSummary(9, null)).toBe("9 units")
  })
})
