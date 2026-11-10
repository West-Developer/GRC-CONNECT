import { describe, expect, it } from "vitest"

import { formatClockTime, formatTimeRange } from "@/features/lib/format-time"

describe("formatClockTime", () => {
  it("formats a morning time with AM", () => {
    expect(formatClockTime("07:30:00")).toBe("7:30 AM")
  })

  it("formats an afternoon time with PM", () => {
    expect(formatClockTime("13:30:00")).toBe("1:30 PM")
  })

  it("formats midnight as 12:00 AM", () => {
    expect(formatClockTime("00:00:00")).toBe("12:00 AM")
  })

  it("formats noon as 12:00 PM", () => {
    expect(formatClockTime("12:00:00")).toBe("12:00 PM")
  })

  it("accepts a value without seconds", () => {
    expect(formatClockTime("19:00")).toBe("7:00 PM")
  })

  it("pads single-digit minutes", () => {
    expect(formatClockTime("09:05:00")).toBe("9:05 AM")
  })
})

describe("formatTimeRange", () => {
  it("formats each side of the range with its own AM/PM marker", () => {
    expect(formatTimeRange("07:30:00", "09:30:00")).toBe("7:30 AM–9:30 AM")
  })

  it("stays unambiguous across the noon boundary", () => {
    expect(formatTimeRange("11:30:00", "13:30:00")).toBe("11:30 AM–1:30 PM")
  })
})
