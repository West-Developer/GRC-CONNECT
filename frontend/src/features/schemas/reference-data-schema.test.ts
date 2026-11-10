import { describe, expect, it } from "vitest"

import {
  academicTermSchema,
  academicTermsEnvelopeSchema,
  curriculumSchema,
  subjectSchema,
} from "@/features/schemas/reference-data-schema"

describe("curriculumSchema", () => {
  it("accepts the backend's archived curriculum status", () => {
    expect(
      curriculumSchema.parse({
        type: "curriculum",
        id: 22,
        program_id: 11,
        name: "BSCS 2023 Curriculum",
        effective_school_year: "2023-2024",
        status: "archived",
        status_label: "Archived",
        decided_at: null,
        last_decision_reason: null,
        subjects: [],
      }).status,
    ).toBe("archived")
  })

  it.each([
    "draft",
    "pending_dean_review",
    "pending_executive_review",
    "active",
    "archived",
  ] as const)(
    "accepts the '%s' curriculum status along with the decision fields",
    (status) => {
      expect(
        curriculumSchema.parse({
          type: "curriculum",
          id: 22,
          program_id: 11,
          name: "BSCS 2023 Curriculum",
          effective_school_year: "2023-2024",
          status,
          status_label: "Whatever",
          decided_at: "2026-08-07T00:00:00.000000Z",
          last_decision_reason: "Missing PATHFIT 2.",
          subjects: [],
        }).status,
      ).toBe(status)
    },
  )
})

describe("subjectSchema", () => {
  it("accepts catalog placeholders with zero units without rejecting the subject list", () => {
    expect(
      subjectSchema.parse({
        type: "subject",
        id: 166,
        code: "PHILHIST",
        title: "Readings in Philippine History",
        units: 0,
        status: "active",
        status_label: "Active",
        is_completion_only: false,
      }).units,
    ).toBe(0)
  })
})

describe("academicTermSchema", () => {
  it("accepts the backend academic term payload including add_drop_opens_at and next_term_sequence", () => {
    const termPayload = {
      type: "academic-term",
      id: 1,
      school_year: "2025-2026",
      semester: "2nd",
      starts_at: "2026-01-12T00:00:00Z",
      ends_at: "2026-05-30T23:59:59Z",
      enrollment_opens_at: "2026-01-05T08:00:00Z",
      enrollment_closes_at: "2026-01-16T17:00:00Z",
      add_drop_opens_at: "2026-01-17T08:00:00Z",
      add_drop_deadline_at: "2026-01-24T17:00:00Z",
      grading_deadline_at: "2026-06-05T17:00:00Z",
      closed_at: null,
      archived_at: null,
      status: "semester_ongoing",
      status_label: "Semester Ongoing",
      is_actionable_current: true,
      next_term_sequence: {
        school_year: "2026-2027",
        semester: "1st",
      },
    }

    const parsed = academicTermSchema.parse(termPayload)
    expect(parsed.id).toBe(1)
    expect(parsed.add_drop_opens_at).toBe("2026-01-17T08:00:00Z")

    // Test envelope with null add_drop_opens_at and null next_term_sequence
    const envelope = academicTermsEnvelopeSchema.parse({
      data: [
        termPayload,
        {
          ...termPayload,
          id: 2,
          add_drop_opens_at: null,
          next_term_sequence: null,
        },
      ],
    })
    expect(envelope.data).toHaveLength(2)
    expect(envelope.data[1].add_drop_opens_at).toBeNull()
    expect(envelope.data[1].next_term_sequence).toBeNull()
  })
})

