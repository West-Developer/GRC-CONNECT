/**
 * Keeps a lecture and its paired laboratory next to each other in a list
 * (stakeholder Doc 14: they are always taken together and meet back-to-back,
 * so the picker should show them together whatever the sort order).
 *
 * Each row stays where it was, except that a row's partner, when it is also in
 * the list, is pulled up to sit directly after it. A row whose partner is not
 * in the list is left alone. The input is not modified.
 */
export type SubjectPairCandidate = {
  subject_id?: number
  academic_grade_id?: number
  paired_subject_id?: number | null
  code?: string
  subject_code?: string
  title?: string
  subject_title?: string
  room_requirement?: string | null
}

/**
 * Returns true if candidate `a` is the laboratory component of a paired subject
 * when compared against its partner `b`.
 */
export function isLabPartner(
  a: SubjectPairCandidate,
  b: SubjectPairCandidate,
): boolean {
  const aCode = (a.code ?? a.subject_code ?? "").trim().toUpperCase()
  const bCode = (b.code ?? b.subject_code ?? "").trim().toUpperCase()

  if (bCode && aCode === `${bCode}L`) return true
  if (aCode && bCode === `${aCode}L`) return false

  if (
    a.room_requirement === "laboratory" &&
    b.room_requirement === "lecture"
  ) {
    return true
  }
  if (
    a.room_requirement === "lecture" &&
    b.room_requirement === "laboratory"
  ) {
    return false
  }

  const aTitle = (a.title ?? a.subject_title ?? "").trim().toUpperCase()
  const bTitle = (b.title ?? b.subject_title ?? "").trim().toUpperCase()

  const aIsLabTitle = /\b(LAB|LABORATORY)\b/.test(aTitle)
  const bIsLabTitle = /\b(LAB|LABORATORY)\b/.test(bTitle)
  if (aIsLabTitle && !bIsLabTitle) return true
  if (!aIsLabTitle && bIsLabTitle) return false

  if (aCode.endsWith("L") && !bCode.endsWith("L")) return true
  if (!aCode.endsWith("L") && bCode.endsWith("L")) return false

  return false
}

/**
 * Keeps a lecture and its paired laboratory next to each other in a list
 * (stakeholder Doc 14 & Doc 18: they are always taken together, and the
 * Lecture component must always appear first, immediately followed by
 * the Laboratory component).
 *
 * Each row stays where it was, except that a row and its partner are grouped
 * adjacent with Lecture first. A row whose partner is not in the list is left
 * alone. The input is not modified.
 */
export function groupPairedSubjects<T extends SubjectPairCandidate>(
  rows: readonly T[],
): T[] {
  const bySubjectId = new Map<number, T>()
  const byCode = new Map<string, T>()

  for (const row of rows) {
    if (row.subject_id !== undefined) {
      bySubjectId.set(row.subject_id, row)
    }
    const code = (row.code ?? row.subject_code ?? "").trim().toUpperCase()
    if (code) {
      byCode.set(code, row)
    }
  }

  const placed = new Set<string | number>()
  const grouped: T[] = []

  const keyOf = (r: T, idx: number): string | number =>
    r.subject_id ??
    r.academic_grade_id ??
    (r.code ?? r.subject_code ? `code:${(r.code ?? r.subject_code)!.toUpperCase()}` : `idx:${idx}`)

  for (let i = 0; i < rows.length; i++) {
    const row = rows[i]
    const rowKey = keyOf(row, i)
    if (placed.has(rowKey)) continue

    let partner: T | undefined
    if (row.paired_subject_id != null) {
      partner = bySubjectId.get(row.paired_subject_id)
    }

    if (!partner) {
      const code = (row.code ?? row.subject_code ?? "").trim().toUpperCase()
      if (code) {
        if (code.endsWith("L")) {
          partner = byCode.get(code.slice(0, -1))
        } else {
          partner = byCode.get(`${code}L`)
        }
      }
    }

    if (partner) {
      const partnerIdx = rows.indexOf(partner)
      const partnerKey = keyOf(partner, partnerIdx >= 0 ? partnerIdx : i)

      if (!placed.has(partnerKey)) {
        if (isLabPartner(row, partner)) {
          // row is Lab, partner is Lecture: place Lecture first, then Lab
          grouped.push(partner)
          placed.add(partnerKey)
          grouped.push(row)
          placed.add(rowKey)
        } else {
          // row is Lecture, partner is Lab: place Lecture first, then Lab
          grouped.push(row)
          placed.add(rowKey)
          grouped.push(partner)
          placed.add(partnerKey)
        }
        continue
      }
    }

    grouped.push(row)
    placed.add(rowKey)
  }

  return grouped
}
