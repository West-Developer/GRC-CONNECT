/**
 * Curriculum-slot ordinal — the same "which semester, counting from the
 * start of the curriculum" numbering the backend's
 * `ClassifyEnrollmentStanding`/`SemesterSlot::ordinal()` uses (a year-Y
 * student has `(Y-1)*2 + 1` completed ordinals). Recomputed here rather than
 * fetched, since every input (a subject's own placement, and the student's
 * own year level + the term being enrolled into) is already on hand
 * client-side.
 */
export function semesterOrdinal(
  yearLevel: number,
  semester: string,
): number {
  return (yearLevel - 1) * 2 + (semester === "1st" ? 1 : 2)
}

/**
 * A subject is "backlog" — still owed from an already-passed semester —
 * when its own curriculum placement ordinal is strictly earlier than the
 * student's current standing. Only meaningful for a subject already known
 * to be eligible (not yet passed, prerequisites met); this function doesn't
 * check that on its own.
 */
export function isBacklogSubject(
  subjectYearLevel: number,
  subjectSemester: string,
  currentYearLevel: number,
  currentSemester: string,
): boolean {
  return (
    semesterOrdinal(subjectYearLevel, subjectSemester) <
    semesterOrdinal(currentYearLevel, currentSemester)
  )
}

/**
 * A subject is "advance" — offered one year ahead of the student's current
 * standing, same semester slot — when its ordinal is exactly two more than
 * the student's current ordinal (a year has two semesters). Mirrors the
 * backend's `BuildEligibleSubjectPool` window of exactly this same +2 slot;
 * an irregular student's pool contains nothing two or more years ahead, so
 * there is no need to check further than +2 here either.
 */
export function isAdvanceSubject(
  subjectYearLevel: number,
  subjectSemester: string,
  currentYearLevel: number,
  currentSemester: string,
): boolean {
  return (
    semesterOrdinal(subjectYearLevel, subjectSemester) ===
    semesterOrdinal(currentYearLevel, currentSemester) + 2
  )
}

/**
 * Format a student's year level integer (1..4) as the institutional ordinal string
 * (e.g. 1 -> "1ST YEAR", 2 -> "2ND YEAR").
 */
export function formatYearLevelOrdinal(
  year: number | null | undefined,
): string {
  if (!year) return "—"
  switch (year) {
    case 1:
      return "1ST YEAR"
    case 2:
      return "2ND YEAR"
    case 3:
      return "3RD YEAR"
    case 4:
      return "4TH YEAR"
    default:
      return `YEAR ${year}`
  }
}
