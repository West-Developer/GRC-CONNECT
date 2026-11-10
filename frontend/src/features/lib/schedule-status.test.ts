import { describe, expect, it } from "vitest"

import { scheduleProposalPresentation } from "@/features/lib/schedule-status"

describe("scheduleProposalPresentation", () => {
  it("reads an unsubmitted draft as neutral, not pending", () => {
    const presentation = scheduleProposalPresentation({
      status: "draft",
      is_submitted: false,
      is_returned: false,
      returned_by_role: null,
      decided_by_name: null,
    })

    expect(presentation.tone).toBe("pending")
    expect(presentation.badgeVariant).toBe("secondary")
    expect(presentation.label).toBe("Draft")
  })

  it("reads a submitted draft as pending Dean review", () => {
    const presentation = scheduleProposalPresentation({
      status: "draft",
      is_submitted: true,
      is_returned: false,
      returned_by_role: null,
      decided_by_name: null,
    })

    expect(presentation.badgeVariant).toBe("warning")
    expect(presentation.label).toBe("Pending Dean")
  })

  it("prioritizes the returned state over the underlying draft status", () => {
    const presentation = scheduleProposalPresentation({
      status: "draft",
      is_submitted: false,
      is_returned: true,
      returned_by_role: "dean",
      decided_by_name: "Dr. Santos",
    })

    expect(presentation.tone).toBe("returned")
    expect(presentation.badgeVariant).toBe("destructive")
    expect(presentation.description).toContain("the Dean")
  })

  it("names the Executive Director when they returned it", () => {
    const presentation = scheduleProposalPresentation({
      status: "draft",
      is_submitted: false,
      is_returned: true,
      returned_by_role: "executive_director",
      decided_by_name: null,
    })

    expect(presentation.description).toContain("the Executive Director")
  })

  it("falls back to a generic reviewer when the role is missing", () => {
    const presentation = scheduleProposalPresentation({
      status: "draft",
      is_submitted: false,
      is_returned: true,
      returned_by_role: null,
      decided_by_name: null,
    })

    expect(presentation.description).toContain("a reviewer")
  })

  it("reads dean_approved as an approved (success) tone", () => {
    const presentation = scheduleProposalPresentation({
      status: "dean_approved",
      is_submitted: true,
      is_returned: false,
      returned_by_role: null,
      decided_by_name: "Dr. Santos",
    })

    expect(presentation.tone).toBe("approved")
    expect(presentation.badgeVariant).toBe("success")
    expect(presentation.label).toBe("Pending Executive Director")
  })

  it("reads executive_approved as approved and ready to publish", () => {
    const presentation = scheduleProposalPresentation({
      status: "executive_approved",
      is_submitted: true,
      is_returned: false,
      returned_by_role: null,
      decided_by_name: "Dr. Cruz",
    })

    expect(presentation.tone).toBe("approved")
    expect(presentation.badgeVariant).toBe("success")
  })

  it("reads published with the default (primary) variant", () => {
    const presentation = scheduleProposalPresentation({
      status: "published",
      is_submitted: true,
      is_returned: false,
      returned_by_role: null,
      decided_by_name: null,
    })

    expect(presentation.tone).toBe("published")
    expect(presentation.badgeVariant).toBe("default")
  })

  it("reads closed as a neutral terminal state", () => {
    const presentation = scheduleProposalPresentation({
      status: "closed",
      is_submitted: true,
      is_returned: false,
      returned_by_role: null,
      decided_by_name: null,
    })

    expect(presentation.tone).toBe("closed")
    expect(presentation.badgeVariant).toBe("secondary")
  })
})
