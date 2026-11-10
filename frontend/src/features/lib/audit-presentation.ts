/**
 * Wording for the Registrar Head's audit screen. The server keeps stable
 * machine values (`section.updated`, `enrollment_subject_waiver`); this turns
 * them into short readable phrases and never guesses beyond that.
 */

function sentence(value: string): string {
  const words = value.replace(/[._]+/gu, " ").trim()
  return words.charAt(0).toUpperCase() + words.slice(1)
}

/** "section.updated" -> "Section updated". */
export function humanizeAuditAction(action: string): string {
  return sentence(action)
}

/** "section" + 3 -> "Section #3"; without an id just the type. */
export function humanizeAuditTarget(
  auditableType: string,
  auditableId: number | null,
): string {
  const label = sentence(auditableType)
  return auditableId === null ? label : `${label} #${auditableId}`
}

/** One side of a field comparison. Empty is shown as an em dash. */
export function formatAuditValue(
  value: string | number | boolean | null,
): string {
  if (value === null || value === "") return "—"
  if (value === true) return "Yes"
  if (value === false) return "No"
  return String(value)
}

/** "2026-07-29T12:00:00Z" as a local, readable date and time. */
export function formatAuditTimestamp(value: string | null): string {
  if (value === null) return "Time unavailable"
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return "Time unavailable"
  return date.toLocaleString(undefined, {
    dateStyle: "medium",
    timeStyle: "short",
  })
}
