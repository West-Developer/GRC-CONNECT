import { describe, expect, it } from "vitest"

import {
  compareBySchedule,
  hasScheduleConflict,
  parseScheduleDays,
  type ScheduleSlot,
} from "@/features/lib/schedule-order"

function slot(overrides: Partial<ScheduleSlot> = {}): ScheduleSlot {
  return {
    schedule_days: "MWF",
    starts_at_time: "08:00:00",
    ends_at_time: "09:00:00",
    ...overrides,
  }
}

describe("parseScheduleDays", () => {
  it("parses MWF into Monday, Wednesday, Friday", () => {
    expect(parseScheduleDays("MWF")).toEqual([1, 3, 5])
  })

  it("parses TTh into Tuesday, Thursday without Th being swallowed by T", () => {
    expect(parseScheduleDays("TTh")).toEqual([2, 4])
  })

  it("parses Sat and Sun as whole tokens", () => {
    expect(parseScheduleDays("SatSun")).toEqual([6, 7])
  })

  it("returns an empty list for null", () => {
    expect(parseScheduleDays(null)).toEqual([])
  })

  it("parses the API's canonical slash-joined MON/TUE/WED format", () => {
    expect(parseScheduleDays("MON/TUE/WED")).toEqual([1, 2, 3])
  })

  it("parses THU and FRI in canonical all-caps form", () => {
    expect(parseScheduleDays("THU/FRI")).toEqual([4, 5])
  })

  it("parses a single canonical SAT segment", () => {
    expect(parseScheduleDays("SAT")).toEqual([6])
  })
})

describe("hasScheduleConflict", () => {
  it("flags an overlapping time on a shared day", () => {
    const a = slot({
      schedule_days: "MW",
      starts_at_time: "08:00:00",
      ends_at_time: "09:30:00",
    })
    const b = slot({
      schedule_days: "MF",
      starts_at_time: "09:00:00",
      ends_at_time: "10:00:00",
    })

    expect(hasScheduleConflict(a, b)).toBe(true)
  })

  it("does not flag times that only touch at the boundary", () => {
    const a = slot({ starts_at_time: "08:00:00", ends_at_time: "09:00:00" })
    const b = slot({ starts_at_time: "09:00:00", ends_at_time: "10:00:00" })

    expect(hasScheduleConflict(a, b)).toBe(false)
  })

  it("does not flag overlapping times on different days", () => {
    const a = slot({ schedule_days: "MWF" })
    const b = slot({ schedule_days: "TTh" })

    expect(hasScheduleConflict(a, b)).toBe(false)
  })

  it("does not flag a slot that has no schedule yet", () => {
    const a = slot()
    const b = slot({
      schedule_days: null,
      starts_at_time: null,
      ends_at_time: null,
    })

    expect(hasScheduleConflict(a, b)).toBe(false)
  })
})

describe("compareBySchedule", () => {
  it("sorts an earlier time before a later time on the same day", () => {
    const early = slot({ schedule_days: "M", starts_at_time: "07:30:00" })
    const late = slot({ schedule_days: "M", starts_at_time: "09:00:00" })

    expect(compareBySchedule(early, late)).toBeLessThan(0)
  })

  it("sorts Monday before Tuesday regardless of time", () => {
    const monday = slot({ schedule_days: "M", starts_at_time: "17:00:00" })
    const tuesday = slot({ schedule_days: "T", starts_at_time: "07:00:00" })

    expect(compareBySchedule(monday, tuesday)).toBeLessThan(0)
  })

  it("sorts Saturday before an unscheduled slot", () => {
    const saturday = slot({ schedule_days: "Sat", starts_at_time: "08:00:00" })
    const unscheduled = slot({
      schedule_days: null,
      starts_at_time: null,
      ends_at_time: null,
    })

    expect(compareBySchedule(saturday, unscheduled)).toBeLessThan(0)
  })
})
