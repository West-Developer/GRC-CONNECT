import { afterEach, describe, expect, it, vi } from "vitest"

import {
  QUEUE_CALL_SOUND_PREFERENCE_KEY,
  readQueueCallSoundPreference,
  writeQueueCallSoundPreference,
} from "@/features/lib/queue-alert-preference"

describe("queue call sound preference", () => {
  afterEach(() => {
    window.localStorage.clear()
    vi.restoreAllMocks()
  })

  it("returns false unless the stored value is the literal enabled value", () => {
    expect(readQueueCallSoundPreference()).toBe(false)

    window.localStorage.setItem(QUEUE_CALL_SOUND_PREFERENCE_KEY, "true")
    expect(readQueueCallSoundPreference()).toBe(true)

    window.localStorage.setItem(QUEUE_CALL_SOUND_PREFERENCE_KEY, "TRUE")
    expect(readQueueCallSoundPreference()).toBe(false)
  })

  it("persists the explicit sound choice without retaining any queue data", () => {
    writeQueueCallSoundPreference(true)
    expect(window.localStorage.getItem(QUEUE_CALL_SOUND_PREFERENCE_KEY)).toBe(
      "true",
    )

    writeQueueCallSoundPreference(false)
    expect(window.localStorage.getItem(QUEUE_CALL_SOUND_PREFERENCE_KEY)).toBe(
      "false",
    )
  })

  it("fails safely when browser storage is unavailable", () => {
    vi.spyOn(Storage.prototype, "getItem").mockImplementation(() => {
      throw new DOMException("Blocked", "SecurityError")
    })
    vi.spyOn(Storage.prototype, "setItem").mockImplementation(() => {
      throw new DOMException("Blocked", "SecurityError")
    })

    expect(readQueueCallSoundPreference()).toBe(false)
    expect(() => writeQueueCallSoundPreference(true)).not.toThrow()
  })
})
