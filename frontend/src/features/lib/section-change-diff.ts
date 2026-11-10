import type { SectionChangeFields } from "@/features/schemas/section-change-request-schema"
import type { Section } from "@/features/schemas/reference-data-schema"

/** The schedule fields the Program Head edits in the section dialog. */
export interface SectionScheduleDraft {
  schedule_days: string
  /** HH:mm as typed into a time input, or "" */
  starts_at_time: string
  ends_at_time: string
  room: string
  modality: string
  capacity: number
}

const toServerTime = (value: string) =>
  value ? `${value}:00`.slice(0, 8) : null

const blankToNull = (value: string) =>
  value.trim() === "" ? null : value.trim()

/**
 * Only the fields the Program Head actually changed, in the shape the change
 * request endpoint takes. Empty when nothing differs, which the dialog uses to
 * keep "Send change request" disabled.
 */
export function buildSectionChangeFields(
  section: Section,
  draft: SectionScheduleDraft,
): SectionChangeFields {
  const changes: SectionChangeFields = {}

  const days = blankToNull(draft.schedule_days)
  if (days !== (section.schedule_days ?? null)) changes.schedule_days = days

  const starts = toServerTime(draft.starts_at_time)
  if (starts !== (section.starts_at_time ?? null))
    changes.starts_at_time = starts

  const ends = toServerTime(draft.ends_at_time)
  if (ends !== (section.ends_at_time ?? null)) changes.ends_at_time = ends

  const room = blankToNull(draft.room)
  if (room !== (section.room ?? null)) changes.room = room

  const modality = blankToNull(draft.modality)
  if (modality !== (section.modality ?? null)) changes.modality = modality

  if (Number.isInteger(draft.capacity) && draft.capacity !== section.capacity) {
    changes.capacity = draft.capacity
  }

  return changes
}

/** Human wording for one side of a change ("Not set" when empty). */
export function describeChangeValue(
  field: string,
  value: string | number | null,
): string {
  if (value === null || value === "") return "Not set"
  if (field === "starts_at_time" || field === "ends_at_time") {
    return String(value).slice(0, 5)
  }
  if (field === "modality") {
    if (value === "f2f") return "Face to Face"
    if (value === "hyflex_a") return "HyFlex A"
    if (value === "hyflex_b") return "HyFlex B"
  }
  return String(value)
}
