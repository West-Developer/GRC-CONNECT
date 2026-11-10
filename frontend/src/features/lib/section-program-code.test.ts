import { describe, expect, it } from "vitest"

import { programCodeFromSection } from "@/features/lib/section-program-code"

describe("programCodeFromSection", () => {
  it("extracts the leading letters as the program code", () => {
    expect(programCodeFromSection("EN101")).toBe("EN")
    expect(programCodeFromSection("HR403")).toBe("HR")
  })

  it("uppercases a lowercase prefix", () => {
    expect(programCodeFromSection("it201")).toBe("IT")
  })

  it("falls back to the whole code when there is no leading letter run", () => {
    expect(programCodeFromSection("101")).toBe("101")
  })
})
