import type { FacultyLoadLimitSource } from "@/features/schemas/schedule-generation-schema"

/**
 * Where a professor's maximum load comes from, in words (ADR 0033). The
 * employment-type label ("Full-time") is the server's own so the wording stays
 * in one place.
 */
export function limitSourceLabel(
  source: FacultyLoadLimitSource | null,
  employmentTypeLabel: string | null,
): string {
  switch (source) {
    case "override":
      return "Own max load"
    case "employment_type":
      return `${employmentTypeLabel ?? "Employment type"} limit`
    case "college_default":
      return "College default"
    default:
      return "No limit set"
  }
}

/** "9 of 12 units", or "9 units" when there is no limit to compare with. */
export function loadSummary(
  totalUnits: number,
  maxUnits: number | null,
): string {
  return maxUnits === null
    ? `${totalUnits} units`
    : `${totalUnits} of ${maxUnits} units`
}

export type LoadStatus = "over" | "at" | "within" | "none"

/** Where a professor stands against the maximum that applies to them. */
export function loadStatus(
  totalUnits: number,
  maxUnits: number | null,
): LoadStatus {
  if (maxUnits === null) return "none"
  if (totalUnits > maxUnits) return "over"
  if (totalUnits === maxUnits) return "at"
  return "within"
}

export const loadStatusLabel: Record<LoadStatus, string> = {
  over: "Over limit",
  at: "At limit",
  within: "Within limit",
  none: "No limit set",
}
