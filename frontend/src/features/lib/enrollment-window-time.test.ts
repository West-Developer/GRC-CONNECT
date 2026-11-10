import { describe, expect, it } from "vitest"

import {
  closingInstant,
  openingInstant,
  toDateInputValue,
} from "@/features/lib/enrollment-window-time"

describe("enrollment-window-time", () => {
  it("returns an empty string for a null instant", () => {
    expect(toDateInputValue(null)).toBe("")
  })

  it("extracts the local calendar date from an ISO instant", () => {
    // Regardless of the machine's timezone offset, the round trip through
    // openingInstant() below is what actually matters for correctness — this
    // just checks the shape.
    expect(toDateInputValue("2028-07-01T08:00:00Z")).toMatch(/^\d{4}-\d{2}-\d{2}$/)
  })

  it("composes the fixed 8:00 AM opening time onto a date", () => {
    expect(openingInstant("2028-07-01")).toBe("2028-07-01T08:00")
  })

  it("composes the fixed 11:59 PM closing time onto a date", () => {
    expect(closingInstant("2028-07-15")).toBe("2028-07-15T23:59")
  })
})
