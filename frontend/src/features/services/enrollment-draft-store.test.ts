import { describe, expect, it } from "vitest"

import {
  createEnrollmentDraftStore,
  type EnrollmentDraftStorageLike,
} from "@/features/services/enrollment-draft-store"

function createMemoryStorage(): EnrollmentDraftStorageLike {
  const values = new Map<string, string>()

  return {
    getItem: (key) => values.get(key) ?? null,
    setItem: (key, value) => {
      values.set(key, value)
    },
    removeItem: (key) => {
      values.delete(key)
    },
  }
}

describe("createEnrollmentDraftStore", () => {
  it("round-trips selections and the selected block through the store", () => {
    const store = createEnrollmentDraftStore(createMemoryStorage())

    expect(
      store.write(1, 2, { selections: { 7: 5 }, selectedBlockCode: "IT201" }),
    ).toBe(true)
    expect(store.read(1, 2)).toEqual({
      selections: { 7: 5 },
      selectedBlockCode: "IT201",
    })
  })

  it("returns null when nothing has been written", () => {
    const store = createEnrollmentDraftStore(createMemoryStorage())

    expect(store.read(1, 2)).toBeNull()
  })

  it("scopes drafts per user and per term — no cross-leak", () => {
    const storage = createMemoryStorage()
    const store = createEnrollmentDraftStore(storage)
    store.write(1, 2, { selections: { 7: 5 }, selectedBlockCode: null })

    expect(store.read(1, 3)).toBeNull()
    expect(store.read(2, 2)).toBeNull()
    expect(store.read(1, 2)).toEqual({
      selections: { 7: 5 },
      selectedBlockCode: null,
    })
  })

  it("clears a stored draft", () => {
    const store = createEnrollmentDraftStore(createMemoryStorage())
    store.write(1, 2, { selections: { 7: 5 }, selectedBlockCode: null })

    store.clear(1, 2)

    expect(store.read(1, 2)).toBeNull()
  })

  it("treats a null underlying storage as consistently unavailable", () => {
    const store = createEnrollmentDraftStore(null)

    expect(
      store.write(1, 2, { selections: {}, selectedBlockCode: null }),
    ).toBe(false)
    expect(store.read(1, 2)).toBeNull()
    expect(() => {
      store.clear(1, 2)
    }).not.toThrow()
  })

  it("fails closed when the storage throws", () => {
    const throwingStorage: EnrollmentDraftStorageLike = {
      getItem: () => {
        throw new Error("storage unavailable")
      },
      setItem: () => {
        throw new Error("storage unavailable")
      },
      removeItem: () => {
        throw new Error("storage unavailable")
      },
    }
    const store = createEnrollmentDraftStore(throwingStorage)

    expect(
      store.write(1, 2, { selections: {}, selectedBlockCode: null }),
    ).toBe(false)
    expect(store.read(1, 2)).toBeNull()
    expect(() => {
      store.clear(1, 2)
    }).not.toThrow()
  })

  it("treats malformed stored JSON as absent rather than throwing", () => {
    const storage = createMemoryStorage()
    storage.setItem("grc.enrollment-draft.v1.1.2", "{not-json")
    const store = createEnrollmentDraftStore(storage)

    expect(store.read(1, 2)).toBeNull()
  })
})
