export interface ScheduleSlot {
  schedule_days: string | null
  starts_at_time: string | null
  ends_at_time: string | null
}

/**
 * Longest tokens first so "Th"/"Sat"/"Sun" are not swallowed by the
 * single-letter "T"/"S" checks — mirrors the backend's
 * `App\Domain\Scheduling\ScheduleDayParser` token table and its ISO-8601
 * numbering (1 = Monday … 7 = Sunday).
 */
const DAY_TOKENS: readonly (readonly [string, number])[] = [
  ["MON", 1],
  ["TUE", 2],
  ["WED", 3],
  ["THU", 4],
  ["FRI", 5],
  ["SAT", 6],
  ["SUN", 7],
  ["Th", 4],
  ["Sat", 6],
  ["Sun", 7],
  ["M", 1],
  ["T", 2],
  ["W", 3],
  ["F", 5],
  ["S", 6],
]

/**
 * Segments delimited the same way the backend's `CanonicalScheduleDays`
 * joins its output (space, comma, slash, semicolon, ampersand, pipe, hyphen) are
 * parsed independently. Without this split, the API's real "MON/TUE/WED"
 * response would match only "M" from the first segment, then abort on the
 * un-tokenizable "ON/TUE/WED" left over — silently dropping every day after
 * the first. Matching is case-insensitive so the canonical all-caps "THU"
 * form and the legacy mixed-case "Th" shorthand both resolve, mirroring
 * `App\Domain\Scheduling\ScheduleDayParser`'s own `strtoupper()` + per-segment
 * loop.
 */
export function parseScheduleDays(scheduleDays: string | null): number[] {
  if (scheduleDays === null) return []

  const days: number[] = []
  const segments = scheduleDays
    .split(/[\s,/;&|–-]+/)
    .filter((segment) => segment !== "")

  for (const segment of segments) {
    let remaining = segment.toUpperCase()

    while (remaining !== "") {
      const token = DAY_TOKENS.find(([symbol]) =>
        remaining.startsWith(symbol.toUpperCase()),
      )
      if (!token) break
      days.push(token[1])
      remaining = remaining.slice(token[0].length)
    }
  }

  return [...new Set(days)]
}

function isScheduled(slot: ScheduleSlot): boolean {
  return (
    slot.schedule_days !== null &&
    slot.starts_at_time !== null &&
    slot.ends_at_time !== null
  )
}

/**
 * Half-open interval overlap: [start, end) vs [start, end). Two slots that
 * merely touch at the boundary (one ends exactly when the next starts) do
 * not conflict — matches the backend's `SectionConflictDetector`.
 */
function timesOverlap(a: ScheduleSlot, b: ScheduleSlot): boolean {
  // Callers only reach this after isScheduled() confirms both slots'
  // times are non-null.
  return (
    a.starts_at_time! < b.ends_at_time! && b.starts_at_time! < a.ends_at_time!
  )
}

/**
 * A same-day, overlapping-time conflict between two slots — the same rule
 * `App\Domain\Scheduling\SectionConflictDetector` enforces server-side at
 * submission. Checked client-side too so a student sees the conflict while
 * still picking sections, not only after submitting.
 */
export function hasScheduleConflict(a: ScheduleSlot, b: ScheduleSlot): boolean {
  if (!isScheduled(a) || !isScheduled(b)) return false

  const sharedDays = parseScheduleDays(a.schedule_days).filter((day) =>
    parseScheduleDays(b.schedule_days).includes(day),
  )

  return sharedDays.length > 0 && timesOverlap(a, b)
}

/** Earliest weekday (Monday=1..Saturday=6) a slot meets on; Sunday and unscheduled slots sort last. */
function earliestWeekday(slot: ScheduleSlot): number {
  const days = parseScheduleDays(slot.schedule_days).filter(
    (day) => day >= 1 && day <= 6,
  )
  return days.length > 0 ? Math.min(...days) : 7
}

/**
 * Chronological ordering — Monday's earliest class first, then Tuesday, …
 * through Saturday, with an unscheduled (or Sunday) slot sorting last.
 */
export function compareBySchedule(a: ScheduleSlot, b: ScheduleSlot): number {
  const dayDelta = earliestWeekday(a) - earliestWeekday(b)
  if (dayDelta !== 0) return dayDelta

  return (a.starts_at_time ?? "99:99:99").localeCompare(
    b.starts_at_time ?? "99:99:99",
  )
}
