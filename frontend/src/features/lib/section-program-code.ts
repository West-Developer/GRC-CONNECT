/**
 * Derives a program grouping key from a block section code (e.g. "EN101" →
 * "EN", "HR403" → "HR"). Section codes carry no separate program field, so
 * every screen that groups or filters sections by program parses the same
 * leading-letters convention through this single helper.
 */
export function programCodeFromSection(sectionCode: string): string {
  return /^[A-Za-z]+/.exec(sectionCode)?.[0].toUpperCase() ?? sectionCode
}
