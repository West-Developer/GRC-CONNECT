import { describe, expect, it } from "vitest"

import {
  createFormDraftStore,
  FORM_DRAFT_MAX_AGE_MS,
  type FormDraftStorageLike,
} from "@/features/services/form-draft-store"

function createMemoryStorage(
  initial: Record<string, string> = {},
): FormDraftStorageLike & { keys(): string[] } {
  const values = new Map<string, string>(Object.entries(initial))

  return {
    get length() {
      return values.size
    },
    getItem: (key) => values.get(key) ?? null,
    key: (index) => [...values.keys()][index] ?? null,
    keys: () => [...values.keys()],
    removeItem: (key) => {
      values.delete(key)
    },
    setItem: (key, value) => {
      values.set(key, value)
    },
  }
}

describe("createFormDraftStore", () => {
  it("round-trips what was typed", () => {
    const store = createFormDraftStore(createMemoryStorage())
    const values = { first_name: "Ana", requirement_type_ids: [1, 2] }

    expect(store.write("5", "admission.create-account", values)).toBe(true)
    expect(store.read("5", "admission.create-account")).toEqual(values)
  })

  it("returns null when nothing was written", () => {
    const store = createFormDraftStore(createMemoryStorage())

    expect(store.read("5", "admission.create-account")).toBeNull()
  })

  it("keeps drafts apart by user and by form", () => {
    const store = createFormDraftStore(createMemoryStorage())
    store.write("5", "admission.create-account", { first_name: "Ana" })

    expect(store.read("6", "admission.create-account")).toBeNull()
    expect(store.read("5", "admission.directory-search")).toBeNull()
  })

  it("drops a draft once it is older than the limit, and removes it from storage", () => {
    const storage = createMemoryStorage()
    let now = 1_000_000
    const store = createFormDraftStore(storage, () => now)
    store.write("5", "form", { a: 1 })

    now += FORM_DRAFT_MAX_AGE_MS - 1
    expect(store.read("5", "form")).toEqual({ a: 1 })

    now += 2
    expect(store.read("5", "form")).toBeNull()
    expect(storage.keys()).toEqual([])
  })

  it("clears one draft without touching the others", () => {
    const store = createFormDraftStore(createMemoryStorage())
    store.write("5", "one", { a: 1 })
    store.write("5", "two", { b: 2 })

    store.clear("5", "one")

    expect(store.read("5", "one")).toBeNull()
    expect(store.read("5", "two")).toEqual({ b: 2 })
  })

  it("clears every draft of every user on sign-out, and nothing else", () => {
    const storage = createMemoryStorage({
      "grc.auth-token.v1": "keep-me",
      "grc.enrollment-draft.v1.5.2": '{"selections":{}}',
    })
    const store = createFormDraftStore(storage)
    store.write("5", "one", { a: 1 })
    store.write("6", "two", { b: 2 })

    store.clearAll()

    expect(store.read("5", "one")).toBeNull()
    expect(store.read("6", "two")).toBeNull()
    expect(storage.keys().sort()).toEqual([
      "grc.auth-token.v1",
      "grc.enrollment-draft.v1.5.2",
    ])
  })

  it("ignores a stored value that is not a draft", () => {
    const storage = createMemoryStorage({
      "grc.form-draft.v1.5.form": "not json at all",
      "grc.form-draft.v1.5.other": '{"unexpected":true}',
    })
    const store = createFormDraftStore(storage)

    expect(store.read("5", "form")).toBeNull()
    expect(store.read("5", "other")).toBeNull()
  })

  it("never throws when there is no storage", () => {
    const store = createFormDraftStore(null)

    expect(store.write("5", "form", { a: 1 })).toBe(false)
    expect(store.read("5", "form")).toBeNull()
    expect(() => store.clear("5", "form")).not.toThrow()
    expect(() => store.clearAll()).not.toThrow()
  })

  it("never throws when the browser refuses storage", () => {
    const refusing: FormDraftStorageLike = {
      length: 1,
      getItem: () => {
        throw new Error("denied")
      },
      key: () => {
        throw new Error("denied")
      },
      removeItem: () => {
        throw new Error("denied")
      },
      setItem: () => {
        throw new Error("quota")
      },
    }
    const store = createFormDraftStore(refusing)

    expect(store.write("5", "form", { a: 1 })).toBe(false)
    expect(store.read("5", "form")).toBeNull()
    expect(() => store.clear("5", "form")).not.toThrow()
    expect(() => store.clearAll()).not.toThrow()
  })

  it("does not store a value that cannot be written as JSON", () => {
    const store = createFormDraftStore(createMemoryStorage())
    const circular: Record<string, unknown> = {}
    circular.self = circular

    expect(store.write("5", "form", circular)).toBe(false)
  })
})
