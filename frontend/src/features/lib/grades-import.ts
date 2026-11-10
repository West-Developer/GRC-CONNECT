import type { GradeMarkValue } from "@/features/lib/grade-presentation"
import type { SectionGradeSheet } from "@/features/schemas/section-grade-schema"

/**
 * Turns an uploaded grade sheet (CSV or Excel, already read into rows of text) into grade drafts
 * for one section. Two layouts are understood:
 *
 * - the simple template this page downloads: `Student Number, Student Name, Grade, Remarks`;
 * - the school's legacy Grading Sheet (`GradingSheet_..._.xlsx`): a few header lines (`SCHOOL
 *   YEAR`, `TEACHER`, `SEMESTER`, `SCHEDULE ID`, `SUBJECT`), then a header row starting `StudentID,
 *   Student Name, Q1 ...` and ending `... Grade, Numeric Grade, Remarks, Mid Term, Mid Term Numeric,
 *   Semestral Grade, Semester Numeric Grade`. The semester's numeric grade is the final mark.
 *
 * Nothing here talks to the API: the result only fills the page's drafts, which the professor
 * still reviews, saves and submits.
 */

export interface GradeDraft {
  mark: string
  remarks: string
}

export interface ParsedGradesImport {
  drafts: Record<number, GradeDraft>
  /** Students whose mark was read and accepted. */
  matchedCount: number
  /** Marks that are not allowed for this subject (a Drop, a percentage, a typo). */
  invalidCount: number
  /** Rows that are not in this section's class list. */
  unmatchedCount: number
  /** How many students were found by name because their ID did not match. Worth a second look. */
  matchedByNameCount: number
  /** The first few unmatched rows' names, for the message. */
  unmatchedNames: string[]
  /** The `SCHEDULE ID` printed on a legacy Grading Sheet, when there is one. */
  sheetScheduleId: number | null
  /** True when the file was the legacy Grading Sheet layout. */
  isGradingSheet: boolean
}

const EMPTY_RESULT = {
  matchedCount: 0,
  invalidCount: 0,
  unmatchedCount: 0,
  matchedByNameCount: 0,
  unmatchedNames: [] as string[],
  sheetScheduleId: null,
  isGradingSheet: false,
}

function cleanCsvValue(value: string): string {
  let cleaned = value.trim()
  if (cleaned.startsWith('"') && cleaned.endsWith('"') && cleaned.length >= 2) {
    cleaned = cleaned.slice(1, -1).replace(/""/g, '"')
  }
  return cleaned.trim()
}

function parseCsvLine(line: string): string[] {
  const result: string[] = []
  let current = ""
  let inQuotes = false

  for (let i = 0; i < line.length; i++) {
    const char = line[i]
    if (char === '"') {
      if (inQuotes && line[i + 1] === '"') {
        current += '"'
        i++
      } else {
        inQuotes = !inQuotes
      }
    } else if (char === "," && !inQuotes) {
      result.push(cleanCsvValue(current))
      current = ""
    } else {
      current += char
    }
  }
  result.push(cleanCsvValue(current))
  return result
}

/** CSV text as rows of text, skipping blank lines. */
export function csvToRows(csvText: string): string[][] {
  return csvText
    .replace(/^\uFEFF/, "")
    .split(/\r?\n/)
    .filter((line) => line.trim().length > 0)
    .map(parseCsvLine)
}

export function normalizeMark(value: string): string {
  const cleaned = value.trim().toUpperCase()
  if (cleaned === "1" || cleaned === "1.0") return "1.00"
  if (cleaned === "1.2" || cleaned === "1.25") return "1.25"
  if (cleaned === "1.5" || cleaned === "1.50") return "1.50"
  if (cleaned === "1.7" || cleaned === "1.75") return "1.75"
  if (cleaned === "2" || cleaned === "2.0") return "2.00"
  if (cleaned === "2.2" || cleaned === "2.25") return "2.25"
  if (cleaned === "2.5" || cleaned === "2.50") return "2.50"
  if (cleaned === "2.7" || cleaned === "2.75") return "2.75"
  if (cleaned === "3" || cleaned === "3.0" || cleaned === "3.00") return "3.00"
  if (cleaned === "5" || cleaned === "5.0" || cleaned === "5.00") return "5.00"
  if (cleaned === "INC") return "INC"
  if (cleaned === "C") return "C"
  // Excel stores 1.75 as a float and may hand back 1.7500000000000002 or "1.750".
  if (cleaned !== "" && /^\d+(\.\d+)?$/.test(cleaned)) {
    return Number(cleaned).toFixed(2)
  }
  return cleaned
}

const ID_HEADERS = [
  "studentid",
  "student id",
  "student number",
  "student_number",
  "student no",
  "student no.",
]
// Most specific first: the legacy sheet has a percentage `Grade` before its `Numeric Grade`.
const GRADE_HEADERS = [
  "semester numeric grade",
  "semestral numeric grade",
  "final numeric grade",
  "final grade",
  "numeric grade",
  "grade",
  "mark",
]

function findHeaderRow(rows: readonly string[][]): number {
  const limit = Math.min(rows.length, 40)
  for (let i = 0; i < limit; i++) {
    const cells = (rows[i] ?? []).map((cell) => cell.trim().toLowerCase())
    const hasId = cells.some((cell) => ID_HEADERS.includes(cell))
    const hasName = cells.some((cell) => cell === "student name" || cell === "name")
    if (hasId && hasName) return i
  }
  return 0
}

function scheduleIdIn(rows: readonly string[][]): number | null {
  for (const row of rows.slice(0, 15)) {
    for (const cell of row) {
      const match = /schedule\s*id\s*:?\s*(\d+)/i.exec(cell)
      if (match) return Number(match[1])
    }
  }
  return null
}

function nameTokens(value: string): string[] {
  return value
    .normalize("NFD")
    .replace(/[̀-ͯ]/g, "")
    .toLowerCase()
    .replace(/[^a-z\s-]/g, " ")
    .replace(/-/g, " ")
    .split(/\s+/)
    .filter((token) => token.length > 1)
}

/** `LAST, FIRST MIDDLE` against `First M. Last`: the last name and the first given name must both be there. */
function sheetNameMatches(sheetName: string, rowName: string): boolean {
  const comma = sheetName.indexOf(",")
  if (comma === -1) return false
  const last = nameTokens(sheetName.slice(0, comma))
  const given = nameTokens(sheetName.slice(comma + 1))
  if (last.length === 0 || given.length === 0) return false
  const row = new Set(nameTokens(rowName))
  return last.every((token) => row.has(token)) && row.has(given[0] ?? "")
}

function numericSuffix(studentNumber: string): number | null {
  const tail = studentNumber.split("-").pop() ?? ""
  return /^\d+$/.test(tail) ? Number(tail) : null
}

const NOT_A_MARK = new Set(["", "passed", "failed"])

export function parseGradesRows(
  rows: readonly string[][],
  sheet: SectionGradeSheet,
  allowedMarks: readonly GradeMarkValue[],
  existingDrafts: Record<number, GradeDraft> = {},
): ParsedGradesImport {
  const base: ParsedGradesImport = { drafts: existingDrafts, ...EMPTY_RESULT }
  if (rows.length < 2) return base

  const headerRowIndex = findHeaderRow(rows)
  const header = (rows[headerRowIndex] ?? []).map((cell) => cell.trim().toLowerCase())
  const isGradingSheet = header.includes("studentid")
  const sheetScheduleId = isGradingSheet ? scheduleIdIn(rows) : null

  let idIndex = header.findIndex((cell) => ID_HEADERS.includes(cell))
  if (idIndex === -1) {
    idIndex = header.findIndex(
      (cell) =>
        cell.includes("student number") ||
        cell.includes("student_number") ||
        cell.includes("student id"),
    )
  }
  const nameIndex = header.findIndex(
    (cell) => cell === "student name" || cell === "name",
  )
  const remarksIndex = header.findIndex((cell) => cell.includes("remark"))

  // Every grade column the file has, best first; a row uses the first one it filled in.
  const gradeIndexes: number[] = []
  for (const label of GRADE_HEADERS) {
    const index = header.indexOf(label)
    if (index !== -1 && !gradeIndexes.includes(index)) gradeIndexes.push(index)
  }
  if (gradeIndexes.length === 0) {
    const loose = header.findIndex(
      (cell) => cell.includes("grade") || cell.includes("mark"),
    )
    gradeIndexes.push(loose === -1 ? 2 : loose)
  }
  if (idIndex === -1) idIndex = 0

  const byNumber = new Map<string, number>()
  const bySuffix = new Map<number, number[]>()
  for (const [position, row] of sheet.rows.entries()) {
    byNumber.set(row.student_number.toLowerCase(), position)
    const suffix = numericSuffix(row.student_number)
    if (suffix !== null) bySuffix.set(suffix, [...(bySuffix.get(suffix) ?? []), position])
  }

  const drafts = { ...existingDrafts }
  let matchedCount = 0
  let invalidCount = 0
  let unmatchedCount = 0
  let matchedByNameCount = 0
  const unmatchedNames: string[] = []

  for (const cells of rows.slice(headerRowIndex + 1)) {
    const rawId = (cells[idIndex] ?? "").trim()
    // The legacy sheet's HPS / DATE lines and any blank line carry no student.
    if (!rawId || ["hps", "date"].includes(rawId.toLowerCase())) continue

    const sheetName = nameIndex === -1 ? "" : (cells[nameIndex] ?? "").trim()
    let position = byNumber.get(rawId.toLowerCase())
    let byName = false

    if (position === undefined && /^\d+$/.test(rawId)) {
      const candidates = bySuffix.get(Number(rawId)) ?? []
      if (candidates.length === 1) position = candidates[0]
    }
    if (position === undefined && sheetName) {
      const candidates = sheet.rows
        .map((row, index) => ({ row, index }))
        .filter(({ row }) => sheetNameMatches(sheetName, row.student_name))
      if (candidates.length === 1) {
        position = candidates[0]?.index
        byName = true
      }
    }

    const row = position === undefined ? undefined : sheet.rows[position]
    if (!row) {
      unmatchedCount++
      if (unmatchedNames.length < 5) unmatchedNames.push(sheetName || rawId)
      continue
    }

    const rawGrade =
      gradeIndexes
        .map((index) => (cells[index] ?? "").trim())
        .find((value) => value !== "") ?? ""
    const rawRemarks = remarksIndex === -1 ? "" : (cells[remarksIndex] ?? "").trim()
    // The legacy sheet's own Remarks column only says Passed or Failed; that is not a note.
    const remarks = NOT_A_MARK.has(rawRemarks.toLowerCase()) ? "" : rawRemarks

    if (!rawGrade) {
      if (remarks) {
        drafts[row.student_id] = {
          mark: drafts[row.student_id]?.mark ?? row.mark ?? "",
          remarks,
        }
      }
      continue
    }

    const normalized = normalizeMark(rawGrade)
    if (allowedMarks.includes(normalized as GradeMarkValue)) {
      drafts[row.student_id] = {
        mark: normalized,
        remarks: remarks || (drafts[row.student_id]?.remarks ?? row.remarks ?? ""),
      }
      matchedCount++
      if (byName) matchedByNameCount++
    } else {
      invalidCount++
    }
  }

  return {
    drafts,
    matchedCount,
    invalidCount,
    unmatchedCount,
    matchedByNameCount,
    unmatchedNames,
    sheetScheduleId,
    isGradingSheet,
  }
}
