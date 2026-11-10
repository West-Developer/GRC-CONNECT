import { parseScheduleDays } from "@/features/lib/schedule-order"
import type { Subject } from "@/features/schemas/reference-data-schema"

/** Grid bounds for the room weekly calendar: 7:30 AM to 9:00 PM, Mon–Sat. */
export const DAY_START_MINUTES = 7 * 60 + 30
export const DAY_END_MINUTES = 21 * 60
export const SLOT_MINUTES = 30
export const SLOT_COUNT = (DAY_END_MINUTES - DAY_START_MINUTES) / SLOT_MINUTES
export const CALENDAR_DAYS = [1, 2, 3, 4, 5, 6] as const

/**
 * Side-by-side lanes stay legible up to this many overlapping bookings — a
 * shared room can otherwise collect a whole cluster of same-time bookings
 * across colleges, which would slice each lane down to a sliver too narrow
 * to read. Above this count the whole cluster collapses into one summary
 * chip instead (see `RoomCalendarCluster`).
 */
export const MAX_INLINE_LANES = 2

export interface RoomCalendarSlot {
  schedule_days: string | null
  starts_at_time: string | null
  ends_at_time: string | null
}

export const modalityLabel: Record<"hyflex_a" | "hyflex_b" | "f2f", string> = {
  hyflex_a: "HyFlex A",
  hyflex_b: "HyFlex B",
  f2f: "F2F",
}

export interface RoomConflictSlot extends RoomCalendarSlot {
  modality: "hyflex_a" | "hyflex_b" | "f2f" | null
}

const PHYSICAL_WEEK_MODALITIES = new Set(["f2f", "hyflex_a", "hyflex_b"])

function isComplementaryHyflexPattern(a: string | null, b: string | null): boolean {
  return (
    (a === "hyflex_a" && b === "hyflex_b") || (a === "hyflex_b" && b === "hyflex_a")
  )
}

/**
 * Mirrors the backend's `App\Domain\Scheduling\RoomConflictDetector`: a
 * HyFlex A/B pair legitimately shares a room and time (complementary
 * alternating weeks), so two bookings only conflict when their days and
 * times overlap AND they are not that complementary pair.
 */
export function hasRoomConflict(a: RoomConflictSlot, b: RoomConflictSlot): boolean {
  if (a.schedule_days === null || a.starts_at_time === null || a.ends_at_time === null)
    return false
  if (b.schedule_days === null || b.starts_at_time === null || b.ends_at_time === null)
    return false
  if (a.modality === null || !PHYSICAL_WEEK_MODALITIES.has(a.modality)) return false
  if (b.modality === null || !PHYSICAL_WEEK_MODALITIES.has(b.modality)) return false

  const sharedDays = parseScheduleDays(a.schedule_days).filter((day) =>
    parseScheduleDays(b.schedule_days).includes(day),
  )
  if (sharedDays.length === 0) return false

  const overlaps = a.starts_at_time < b.ends_at_time && b.starts_at_time < a.ends_at_time
  if (!overlaps) return false

  return !isComplementaryHyflexPattern(a.modality, b.modality)
}

/**
 * Every entry with at least one real (non-HyFlex-complementary) conflict
 * against another entry in the list — lets the UI flag a genuine
 * double-booking instead of every merely-overlapping entry.
 */
export function findConflictingIds<T extends RoomConflictSlot>(
  entries: readonly T[],
  idOf: (entry: T) => number,
): Set<number> {
  const conflicting = new Set<number>()
  for (let i = 0; i < entries.length; i += 1) {
    for (let j = i + 1; j < entries.length; j += 1) {
      if (hasRoomConflict(entries[i], entries[j])) {
        conflicting.add(idOf(entries[i]))
        conflicting.add(idOf(entries[j]))
      }
    }
  }
  return conflicting
}

interface RoomCalendarPlacementBase {
  day: number
  startSlot: number
  spanSlots: number
}

export interface RoomCalendarBlock<T extends RoomCalendarSlot>
  extends RoomCalendarPlacementBase {
  kind: "block"
  entry: T
  lane: number
  laneCount: number
  /** True when the booking starts before 7:30 AM or ends after 9:00 PM — clamped into the grid rather than dropped. */
  isClipped: boolean
}

/** A collapsed group of `> MAX_INLINE_LANES` overlapping bookings — rendered as one "N bookings" chip. */
export interface RoomCalendarCluster<T extends RoomCalendarSlot>
  extends RoomCalendarPlacementBase {
  kind: "cluster"
  entries: T[]
}

export type RoomCalendarPlacement<T extends RoomCalendarSlot> =
  | RoomCalendarBlock<T>
  | RoomCalendarCluster<T>

interface RawBlock<T extends RoomCalendarSlot> extends RoomCalendarPlacementBase {
  entry: T
  lane: number
  laneCount: number
  isClipped: boolean
}

export function toMinutes(time: string): number {
  const [hours, minutes] = time.split(":").map(Number)
  return hours * 60 + minutes
}

/** "7:30 AM" for the grid's left-hand time column. */
export function slotLabel(index: number): string {
  const minutes = DAY_START_MINUTES + index * SLOT_MINUTES
  const hour24 = Math.floor(minutes / 60)
  const minute = minutes % 60
  const period = hour24 >= 12 ? "PM" : "AM"
  const hour12 = hour24 % 12 === 0 ? 12 : hour24 % 12
  return `${hour12}:${String(minute).padStart(2, "0")} ${period}`
}

/** "07:30" (24-hour) for prefilling a `type="time"` input from a clicked slot. */
export function slotClockTime(index: number): string {
  const minutes = DAY_START_MINUTES + index * SLOT_MINUTES
  const hour24 = Math.floor(minutes / 60)
  const minute = minutes % 60
  return `${String(hour24).padStart(2, "0")}:${String(minute).padStart(2, "0")}`
}

/** "13:30:00" -> "1:30 PM" — every displayed booking time is 12-hour, never military time. */
export function formatClockTime12(time: string): string {
  const [hourPart, minutePart] = time.split(":")
  const hour24 = Number(hourPart)
  const minute = Number(minutePart)
  const period = hour24 >= 12 ? "PM" : "AM"
  const hour12 = hour24 % 12 === 0 ? 12 : hour24 % 12
  return `${hour12}:${String(minute).padStart(2, "0")} ${period}`
}

/** "13:30:00", "16:30:00" -> "1:30 PM–4:30 PM". */
export function formatTimeRange12(start: string, end: string): string {
  return `${formatClockTime12(start)}–${formatClockTime12(end)}`
}

function buildOverlapClusters<T extends RoomCalendarSlot>(
  sortedByStart: readonly RawBlock<T>[],
): RawBlock<T>[][] {
  const clusters: RawBlock<T>[][] = []
  let current: RawBlock<T>[] = []
  let currentEnd = -Infinity

  for (const block of sortedByStart) {
    if (current.length > 0 && block.startSlot >= currentEnd) {
      clusters.push(current)
      current = []
      currentEnd = -Infinity
    }
    current.push(block)
    currentEnd = Math.max(currentEnd, block.startSlot + block.spanSlots)
  }
  if (current.length > 0) clusters.push(current)

  return clusters
}

/**
 * Assigns each overlapping block a side-by-side lane rather than hiding one —
 * two colleges legitimately sharing a room slot, or a HyFlex A/B pair, both
 * need to stay visible. Returns the overlap clusters so the caller can
 * decide, per cluster, whether to keep individual lanes or collapse them.
 */
function assignLanes<T extends RoomCalendarSlot>(
  blocks: RawBlock<T>[],
): RawBlock<T>[][] {
  const sorted = [...blocks].sort(
    (a, b) => a.startSlot - b.startSlot || b.spanSlots - a.spanSlots,
  )
  const laneEnds: number[] = []

  for (const block of sorted) {
    let lane = laneEnds.findIndex((end) => end <= block.startSlot)
    if (lane === -1) {
      lane = laneEnds.length
      laneEnds.push(0)
    }
    laneEnds[lane] = block.startSlot + block.spanSlots
    block.lane = lane
  }

  const clusters = buildOverlapClusters(sorted)
  for (const cluster of clusters) {
    const laneCount = Math.max(...cluster.map((block) => block.lane)) + 1
    for (const block of cluster) block.laneCount = laneCount
  }

  return clusters
}

/**
 * Expands each scheduled entry across every day it meets on and clamps it
 * into the 7:30 AM–9:00 PM grid. Entries with no day/time are silently
 * skipped — an unscheduled section has nothing to place on this calendar. A
 * cluster of more than `MAX_INLINE_LANES` overlapping bookings collapses
 * into one `RoomCalendarCluster` chip so the grid stays readable.
 */
export function buildRoomWeek<T extends RoomCalendarSlot>(
  entries: readonly T[],
): Map<number, RoomCalendarPlacement<T>[]> {
  const rawByDay = new Map<number, RawBlock<T>[]>(
    CALENDAR_DAYS.map((day) => [day, []]),
  )

  for (const entry of entries) {
    if (
      entry.schedule_days === null ||
      entry.starts_at_time === null ||
      entry.ends_at_time === null
    )
      continue

    const days = parseScheduleDays(entry.schedule_days).filter((day) =>
      (CALENDAR_DAYS as readonly number[]).includes(day),
    )
    if (days.length === 0) continue

    const startMinutes = toMinutes(entry.starts_at_time)
    const endMinutes = toMinutes(entry.ends_at_time)
    const clampedStart = Math.max(startMinutes, DAY_START_MINUTES)
    const clampedEnd = Math.min(endMinutes, DAY_END_MINUTES)
    const isClipped = startMinutes < DAY_START_MINUTES || endMinutes > DAY_END_MINUTES
    const startSlot = Math.floor((clampedStart - DAY_START_MINUTES) / SLOT_MINUTES)
    const spanSlots = Math.max(1, Math.ceil((clampedEnd - clampedStart) / SLOT_MINUTES))

    for (const day of days) {
      rawByDay.get(day)?.push({ entry, day, startSlot, spanSlots, lane: 0, laneCount: 1, isClipped })
    }
  }

  const byDay = new Map<number, RoomCalendarPlacement<T>[]>()
  for (const [day, blocks] of rawByDay) {
    const clusters = assignLanes(blocks)
    const placements: RoomCalendarPlacement<T>[] = []

    for (const cluster of clusters) {
      if (cluster.length > MAX_INLINE_LANES) {
        const startSlot = Math.min(...cluster.map((block) => block.startSlot))
        const endSlot = Math.max(...cluster.map((block) => block.startSlot + block.spanSlots))
        placements.push({
          kind: "cluster",
          day,
          startSlot,
          spanSlots: endSlot - startSlot,
          entries: cluster.map((block) => block.entry),
        })
      } else {
        for (const block of cluster) {
          placements.push({
            kind: "block",
            day,
            entry: block.entry,
            startSlot: block.startSlot,
            spanSlots: block.spanSlots,
            lane: block.lane,
            laneCount: block.laneCount,
            isClipped: block.isClipped,
          })
        }
      }
    }

    byDay.set(day, placements)
  }

  return byDay
}

/** The 30-minute slot indices in `day` with no booking — the calendar's clickable "available" cells. */
export function freeSlots<T extends RoomCalendarSlot>(
  week: Map<number, RoomCalendarPlacement<T>[]>,
  day: number,
): number[] {
  const occupied = new Set<number>()
  for (const placement of week.get(day) ?? []) {
    for (let offset = 0; offset < placement.spanSlots; offset += 1) {
      occupied.add(placement.startSlot + offset)
    }
  }

  const free: number[] = []
  for (let slot = 0; slot < SLOT_COUNT; slot += 1) {
    if (!occupied.has(slot)) free.push(slot)
  }

  return free
}

/** Mirrors `App\Models\Subject::isLectureComponent()` — the only consumer of the pairing fields the room scheduler added to `/subjects`. */
export function isLectureComponentSubject(subject: Subject | undefined): boolean {
  if (subject?.paired_subject_id == null) return false
  if (subject.room_requirement != null) return subject.room_requirement === "lecture"
  return !subject.title.toUpperCase().includes("LAB")
}
