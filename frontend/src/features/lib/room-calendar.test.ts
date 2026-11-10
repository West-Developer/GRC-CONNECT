import { describe, expect, it } from "vitest"

import {
  buildRoomWeek,
  findConflictingIds,
  freeSlots,
  hasRoomConflict,
  slotClockTime,
  slotLabel,
  toMinutes,
  type RoomCalendarBlock,
  type RoomCalendarSlot,
  type RoomConflictSlot,
} from "@/features/lib/room-calendar"

interface TestSlot extends RoomCalendarSlot {
  id: string
}

function slot(id: string, days: string, start: string, end: string): TestSlot {
  return { id, schedule_days: days, starts_at_time: start, ends_at_time: end }
}

function conflictSlot(
  id: number,
  days: string,
  start: string,
  end: string,
  modality: RoomConflictSlot["modality"],
): RoomConflictSlot & { id: number } {
  return { id, schedule_days: days, starts_at_time: start, ends_at_time: end, modality }
}

/** Narrows a placement to a single block, failing loudly if it collapsed into a cluster instead. */
function asBlock(placement: unknown): RoomCalendarBlock<TestSlot> {
  const block = placement as RoomCalendarBlock<TestSlot>
  if (block.kind !== "block") throw new Error("Expected a block placement, got a cluster.")
  return block
}

describe("toMinutes", () => {
  it("converts a HH:MM:SS time into minutes since midnight", () => {
    expect(toMinutes("07:30:00")).toBe(450)
    expect(toMinutes("21:00:00")).toBe(1260)
  })
})

describe("slotLabel", () => {
  it("labels the first slot as 7:30 AM", () => {
    expect(slotLabel(0)).toBe("7:30 AM")
  })

  it("labels a midday slot in 12-hour clock", () => {
    expect(slotLabel(9)).toBe("12:00 PM")
  })

  it("labels the last slot as 8:30 PM", () => {
    expect(slotLabel(26)).toBe("8:30 PM")
  })
})

describe("slotClockTime", () => {
  it("returns 24-hour HH:MM for a time input", () => {
    expect(slotClockTime(0)).toBe("07:30")
    expect(slotClockTime(9)).toBe("12:00")
  })
})

describe("buildRoomWeek", () => {
  it("places a Monday/Wednesday 7:30-10:30 booking on both days with the right span", () => {
    const week = buildRoomWeek([slot("a", "MON/WED", "07:30:00", "10:30:00")])

    for (const day of [1, 3]) {
      const placements = week.get(day) ?? []
      expect(placements).toHaveLength(1)
      const block = asBlock(placements[0])
      expect(block.startSlot).toBe(0)
      expect(block.spanSlots).toBe(6)
      expect(block.isClipped).toBe(false)
    }
    expect(week.get(2)).toHaveLength(0)
  })

  it("gives two overlapping bookings on the same day separate lanes", () => {
    const week = buildRoomWeek([
      slot("a", "MON", "08:00:00", "10:00:00"),
      slot("b", "MON", "09:00:00", "11:00:00"),
    ])

    const blocks = (week.get(1) ?? []).map(asBlock)
    expect(blocks).toHaveLength(2)
    const lanes = blocks.map((block) => block.lane).sort()
    expect(lanes).toEqual([0, 1])
    expect(blocks[0].laneCount).toBe(2)
    expect(blocks[1].laneCount).toBe(2)
  })

  it("does not lane-split two bookings that do not overlap", () => {
    const blocks = (buildRoomWeek([
      slot("a", "MON", "08:00:00", "09:00:00"),
      slot("b", "MON", "09:00:00", "10:00:00"),
    ]).get(1) ?? []).map(asBlock)

    expect(blocks.every((block) => block.lane === 0 && block.laneCount === 1)).toBe(true)
  })

  it("collapses more than two overlapping bookings into one cluster instead of unreadable slivers", () => {
    const week = buildRoomWeek([
      slot("a", "MON", "08:00:00", "09:00:00"),
      slot("b", "MON", "08:00:00", "09:30:00"),
      slot("c", "MON", "08:30:00", "10:00:00"),
    ])

    const placements = week.get(1) ?? []
    expect(placements).toHaveLength(1)
    const [cluster] = placements
    expect(cluster.kind).toBe("cluster")
    if (cluster.kind !== "cluster") throw new Error("expected a cluster")
    expect(cluster.entries.map((entry) => entry.id).sort()).toEqual(["a", "b", "c"])
    // Bounding box covers the earliest start (8:00, slot 1) through the
    // latest end (10:00, slot 5) across all three bookings.
    expect(cluster.startSlot).toBe(1)
    expect(cluster.spanSlots).toBe(4)
  })

  it("keeps an unrelated non-overlapping booking as its own block alongside a cluster", () => {
    const week = buildRoomWeek([
      slot("a", "MON", "08:00:00", "09:00:00"),
      slot("b", "MON", "08:00:00", "09:00:00"),
      slot("c", "MON", "08:00:00", "09:00:00"),
      slot("d", "MON", "14:00:00", "15:00:00"),
    ])

    const placements = week.get(1) ?? []
    expect(placements).toHaveLength(2)
    expect(placements.filter((placement) => placement.kind === "cluster")).toHaveLength(1)
    const soloBlock = asBlock(placements.find((placement) => placement.kind === "block"))
    expect(soloBlock.entry.id).toBe("d")
  })

  it("clamps a booking that starts before 7:30 AM and flags it as clipped", () => {
    const block = asBlock((buildRoomWeek([slot("a", "TUE", "06:00:00", "08:30:00")]).get(2) ?? [])[0])

    expect(block.startSlot).toBe(0)
    expect(block.spanSlots).toBe(2)
    expect(block.isClipped).toBe(true)
  })

  it("clamps a booking that ends after 9:00 PM and flags it as clipped", () => {
    const block = asBlock((buildRoomWeek([slot("a", "FRI", "20:00:00", "22:00:00")]).get(5) ?? [])[0])

    expect(block.startSlot).toBe(25)
    expect(block.spanSlots).toBe(2)
    expect(block.isClipped).toBe(true)
  })

  it("skips an entry with no schedule days or times", () => {
    const week = buildRoomWeek([slot("a", null as unknown as string, null as unknown as string, null as unknown as string)])

    for (const placements of week.values()) expect(placements).toHaveLength(0)
  })

  it("ignores a Sunday token since the grid only covers Monday through Saturday", () => {
    const week = buildRoomWeek([slot("a", "SUN", "08:00:00", "09:00:00")])

    for (const placements of week.values()) expect(placements).toHaveLength(0)
  })
})

describe("freeSlots", () => {
  it("returns every slot index not covered by a booking", () => {
    const week = buildRoomWeek([slot("a", "MON", "07:30:00", "08:30:00")])

    const free = freeSlots(week, 1)
    expect(free).not.toContain(0)
    expect(free).not.toContain(1)
    expect(free).toContain(2)
    expect(free).toHaveLength(25)
  })

  it("returns every slot for a day with no bookings", () => {
    const week = buildRoomWeek([slot("a", "MON", "07:30:00", "08:30:00")])

    expect(freeSlots(week, 4)).toHaveLength(27)
  })

  it("treats a collapsed cluster's whole bounding range as occupied", () => {
    const week = buildRoomWeek([
      slot("a", "MON", "08:00:00", "09:00:00"),
      slot("b", "MON", "08:00:00", "09:00:00"),
      slot("c", "MON", "08:00:00", "09:00:00"),
    ])

    const free = freeSlots(week, 1)
    expect(free).not.toContain(1)
    expect(free).not.toContain(2)
  })
})

describe("hasRoomConflict", () => {
  it("does not flag a complementary HyFlex A/B pair sharing a room and time", () => {
    const a = conflictSlot(1, "MON", "08:00:00", "10:00:00", "hyflex_a")
    const b = conflictSlot(2, "MON", "08:00:00", "10:00:00", "hyflex_b")

    expect(hasRoomConflict(a, b)).toBe(false)
  })

  it("flags two F2F bookings overlapping on the same day", () => {
    const a = conflictSlot(1, "MON", "08:00:00", "10:00:00", "f2f")
    const b = conflictSlot(2, "MON", "09:00:00", "11:00:00", "f2f")

    expect(hasRoomConflict(a, b)).toBe(true)
  })

  it("flags two bookings with the same HyFlex pattern overlapping", () => {
    const a = conflictSlot(1, "MON", "08:00:00", "10:00:00", "hyflex_a")
    const b = conflictSlot(2, "MON", "08:00:00", "10:00:00", "hyflex_a")

    expect(hasRoomConflict(a, b)).toBe(true)
  })

  it("does not flag bookings on different days", () => {
    const a = conflictSlot(1, "MON", "08:00:00", "10:00:00", "f2f")
    const b = conflictSlot(2, "TUE", "08:00:00", "10:00:00", "f2f")

    expect(hasRoomConflict(a, b)).toBe(false)
  })

  it("does not flag a booking with no modality recorded", () => {
    const a = conflictSlot(1, "MON", "08:00:00", "10:00:00", null)
    const b = conflictSlot(2, "MON", "08:00:00", "10:00:00", "f2f")

    expect(hasRoomConflict(a, b)).toBe(false)
  })
})

describe("findConflictingIds", () => {
  it("flags the F2F section that collides with both HyFlex halves, leaving the HyFlex pair itself unflagged by each other and an unrelated booking clear", () => {
    const entries = [
      conflictSlot(1, "MON", "08:00:00", "10:00:00", "hyflex_a"),
      conflictSlot(2, "MON", "08:00:00", "10:00:00", "hyflex_b"),
      conflictSlot(3, "MON", "08:00:00", "10:00:00", "f2f"),
      conflictSlot(4, "MON", "14:00:00", "15:00:00", "f2f"),
    ]

    const conflicting = findConflictingIds(entries, (entry) => entry.id)

    // 1 and 2 are a legitimate HyFlex A/B pair, but each still conflicts
    // with 3 (an ordinary F2F booking in the same slot), so all three land
    // in the conflict set; 4 does not overlap anyone.
    expect(conflicting).toEqual(new Set([1, 2, 3]))
  })
})
