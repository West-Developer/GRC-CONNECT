import { describe, expect, it } from "vitest"

import {
  csvToRows,
  normalizeMark,
  parseGradesRows,
} from "@/features/lib/grades-import"
import type { GradeMarkValue } from "@/features/lib/grade-presentation"
import type { SectionGradeSheet } from "@/features/schemas/section-grade-schema"

const marks: readonly GradeMarkValue[] = [
  "1.00",
  "1.25",
  "1.50",
  "1.75",
  "2.00",
  "2.25",
  "2.50",
  "2.75",
  "3.00",
  "5.00",
  "INC",
]

function row(
  studentId: number,
  studentNumber: string,
  studentName: string,
  mark: string | null = null,
) {
  return {
    enrollment_subject_id: studentId,
    student_id: studentId,
    student_number: studentNumber,
    student_name: studentName,
    grade_id: null,
    mark,
    mark_label: null,
    remarks: null,
    status: "not_recorded",
    status_label: "Not recorded",
  }
}

const sheet = {
  type: "section_grade_sheet",
  section: {},
  rows: [
    row(1, "2026-08-21204", "Andrin M. Alejandro"),
    row(2, "2026-08-21014", "Chernelyn M. Aruta"),
    row(3, "2026-08-20957", "Jea-Ann E. Baure"),
    row(4, "2026-08-21291", "Eli P. Billante"),
  ],
} as unknown as SectionGradeSheet

/** The legacy Grading Sheet's shape: header lines, a header row, HPS and DATE lines, then students. */
function gradingSheet(
  students: readonly string[][],
  scheduleId = "41105",
): string[][] {
  const header = [
    "StudentID",
    "Student Name",
    "Q1",
    "Grade",
    "Numeric Grade",
    "Remarks",
    "Mid Term",
    "Mid Term Numeric",
    "Semestral Grade",
    "Semester Numeric Grade",
  ]
  return [
    ["Global Reciprocal Colleges "],
    ["GRADING SHEET"],
    ["SCHOOL YEAR: 2026-2027"],
    ["TEACHER: SANGCO, MICHAEL DAVE PEREZ "],
    ["SEMESTER: First Semester"],
    [`SCHEDULE ID: ${scheduleId} 01:30 PM-04:30 PM Tue`],
    [],
    ["SUBJECT: Rizal's Life & Works"],
    [],
    header,
    [],
    ["", "HPS", "0"],
    ["", "DATE"],
    [],
    ...students,
  ]
}

describe("parseGradesRows: the simple template", () => {
  it("matches by student number and keeps remarks", () => {
    const rows = csvToRows(
      [
        "Student Number,Student Name,Grade,Remarks",
        '"2026-08-21204","Alejandro, Andrin",1.25,"Excellent"',
        '"2026-08-99999","Nobody",2.00,""',
      ].join("\n"),
    )

    const result = parseGradesRows(rows, sheet, marks)

    expect(result.matchedCount).toBe(1)
    expect(result.drafts[1]).toEqual({ mark: "1.25", remarks: "Excellent" })
    expect(result.unmatchedCount).toBe(1)
    expect(result.isGradingSheet).toBe(false)
  })
})

describe("parseGradesRows: the legacy Grading Sheet", () => {
  it("reads the Semester Numeric Grade for each student and skips the HPS and DATE lines", () => {
    const rows = gradingSheet([
      ["21204", "ALEJANDRO, ANDRIN JAMES MARTINEZ", "", "", "", "Passed", "", "", "", "1.75"],
      ["21014", "ARUTA, CHERNELYN MOBIDO", "", "", "", "", "", "", "", "2.5"],
    ])

    const result = parseGradesRows(rows, sheet, marks)

    expect(result.isGradingSheet).toBe(true)
    expect(result.sheetScheduleId).toBe(41105)
    expect(result.matchedCount).toBe(2)
    // The sheet's own Passed/Failed remark is not a teacher's note.
    expect(result.drafts[1]).toEqual({ mark: "1.75", remarks: "" })
    expect(result.drafts[2]).toEqual({ mark: "2.50", remarks: "" })
    expect(result.unmatchedCount).toBe(0)
  })

  it("falls back to the Numeric Grade when a row has no Semester Numeric Grade", () => {
    const rows = gradingSheet([
      ["21204", "ALEJANDRO, ANDRIN JAMES MARTINEZ", "", "88.5", "2.00", "", "", "", "", ""],
      ["21014", "ARUTA, CHERNELYN MOBIDO", "", "", "3", "", "", "", "", "1.50"],
    ])

    const result = parseGradesRows(rows, sheet, marks)

    // The percentage `Grade` column is never used while a numeric grade exists.
    expect(result.drafts[1]?.mark).toBe("2.00")
    expect(result.drafts[2]?.mark).toBe("1.50")
  })

  it("finds a student by name when the legacy ID is not their student number", () => {
    const rows = gradingSheet([
      ["77777", "BAURE, JEA-ANN ESCOL", "", "", "", "", "", "", "", "2.25"],
    ])

    const result = parseGradesRows(rows, sheet, marks)

    expect(result.drafts[3]?.mark).toBe("2.25")
    expect(result.matchedByNameCount).toBe(1)
  })

  it("does not guess when two students could share a name", () => {
    const twins = {
      ...sheet,
      rows: [
        row(5, "2026-08-10001", "Maria L. Santos"),
        row(6, "2026-08-10002", "Maria S. Santos"),
      ],
    } as unknown as SectionGradeSheet
    const rows = gradingSheet([
      ["55555", "SANTOS, MARIA LUISA", "", "", "", "", "", "", "", "1.50"],
    ])

    const result = parseGradesRows(rows, twins, marks)

    expect(result.matchedCount).toBe(0)
    expect(result.unmatchedCount).toBe(1)
    expect(result.unmatchedNames).toEqual(["SANTOS, MARIA LUISA"])
  })

  it("skips students who are not in this class and marks that are not allowed", () => {
    const rows = gradingSheet([
      ["21204", "ALEJANDRO, ANDRIN JAMES MARTINEZ", "", "", "", "", "", "", "", "Drop"],
      ["21014", "ARUTA, CHERNELYN MOBIDO", "", "", "", "", "", "", "", "87"],
      ["12345", "STRANGER, SOMEONE ELSE", "", "", "", "", "", "", "", "1.00"],
      ["21291", "BILLANTE, ELI PALMORES", "", "", "", "", "", "", "", "1.25"],
    ])

    const result = parseGradesRows(rows, sheet, marks)

    expect(result.matchedCount).toBe(1)
    expect(result.drafts[4]?.mark).toBe("1.25")
    // A Drop and a percentage are not marks a professor may submit.
    expect(result.invalidCount).toBe(2)
    expect(result.unmatchedCount).toBe(1)
  })

  it("leaves a student alone when no final grade is filled in yet", () => {
    const rows = gradingSheet([
      ["21204", "ALEJANDRO, ANDRIN JAMES MARTINEZ", "1", "38", "", "", "", "", "", ""],
    ])

    const result = parseGradesRows(rows, sheet, marks)

    expect(result.matchedCount).toBe(0)
    expect(result.drafts).toEqual({})
  })

  it("reports the schedule id the sheet was made for", () => {
    const rows = gradingSheet(
      [["21204", "ALEJANDRO, ANDRIN JAMES MARTINEZ", "", "", "", "", "", "", "", "1.00"]],
      "99999",
    )

    expect(parseGradesRows(rows, sheet, marks).sheetScheduleId).toBe(99999)
  })

  it("keeps the drafts the page already had for other students", () => {
    const rows = gradingSheet([
      ["21204", "ALEJANDRO, ANDRIN JAMES MARTINEZ", "", "", "", "", "", "", "", "1.00"],
    ])

    const result = parseGradesRows(rows, sheet, marks, {
      4: { mark: "3.00", remarks: "kept" },
    })

    expect(result.drafts[4]).toEqual({ mark: "3.00", remarks: "kept" })
    expect(result.drafts[1]?.mark).toBe("1.00")
  })
})

describe("normalizeMark", () => {
  it.each([
    ["1", "1.00"],
    ["1.7", "1.75"],
    ["2.2", "2.25"],
    ["5", "5.00"],
    ["1.7500000000000002", "1.75"],
    ["2.500", "2.50"],
    ["inc", "INC"],
    ["c", "C"],
  ])("turns %s into %s", (input, expected) => {
    expect(normalizeMark(input)).toBe(expected)
  })

  it("leaves text that is not a mark for the allowed-marks check to refuse", () => {
    expect(normalizeMark("Drop")).toBe("DROP")
    expect(normalizeMark("87")).toBe("87.00")
  })
})
