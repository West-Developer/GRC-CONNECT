import { describe, expect, it } from "vitest"

import {
  formatAuditTimestamp,
  formatAuditValue,
  humanizeAuditAction,
  humanizeAuditTarget,
} from "@/features/lib/audit-presentation"

describe("audit presentation", () => {
  it("turns machine actions and targets into short phrases", () => {
    expect(humanizeAuditAction("section.updated")).toBe("Section updated")
    expect(humanizeAuditAction("enrollment_subject_waiver.granted")).toBe(
      "Enrollment subject waiver granted",
    )
    expect(humanizeAuditTarget("section", 3)).toBe("Section #3")
    expect(humanizeAuditTarget("audit_log", null)).toBe("Audit log")
  })

  it("shows empty as a dash and booleans as Yes/No", () => {
    expect(formatAuditValue(null)).toBe("—")
    expect(formatAuditValue("")).toBe("—")
    expect(formatAuditValue(true)).toBe("Yes")
    expect(formatAuditValue(false)).toBe("No")
    expect(formatAuditValue(35)).toBe("35")
    expect(formatAuditValue("R101")).toBe("R101")
  })

  it("handles a missing or unreadable timestamp", () => {
    expect(formatAuditTimestamp(null)).toBe("Time unavailable")
    expect(formatAuditTimestamp("not a date")).toBe("Time unavailable")
    expect(formatAuditTimestamp("2026-07-29T12:00:00Z")).not.toBe(
      "Time unavailable",
    )
  })
})
