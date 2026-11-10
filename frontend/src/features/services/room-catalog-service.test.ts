import { describe, expect, it } from "vitest"

import { getLocalRoomOptions } from "@/features/services/room-catalog-service"

describe("getLocalRoomOptions", () => {
  it("keeps the COA scheduling fallback aligned with its requested 3 and 5 series rooms", () => {
    const names = getLocalRoomOptions("coa").map((room) => room.name)

    expect(names).toEqual(
      expect.arrayContaining([
        "3A",
        "3B",
        "3C",
        "3D",
        "3E",
        "3F",
        "3G",
        "5A",
        "5B",
        "5C",
        "5D",
        "5E",
        "5F",
        "5G",
      ]),
    )
    // LAB 1-4 are now shared campus rooms available to every college; COM
    // LAB 2 remains CBAE-exclusive inventory.
    expect(names).toContain("LAB 1")
    expect(names).not.toContain("COM LAB 2")
  })
})
