import { describe, expect, it } from "vitest"

import { generateTemporaryCredential } from "@/features/lib/temporary-credential"

describe("generateTemporaryCredential", () => {
  it("creates a 20-character credential with every required character class", () => {
    const credential = generateTemporaryCredential()

    expect(credential).toHaveLength(20)
    expect(credential).toMatch(/[A-Z]/)
    expect(credential).toMatch(/[a-z]/)
    expect(credential).toMatch(/[0-9]/)
    expect(credential).toMatch(/[^A-Za-z0-9]/)
  })
})
