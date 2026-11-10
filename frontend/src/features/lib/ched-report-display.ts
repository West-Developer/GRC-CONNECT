import type {
  ChedReportKey,
  ChedReportRow,
} from "@/features/schemas/ched-report-schema"

export interface ChedSubject {
  title: string
  units: string
}

/**
 * The CHED list writes a student's subjects as one text, `Title (units), Title (units)`. A title can
 * itself hold commas ("Science, Technology, and Society") or brackets, so the text is cut where a
 * `(number)` is followed by a comma or the end, not at every comma.
 */
export function parseChedSubjects(text: string): ChedSubject[] {
  const trimmed = text.trim()
  if (trimmed === "") return []

  const subjects: ChedSubject[] = []
  const pattern = /(.+?) \((\d+(?:\.\d+)?)\)(?:, |$)/gy
  let consumed = 0
  let match: RegExpExecArray | null

  while ((match = pattern.exec(trimmed)) !== null) {
    subjects.push({ title: match[1], units: match[2] })
    consumed = pattern.lastIndex
    if (consumed >= trimmed.length) break
  }

  // Text that does not follow the shape is shown whole rather than dropped.
  if (consumed < trimmed.length) {
    subjects.push({
      title: trimmed.slice(consumed).replace(/^, /, ""),
      units: "",
    })
  }

  return subjects
}

/** "Surname, First M. Ext." from the row's name columns; whatever is empty is left out. */
export function chedStudentName(row: ChedReportRow): string {
  const text = (key: string) => String(row[key] ?? "").trim()
  const given = [
    text("first_name"),
    text("middle_name"),
    text("name_extension"),
  ]
    .filter((part) => part !== "")
    .join(" ")
  const surname = text("surname")

  if (surname !== "" && given !== "") return `${surname}, ${given}`
  return surname || given || "Student"
}

/**
 * NSTP 1 is taken in the first semester and NSTP 2 in the second, so the NSTP enrollment list is
 * "NSTP 1 Enrollment" or "NSTP 2 Enrollment" by the term it is for. Any other term, or no term yet,
 * is plain "NSTP".
 */
export function nstpSubjectOf(semester: string | null): string {
  switch (semester?.trim().toLowerCase()) {
    case "1st":
      return "NSTP 1"
    case "2nd":
      return "NSTP 2"
    default:
      return "NSTP"
  }
}

/** The report's name; the same text the API sends as `report.title`. */
export function chedReportTitle(
  report: ChedReportKey,
  semester: string | null,
): string {
  switch (report) {
    case "enrollment-list":
      return "CHED Enrollment List"
    case "nstp-enrollment":
      return `${nstpSubjectOf(semester)} Enrollment (Form 2-B)`
    case "nstp-graduates":
      return "NSTP 2 Graduates (Form 2-B)"
    case "nstp-summary":
      return "NSTP Summary (Form 2-A)"
  }
}

/** The short label on the report's tab. */
export function chedTabLabel(
  report: ChedReportKey,
  semester: string | null,
): string {
  switch (report) {
    case "enrollment-list":
      return "Enrollment List"
    case "nstp-enrollment":
      return `${nstpSubjectOf(semester)} Enrollment`
    case "nstp-graduates":
      return "NSTP 2 Graduates"
    case "nstp-summary":
      return "Form 2-A Summary"
  }
}
