import { describe, expect, it } from "vitest"

import {
  authTokenStorageKey,
  createAuthTokenStore,
  type TokenStorageLike,
} from "@/features/auth/auth-token"

function createMemoryStorage(): TokenStorageLike {
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

describe("createAuthTokenStore", () => {
  it("round-trips a token through the store", () => {
    const store = createAuthTokenStore(createMemoryStorage())

    expect(store.write("1|plaintext-token")).toBe(true)
    expect(store.read()).toBe("1|plaintext-token")
  })

  it("returns null when nothing has been written", () => {
    const store = createAuthTokenStore(createMemoryStorage())

    expect(store.read()).toBeNull()
  })

  it("clears a stored token", () => {
    const storage = createMemoryStorage()
    const store = createAuthTokenStore(storage)
    store.write("1|plaintext-token")

    store.clear()

    expect(store.read()).toBeNull()
    expect(storage.getItem(authTokenStorageKey)).toBeNull()
  })

  it("treats a null underlying storage as consistently unavailable", () => {
    const store = createAuthTokenStore(null)

    expect(store.write("1|plaintext-token")).toBe(false)
    expect(store.read()).toBeNull()
    expect(() => {
      store.clear()
    }).not.toThrow()
  })

  it("fails closed when the storage throws", () => {
    const throwingStorage: TokenStorageLike = {
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
    const store = createAuthTokenStore(throwingStorage)

    expect(store.write("1|plaintext-token")).toBe(false)
    expect(store.read()).toBeNull()
    expect(() => {
      store.clear()
    }).not.toThrow()
  })

  it("treats an empty or blank stored value as absent", () => {
    const storage = createMemoryStorage()
    storage.setItem(authTokenStorageKey, "   ")
    const store = createAuthTokenStore(storage)

    expect(store.read()).toBeNull()
  })
})
