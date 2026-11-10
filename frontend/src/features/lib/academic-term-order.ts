interface OrderableTerm {
  id: number
  school_year: string
  semester: string
}

/** Within a school year: the first semester, the second, then a summer term. Anything else sorts first. */
function semesterRank(semester: string): number {
  const value = semester.trim().toLowerCase()
  if (value === "1st") return 1
  if (value === "2nd") return 2
  if (value.includes("summer")) return 3
  return 0
}

/**
 * Terms newest first: by school year, then by semester. A term's id is only the order it was created
 * in (the older school years were added after 2025-2026), so it is never used to order them except
 * to break an exact tie.
 */
export function sortTermsNewestFirst<T extends OrderableTerm>(
  terms: readonly T[],
): T[] {
  return [...terms].sort(
    (left, right) =>
      right.school_year.localeCompare(left.school_year) ||
      semesterRank(right.semester) - semesterRank(left.semester) ||
      right.id - left.id,
  )
}
