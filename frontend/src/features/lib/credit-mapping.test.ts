import { describe, expect, it } from "vitest"

import {
  groupCreditsByStudent,
  isCreditMappingIncomplete,
  preselectSuggestions,
} from "@/features/lib/credit-mapping"

describe("isCreditMappingIncomplete", () => {
  it("is incomplete when the student has no credits at all", () => {
    expect(isCreditMappingIncomplete([])).toBe(true)
  })

  it("is incomplete when the student has only saved drafts that were never sent", () => {
    expect(
      isCreditMappingIncomplete([{ status: "draft" }, { status: "draft" }]),
    ).toBe(true)
  })

  it("is incomplete while any sent credit is still pending with the Program Chair", () => {
    expect(
      isCreditMappingIncomplete([
        { status: "approved" },
        { status: "pending" },
      ]),
    ).toBe(true)
    expect(
      isCreditMappingIncomplete([
        { status: "endorsed" },
        { status: "pending" },
      ]),
    ).toBe(true)
  })

  it("is complete once the Program Chair has passed every credit to the Registrar Head (ADR 0050)", () => {
    expect(isCreditMappingIncomplete([{ status: "endorsed" }])).toBe(false)
    expect(
      isCreditMappingIncomplete([
        { status: "endorsed" },
        { status: "approved" },
        { status: "rejected" },
      ]),
    ).toBe(false)
  })

  it("is complete once every sent credit is decided, whatever drafts remain", () => {
    expect(
      isCreditMappingIncomplete([
        { status: "approved" },
        { status: "rejected" },
        { status: "draft" },
      ]),
    ).toBe(false)
  })
})

describe("preselectSuggestions", () => {
  const group = (creditId: number, ...subjectIds: number[]) => ({
    credit_id: creditId,
    suggestions: subjectIds.map((subject_id) => ({ subject_id })),
  })

  it("picks the top suggestion for every row that has none saved yet", () => {
    expect(
      preselectSuggestions(
        [
          { id: 1, subject_id: null },
          { id: 2, subject_id: null },
        ],
        [group(1, 10, 11), group(2, 20)],
      ),
    ).toEqual({ 1: 10, 2: 20 })
  })

  it("skips a subject another credit has already saved", () => {
    expect(
      preselectSuggestions(
        [
          { id: 1, subject_id: 10 },
          { id: 2, subject_id: null },
        ],
        [group(1, 10), group(2, 10, 11)],
      ),
    ).toEqual({ 2: 11 })
  })

  it("never picks the same subject for two rows, and leaves a row out when nothing else is left", () => {
    expect(
      preselectSuggestions(
        [
          { id: 1, subject_id: null },
          { id: 2, subject_id: null },
          { id: 3, subject_id: null },
        ],
        [group(1, 10), group(2, 10, 11), group(3, 10)],
      ),
    ).toEqual({ 1: 10, 2: 11 })
  })

  it("leaves a row with a saved subject, or without suggestions, out", () => {
    expect(
      preselectSuggestions(
        [
          { id: 1, subject_id: 30 },
          { id: 2, subject_id: null },
          { id: 3, subject_id: null },
        ],
        [group(1, 31), group(2)],
      ),
    ).toEqual({})
  })
})

describe("groupCreditsByStudent", () => {
  const row = (id: number, studentId: number) => ({
    id,
    student_id: studentId,
    student_name: `Student ${studentId}`,
    student_number: `2026-000${studentId}`,
  })

  it("groups a student's credits together and keeps the order students first appear in", () => {
    const groups = groupCreditsByStudent([row(1, 7), row(2, 5), row(3, 7)])

    expect(groups.map((g) => g.student_id)).toEqual([7, 5])
    expect(groups[0].credits.map((c) => c.id)).toEqual([1, 3])
    expect(groups[0].student_name).toBe("Student 7")
    expect(groups[1].credits.map((c) => c.id)).toEqual([2])
  })

  it("returns nothing for no credits", () => {
    expect(groupCreditsByStudent([])).toEqual([])
  })
})
