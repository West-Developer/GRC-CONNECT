/**
 * Formats a numeric or string year level into the institution-approved ordinal display
 * label, e.g. 1 / "1" / "Year 1" → "1st Year", 2 → "2nd Year", 3 → "3rd Year", 4 → "4th Year".
 *
 * Nulls and zeros are returned as "—" so callers don't need null-guards
 * in every render site.
 */
export function formatYearLevel(
  yearLevel: number | string | null | undefined,
): string {
  if (yearLevel === null || yearLevel === undefined || yearLevel === "") {
    return "—"
  }

  let num: number

  if (typeof yearLevel === "string") {
    const trimmed = yearLevel.trim()
    if (/^[1-4](st|nd|rd|th)\s+Year$/i.test(trimmed)) {
      const parsed = parseInt(trimmed, 10)
      const suffix =
        parsed === 1 ? "st" : parsed === 2 ? "nd" : parsed === 3 ? "rd" : "th"
      return `${parsed}${suffix} Year`
    }
    const match = trimmed.match(/\d+/)
    if (match) {
      num = parseInt(match[0], 10)
    } else {
      return trimmed
    }
  } else {
    num = Number(yearLevel)
  }

  if (!num || isNaN(num) || num <= 0) {
    return "—"
  }

  const suffix =
    num === 1 ? "st" : num === 2 ? "nd" : num === 3 ? "rd" : "th"
  return `${num}${suffix} Year`
}
