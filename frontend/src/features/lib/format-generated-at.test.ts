import { describe, expect, it } from "vitest"

import {
  corDownloadFilename,
  formatGeneratedAt,
} from "@/features/lib/format-generated-at"

describe("formatGeneratedAt", () => {
  it("converts a UTC timestamp to Asia/Manila wall-clock time regardless of the runner's own timezone", () => {
    expect(formatGeneratedAt("2026-10-01T03:29:28.000000Z")).toBe(
      "10/1/2026, 11:29:28 AM",
    )
  })

  it("rolls the date forward across midnight when the Manila offset crosses a day boundary", () => {
    expect(formatGeneratedAt("2026-09-30T20:05:00.000000Z")).toBe(
      "10/1/2026, 4:05:00 AM",
    )
  })
})

describe("corDownloadFilename", () => {
  it("slugs the student name and uses the Manila calendar date", () => {
    expect(
      corDownloadFilename("West Apay. Ragma", "2026-10-01T03:29:28.000000Z"),
    ).toBe("COR_West_Apay_Ragma_2026-10-01.pdf")
  })

  it("collapses punctuation and trims leading/trailing separators", () => {
    expect(
      corDownloadFilename("  Dela--Cruz, Jr.  ", "2026-01-05T00:00:00.000000Z"),
    ).toBe("COR_Dela_Cruz_Jr_2026-01-05.pdf")
  })
})
