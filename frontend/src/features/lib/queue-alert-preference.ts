export const QUEUE_CALL_SOUND_PREFERENCE_KEY = "grc.queue-call-sound.v1"

export function readQueueCallSoundPreference(): boolean {
  if (typeof window === "undefined") {
    return false
  }

  try {
    return (
      window.localStorage.getItem(QUEUE_CALL_SOUND_PREFERENCE_KEY) === "true"
    )
  } catch {
    return false
  }
}

/**
 * The saved choice, or `null` when the student never made one (as opposed to
 * an explicit "off"). A surface can default sound on without overriding a
 * student who turned it off.
 */
export function readQueueCallSoundChoice(): boolean | null {
  if (typeof window === "undefined") {
    return null
  }

  try {
    const stored = window.localStorage.getItem(QUEUE_CALL_SOUND_PREFERENCE_KEY)
    return stored === "true" ? true : stored === "false" ? false : null
  } catch {
    return null
  }
}

export function writeQueueCallSoundPreference(enabled: boolean): void {
  if (typeof window === "undefined") {
    return
  }

  try {
    window.localStorage.setItem(
      QUEUE_CALL_SOUND_PREFERENCE_KEY,
      enabled ? "true" : "false",
    )
  } catch {
    // Storage can be unavailable in privacy-restricted browser contexts. The
    // in-memory preference still lets this rendered panel behave predictably.
  }
}
