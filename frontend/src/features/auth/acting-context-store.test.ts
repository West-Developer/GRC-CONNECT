import { describe, expect, it } from "vitest"
import { createActingContextStore } from "@/features/auth/acting-context-store"

describe("acting-context-store", () => {
  it("defaults to undefined and provides no header value", () => {
    const store = createActingContextStore()
    expect(store.get()).toBeUndefined()
    expect(store.getHeaderValue()).toBeNull()
  })

  it("returns 'none' when super admin is in console mode (acting context is null)", () => {
    const store = createActingContextStore()
    store.set(null)
    expect(store.get()).toBeNull()
    expect(store.getHeaderValue()).toBe("none")
  })

  it("formats role and college as role:college", () => {
    const store = createActingContextStore()
    store.set({ role: "dean", college: "ccs" })
    expect(store.get()).toEqual({ role: "dean", college: "ccs" })
    expect(store.getHeaderValue()).toBe("dean:ccs")
  })

  it("formats role without college as role", () => {
    const store = createActingContextStore()
    store.set({ role: "registrar_head" })
    expect(store.get()).toEqual({ role: "registrar_head" })
    expect(store.getHeaderValue()).toBe("registrar_head")
  })

  it("resets back to undefined and null header on clear", () => {
    const store = createActingContextStore()
    store.set({ role: "dean", college: "ccs" })
    store.clear()
    expect(store.get()).toBeUndefined()
    expect(store.getHeaderValue()).toBeNull()
  })
})
