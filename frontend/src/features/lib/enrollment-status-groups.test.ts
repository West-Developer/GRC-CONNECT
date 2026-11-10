import { describe, expect, it } from "vitest"

import {
  ENROLLMENT_GROUP_PRESENTATION,
  ENROLLMENT_GROUPS,
  ENROLLMENT_STEP_LABELS,
  ENROLLMENT_STEPS,
  formatCount,
  formatShare,
  groupTotal,
} from "@/features/lib/enrollment-status-groups"

describe("enrollment status groups", () => {
  it("puts the payment hold between the student's review and the Registrar, with a label", () => {
    const steps: readonly string[] = ENROLLMENT_STEPS
    expect(steps.indexOf("awaiting_first_payment")).toBe(
      steps.indexOf("pending_student_review") + 1,
    )
    expect(steps.indexOf("pending_registrar_approval")).toBe(
      steps.indexOf("awaiting_first_payment") + 1,
    )
    expect(
      (ENROLLMENT_STEP_LABELS as Record<string, string>)[
        "awaiting_first_payment"
      ],
    ).toBe("Awaiting First Payment")
  })

  it("presents every group with an icon and a label, so color is never the only cue", () => {
    for (const group of ENROLLMENT_GROUPS) {
      const presentation = ENROLLMENT_GROUP_PRESENTATION[group]
      expect(presentation.label.length).toBeGreaterThan(0)
      expect(presentation.icon).toBeDefined()
    }
    expect(ENROLLMENT_GROUPS).toEqual([
      "enrolled",
      "in_progress",
      "not_yet_done",
      "not_enrolled",
    ])
  })

  it("adds the four groups up", () => {
    expect(
      groupTotal({
        enrolled: 11,
        in_progress: 1,
        not_yet_done: 2699,
        not_enrolled: 0,
      }),
    ).toBe(2711)
  })

  it("formats a share, keeping a tiny slice visible instead of rounding it to 0%", () => {
    expect(formatShare(11, 2711)).toBe("0.4%")
    expect(formatShare(1, 3)).toBe("33%")
    expect(formatShare(0, 10)).toBe("0%")
    expect(formatShare(5, 0)).toBe("0%")
    expect(formatShare(10, 10)).toBe("100%")
  })

  it("formats counts with thousands separators", () => {
    expect(formatCount(2711)).toBe("2,711")
  })
})
