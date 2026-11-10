const MANILA_TIME_ZONE = "Asia/Manila"

/**
 * Formats an ISO timestamp as the institution's wall-clock time (Asia/Manila),
 * explicitly — not the viewer's browser timezone via `toLocaleString()`. The
 * server-generated COR PDF formats the same `generated_at` value through
 * `CorDisplay::generatedAt()` with the same explicit Asia/Manila conversion,
 * so the two always read the same "Generated" time (stakeholder Doc 16 found
 * them 8 hours apart — a UTC vs. Asia/Manila mismatch between the two
 * renderers, not a data bug).
 */
export function formatGeneratedAt(value: string): string {
  return new Intl.DateTimeFormat("en-US", {
    timeZone: MANILA_TIME_ZONE,
    year: "numeric",
    month: "numeric",
    day: "numeric",
    hour: "numeric",
    minute: "2-digit",
    second: "2-digit",
    hour12: true,
  }).format(new Date(value))
}

/**
 * `COR_{student name}_{date}.pdf` — mirrors `CorDisplay::downloadFilename()`
 * on the backend exactly, so a student recognizes the file as theirs instead
 * of a bare document number (stakeholder Doc 16).
 */
export function corDownloadFilename(
  studentName: string,
  generatedAt: string,
): string {
  const slug = studentName
    .trim()
    .replace(/[^A-Za-z0-9]+/g, "_")
    .replace(/^_+|_+$/g, "")
  const date = new Intl.DateTimeFormat("en-CA", {
    timeZone: MANILA_TIME_ZONE,
  }).format(new Date(generatedAt))

  return `COR_${slug}_${date}.pdf`
}
