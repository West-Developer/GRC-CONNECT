import { describe, expect, it } from "vitest"

import { pickCurrentBlock } from "@/features/lib/pick-current-block"
import type { EnrollmentBlock } from "@/features/schemas/enrollment-block-schema"

function block(
  blockCode: string,
  overrides: Partial<EnrollmentBlock> = {},
): EnrollmentBlock {
  return {
    type: "enrollment_block",
    block_code: blockCode,
    year_level: 1,
    curriculum_id: 9,
    section_plan_id: 12,
    total_units: 6,
    seats_remaining: 10,
    capacity: 40,
    is_selectable: true,
    reasons: [],
    preference_score: null,
    preference_reasons: [],
    subjects: [
      {
        section_id: 5,
        subject_id: 7,
        code: "CS101",
        title: "Intro",
        units: 3,
        schedule_days: "MWF",
        starts_at_time: "08:00:00",
        ends_at_time: "09:00:00",
        room: "R1",
        professor_name: null,
        capacity: 40,
        enrolled_count: 30,
        remaining_seats: 10,
      },
    ],
    ...overrides,
  }
}

function full(blockCode: string): EnrollmentBlock {
  return block(blockCode, {
    seats_remaining: 0,
    is_selectable: false,
    reasons: [{ code: "block_full", message: "No open seats." }],
  })
}

describe("pickCurrentBlock", () => {
  it("returns null when there are no blocks", () => {
    expect(pickCurrentBlock([])).toBeNull()
  })

  it("returns the first block that still has seats", () => {
    const current = pickCurrentBlock([block("IT101"), block("IT102")])

    expect(current?.block_code).toBe("IT101")
  })

  it("moves on to the next block once the first one is full", () => {
    const current = pickCurrentBlock([full("IT101"), block("IT102")])

    expect(current?.block_code).toBe("IT102")
  })

  it("orders by the numeric block number, whatever order the list arrives in", () => {
    const current = pickCurrentBlock([
      block("IT110"),
      block("IT102"),
      block("IT101", {
        seats_remaining: 0,
        is_selectable: false,
        reasons: [{ code: "block_full", message: "No open seats." }],
      }),
    ])

    expect(current?.block_code).toBe("IT102")
  })

  it("sorts a longer block number after a shorter one (natural order, not text order)", () => {
    const current = pickCurrentBlock([block("IT1100"), block("IT199")])

    expect(current?.block_code).toBe("IT199")
  })

  it("skips an earlier block that cannot be chosen for another reason when a later one can", () => {
    const current = pickCurrentBlock([
      block("IT101", {
        is_selectable: false,
        reasons: [
          { code: "incomplete_schedule", message: "Not fully scheduled." },
        ],
      }),
      block("IT102"),
    ])

    expect(current?.block_code).toBe("IT102")
  })

  it("still shows the first block with seats when none can be chosen, so the reason is visible", () => {
    const closed = (code: string) =>
      block(code, {
        is_selectable: false,
        reasons: [{ code: "window_closed", message: "Window closed." }],
      })

    const current = pickCurrentBlock([closed("IT102"), closed("IT101")])

    expect(current?.block_code).toBe("IT101")
  })

  it("treats a block with no seats as full even if it is still marked selectable", () => {
    const stale = block("IT101", { seats_remaining: 0, is_selectable: true })

    expect(pickCurrentBlock([stale, block("IT102")])?.block_code).toBe("IT102")
    expect(pickCurrentBlock([stale])).toBeNull()
  })

  it("returns null when every block is full", () => {
    expect(pickCurrentBlock([full("IT101"), full("IT102")])).toBeNull()
  })

  it("does not change the list it was given", () => {
    const list = [block("IT102"), block("IT101")]

    pickCurrentBlock(list)

    expect(list.map((item) => item.block_code)).toEqual(["IT102", "IT101"])
  })
})
