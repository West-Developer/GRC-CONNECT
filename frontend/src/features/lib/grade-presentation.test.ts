import { describe, expect, it } from "vitest"

import {
  allowedMarksForSubject,
  gradeBadgeVariant,
  markTone,
  markToneBadgeVariant,
  markToneRowClass,
} from "@/features/lib/grade-presentation"

describe("allowedMarksForSubject", () => {
  it("limits Leadership subjects to Complete or Incomplete", () => {
    expect(allowedMarksForSubject(true)).toEqual(["C", "INC"])
  })
})

describe("gradeBadgeVariant", () => {
  it("maps locked to default", () => {
    expect(gradeBadgeVariant("locked")).toBe("default")
  })

  it("maps submitted to secondary", () => {
    expect(gradeBadgeVariant("submitted")).toBe("secondary")
  })

  it("maps draft to outline", () => {
    expect(gradeBadgeVariant("draft")).toBe("outline")
  })
})

describe("markTone", () => {
  it("treats null as not-taken", () => {
    expect(markTone(null)).toBe("not-taken")
  })

  it("treats 5.00 as failed", () => {
    expect(markTone("5.00")).toBe("failed")
  })

  it("treats W as withdrawn, with its own badge and row tint", () => {
    expect(markTone("W")).toBe("withdrawn")
    expect(markToneBadgeVariant("withdrawn")).toBe("secondary")
    expect(markToneRowClass("withdrawn")).not.toBe("")
  })

  it.each(["NC", "INC", "DRP"] as const)("treats %s as incomplete", (mark) => {
    expect(markTone(mark)).toBe("incomplete")
  })

  it.each(["1.00", "1.75", "2.50", "3.00", "C"] as const)(
    "treats %s as passed",
    (mark) => {
      expect(markTone(mark)).toBe("passed")
    },
  )
})

describe("markToneBadgeVariant", () => {
  it("maps each tone to its badge variant", () => {
    expect(markToneBadgeVariant("passed")).toBe("success")
    expect(markToneBadgeVariant("failed")).toBe("destructive")
    expect(markToneBadgeVariant("incomplete")).toBe("warning")
    expect(markToneBadgeVariant("not-taken")).toBe("outline")
  })
})

describe("markToneRowClass", () => {
  it("returns no class for a passed row", () => {
    expect(markToneRowClass("passed")).toBe("")
  })

  it("returns a distinct class for failed, incomplete, and not-taken", () => {
    const failed = markToneRowClass("failed")
    const incomplete = markToneRowClass("incomplete")
    const notTaken = markToneRowClass("not-taken")

    expect(failed).not.toBe("")
    expect(incomplete).not.toBe("")
    expect(notTaken).not.toBe("")
    expect(new Set([failed, incomplete, notTaken]).size).toBe(3)
  })
})
