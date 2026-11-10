/**
 * Formats a `HH:MM` / `HH:MM:SS` 24-hour clock string (the shape every
 * `starts_at_time`/`ends_at_time` field arrives in from the API) as a
 * 12-hour clock with an AM/PM marker — plain string math, no `Date`
 * parsing, so it can't drift with the viewer's timezone.
 */
export function formatClockTime(value: string): string {
  const [hourPart, minutePart] = value.split(":")
  const hour24 = Number(hourPart)
  const period = hour24 < 12 ? "AM" : "PM"
  const hour12 = hour24 % 12 === 0 ? 12 : hour24 % 12

  return `${hour12}:${minutePart} ${period}`
}

/**
 * Each side of the range carries its own AM/PM marker rather than sharing
 * one at the end — a range that crosses noon (e.g. 11:30 AM–1:30 PM) would
 * otherwise read as if both sides were PM.
 */
export function formatTimeRange(start: string, end: string): string {
  return `${formatClockTime(start)}–${formatClockTime(end)}`
}
