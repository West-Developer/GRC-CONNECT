import { describe, expect, it } from "vitest"

import {
  buildSectionChangeFields,
  describeChangeValue,
  type SectionScheduleDraft,
} from "@/features/lib/section-change-diff"
import type { Section } from "@/features/schemas/reference-data-schema"

const section = {
  type: "section",
  id: 1,
  academic_term_id: 2,
  subject_id: 3,
  section_code: "A",
  professor_id: null,
  schedule_days: "MON",
  starts_at_time: "08:00:00",
  ends_at_time: "10:00:00",
  room: "R101",
  modality: "f2f",
  capacity: 40,
  status: "published",
} as unknown as Section

const same: SectionScheduleDraft = {
  schedule_days: "MON",
  starts_at_time: "08:00",
  ends_at_time: "10:00",
  room: "R101",
  modality: "f2f",
  capacity: 40,
}

describe("buildSectionChangeFields", () => {
  it("is empty when nothing differs", () => {
    expect(buildSectionChangeFields(section, same)).toEqual({})
  })

  it("names only the changed fields, with server-format times", () => {
    expect(
      buildSectionChangeFields(section, {
        ...same,
        room: "R202",
        starts_at_time: "09:00",
        ends_at_time: "11:00",
      }),
    ).toEqual({
      room: "R202",
      starts_at_time: "09:00:00",
      ends_at_time: "11:00:00",
    })
  })

  it("sends null when a value is cleared", () => {
    expect(buildSectionChangeFields(section, { ...same, room: " " })).toEqual({
      room: null,
    })
  })

  it("includes a capacity change", () => {
    expect(
      buildSectionChangeFields(section, { ...same, capacity: 35 }),
    ).toEqual({
      capacity: 35,
    })
  })
})

describe("describeChangeValue", () => {
  it("shows empty values as Not set, times as HH:mm and modality by name", () => {
    expect(describeChangeValue("room", null)).toBe("Not set")
    expect(describeChangeValue("starts_at_time", "08:00:00")).toBe("08:00")
    expect(describeChangeValue("modality", "hyflex_a")).toBe("HyFlex A")
    expect(describeChangeValue("capacity", 40)).toBe("40")
  })
})
