import { describe, expect, it } from "vitest"

import {
  creditMappingWorkspaceEnvelopeSchema,
  decideTransfereeCreditInputSchema,
  transfereeCreditActionInputSchema,
  transfereeCreditResourceSchema,
} from "@/features/schemas/transferee-credit-schema"

const credit = {
  type: "transferee_credit",
  id: 1,
  student_id: 4,
  student_number: "2026-0001",
  source_institution: "University of the East",
  source_subject_code: "CS101",
  source_subject_title: "Computer Concepts",
  source_grade: "1.50",
  credited_units: 3,
  subject_id: null,
  subject_code: null,
  status: "draft",
  status_label: "Draft",
  processed_at: null,
  created_at: "2026-08-01T00:00:00Z",
}

describe("transferee credit schema", () => {
  it("parses a draft, defaulting the submission and return fields", () => {
    const parsed = transfereeCreditResourceSchema.parse(credit)

    expect(parsed.status).toBe("draft")
    expect(parsed.submitted_at).toBeNull()
    expect(parsed.returned_at).toBeNull()
    expect(parsed.return_reason).toBeNull()
  })

  it("parses a credit the Registrar Head returned", () => {
    const parsed = transfereeCreditResourceSchema.parse({
      ...credit,
      status: "pending",
      status_label: "Pending",
      submitted_at: "2026-08-02T00:00:00Z",
      returned_at: "2026-08-03T00:00:00Z",
      return_reason: "Mapped to the wrong subject.",
    })

    expect(parsed.return_reason).toBe("Mapped to the wrong subject.")
  })

  it("accepts a return only with a reason", () => {
    expect(
      transfereeCreditActionInputSchema.safeParse({ action: "return" }).success,
    ).toBe(false)
    expect(
      decideTransfereeCreditInputSchema.safeParse({
        action: "return",
        reason: "Wrong subject",
      }).success,
    ).toBe(true)
  })
})

describe("credit mapping workspace schema", () => {
  const workspace = {
    data: {
      student_id: 4,
      credits: [
        {
          credit_id: 9,
          suggestions: [
            {
              type: "credit_subject_suggestion",
              subject_id: 10,
              subject_code: "CS101",
              subject_title: "Introduction to Computing",
              units: 3,
              year_level: 1,
              semester: "1st",
              score: 1,
              reasons: ["Same subject code"],
            },
          ],
        },
      ],
      curriculum_subjects: [
        {
          subject_id: 10,
          subject_code: "CS101",
          subject_title: "Introduction to Computing",
          units: 3,
          year_level: 1,
          semester: "1st",
        },
      ],
    },
  }

  it("parses what the API sends for one student", () => {
    const parsed = creditMappingWorkspaceEnvelopeSchema.parse(workspace)

    expect(parsed.data.credits[0].credit_id).toBe(9)
    expect(parsed.data.credits[0].suggestions[0].subject_code).toBe("CS101")
    expect(parsed.data.curriculum_subjects).toHaveLength(1)
  })

  it("parses a student with nothing pending", () => {
    const parsed = creditMappingWorkspaceEnvelopeSchema.parse({
      data: { ...workspace.data, credits: [] },
    })

    expect(parsed.data.credits).toEqual([])
  })

  it("rejects a key the contract does not have", () => {
    expect(
      creditMappingWorkspaceEnvelopeSchema.safeParse({
        data: { ...workspace.data, surprise: true },
      }).success,
    ).toBe(false)
  })
})
