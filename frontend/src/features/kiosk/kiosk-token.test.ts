import { describe, expect, it, vi } from "vitest"

import {
  createKioskTokenStore,
  kioskTokenStorageKey,
  type KioskTokenStore,
} from "@/features/kiosk/kiosk-token"
import type { TokenStorageLike } from "@/features/auth/auth-token"

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

describe("createKioskTokenStore", () => {
  it("round-trips a device token through only the kiosk storage key", () => {
    const storage = createMemoryStorage()
    const store: KioskTokenStore = createKioskTokenStore(storage)

    expect(store.write("1|kiosk-token")).toBe(true)
    expect(store.read()).toBe("1|kiosk-token")
    expect(storage.getItem(kioskTokenStorageKey)).toBe("1|kiosk-token")
    expect(storage.getItem("grc.auth-token.v1")).toBeNull()
  })

  it("returns null for absent or blank persistent values", () => {
    const storage = createMemoryStorage()
    storage.setItem(kioskTokenStorageKey, "   ")

    expect(createKioskTokenStore(storage).read()).toBeNull()
  })

  it("clears only the kiosk token", () => {
    const storage = createMemoryStorage()
    storage.setItem(kioskTokenStorageKey, "kiosk-token")
    storage.setItem("grc.auth-token.v1", "portal-token")

    createKioskTokenStore(storage).clear()

    expect(storage.getItem(kioskTokenStorageKey)).toBeNull()
    expect(storage.getItem("grc.auth-token.v1")).toBe("portal-token")
  })

  it("fails closed when storage is unavailable or rejects an operation", () => {
    const missingStore = createKioskTokenStore(null)
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
    const throwingStore = createKioskTokenStore(throwingStorage)

    expect(missingStore.write("kiosk-token")).toBe(false)
    expect(missingStore.read()).toBeNull()
    expect(() => missingStore.clear()).not.toThrow()
    expect(throwingStore.write("kiosk-token")).toBe(false)
    expect(throwingStore.read()).toBeNull()
    expect(() => throwingStore.clear()).not.toThrow()
  })

  it("never reads, writes, or removes the portal token key", () => {
    const getItem = vi.fn(() => null)
    const setItem = vi.fn()
    const removeItem = vi.fn()
    const storage: TokenStorageLike = {
      getItem,
      setItem,
      removeItem,
    }
    const store = createKioskTokenStore(storage)

    store.read()
    store.write("kiosk-token")
    store.clear()

    expect(getItem).not.toHaveBeenCalledWith("grc.auth-token.v1")
    expect(setItem).not.toHaveBeenCalledWith(
      "grc.auth-token.v1",
      expect.any(String),
    )
    expect(removeItem).not.toHaveBeenCalledWith("grc.auth-token.v1")
  })
})
