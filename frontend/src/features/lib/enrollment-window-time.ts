/**
 * The Registrar picks dates only — enrollment always opens at 8:00 AM and
 * closes at 11:59 PM on the chosen dates, so there is no separate time
 * input to get wrong. These helpers are the single place that convention is
 * encoded; the API contract itself is untouched and still carries full ISO
 * 8601 instants.
 */
export const ENROLLMENT_OPENS_TIME = "08:00"
export const ENROLLMENT_CLOSES_TIME = "23:59"

/** `2028-07-01T00:00:00Z` (or any parseable ISO instant) → `2028-07-01`, in the browser's local time. */
export function toDateInputValue(iso: string | null): string {
  if (!iso) return ""
  const date = new Date(iso)
  const pad = (value: number) => String(value).padStart(2, "0")

  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
}

/** `2028-07-01` → `2028-07-01T08:00`, a local `datetime-local`-shaped string ready for `new Date(...).toISOString()`. */
export function openingInstant(date: string): string {
  return `${date}T${ENROLLMENT_OPENS_TIME}`
}

/** `2028-07-01` → `2028-07-01T23:59`. */
export function closingInstant(date: string): string {
  return `${date}T${ENROLLMENT_CLOSES_TIME}`
}
