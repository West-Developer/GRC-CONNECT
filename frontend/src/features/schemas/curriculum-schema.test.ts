import { describe, expect, it } from "vitest"

import {
  curriculumReplacementSchema,
  curriculumSubjectPlacementInputSchema,
  storeCurriculumInputSchema,
} from "@/features/schemas/curriculum-schema"

const subject = {
  subject_id: 11,
  year_level: 1,
  semester: "1st",
  is_required: true,
  prerequisites: [],
} as const

describe("curriculum authoring schemas", () => {
  it("accepts only program, name, and subject placements when creating a curriculum", () => {
    expect(
      storeCurriculumInputSchema.parse({
        program_id: 1,
        name: "BSCS 2026",
        subjects: [subject],
      }),
    ).toEqual({
      program_id: 1,
      name: "BSCS 2026",
      subjects: [subject],
    })
  })

  it("rejects server-owned school year and status fields in creation payloads", () => {
    expect(
      storeCurriculumInputSchema.safeParse({
        program_id: 1,
        name: "BSCS 2026",
        subjects: [],
        effective_school_year: "2099-2100",
      }).success,
    ).toBe(false)
    expect(
      storeCurriculumInputSchema.safeParse({
        program_id: 1,
        name: "BSCS 2026",
        subjects: [],
        status: "active",
      }).success,
    ).toBe(false)
  })

  it("rejects server-owned school year from complete replacement payloads", () => {
    expect(
      curriculumReplacementSchema.safeParse({
        name: "BSCS 2026",
        subjects: [subject],
        effective_school_year: "2099-2100",
      }).success,
    ).toBe(false)
  })

  it("parses the existing-subject placement variant", () => {
    expect(
      curriculumSubjectPlacementInputSchema.parse({
        source: "existing",
        subject_id: 11,
        year_level: 2,
        semester: "2nd",
      }),
    ).toEqual({
      source: "existing",
      subject_id: 11,
      year_level: 2,
      semester: "2nd",
    })
  })

  it("parses the new-subject placement variant and rejects invalid slots", () => {
    expect(
      curriculumSubjectPlacementInputSchema.parse({
        source: "new",
        code: "CS102",
        title: "Data Structures",
        units: 3,
        year_level: 1,
        semester: "1st",
      }),
    ).toMatchObject({ source: "new", code: "CS102", units: 3 })
    expect(
      curriculumSubjectPlacementInputSchema.safeParse({
        source: "new",
        code: "CS102",
        title: "Data Structures",
        units: 3,
        year_level: 5,
        semester: "summer",
      }).success,
    ).toBe(false)
  })
})
