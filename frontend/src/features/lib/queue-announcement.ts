/**
 * Formats a ticket number so SpeechSynthesis pronounces each character individually.
 * Example: "Q003" -> "Q 0 0 3", "Q-001" -> "Q 0 0 1"
 */
export function formatTicketForSpeech(ticketNumber: string): string {
  return ticketNumber
    .replace(/[^a-zA-Z0-9]/g, " ")
    .trim()
    .split("")
    .filter((char) => char.trim().length > 0)
    .join(" ")
}

/**
 * The two-note queue alert chime: D5 (587.33 Hz), then A5 (880 Hz) starting
 * 0.15 s later. One definition shared by the Cashier's own device
 * (`playAlertChime`) and the student's device (`useQueueCallAlert`), so both
 * hear the same call.
 */
export const ALERT_CHIME_NOTES = [
  { frequency: 587.33, offset: 0, duration: 0.25 },
  { frequency: 880, offset: 0.15, duration: 0.4 },
] as const

/**
 * Plays the chime on `context`, returning the oscillators so a caller that owns
 * the context can stop them early. Each note is a sine with a fast decay.
 */
export function scheduleAlertChime(context: AudioContext): OscillatorNode[] {
  const oscillators: OscillatorNode[] = []

  for (const note of ALERT_CHIME_NOTES) {
    const start = context.currentTime + note.offset
    const oscillator = context.createOscillator()
    const gain = context.createGain()

    oscillator.type = "sine"
    oscillator.frequency.setValueAtTime(note.frequency, start)
    gain.gain.setValueAtTime(0.25, start)
    gain.gain.exponentialRampToValueAtTime(0.001, start + note.duration)
    oscillator.connect(gain)
    gain.connect(context.destination)
    oscillator.start(start)
    oscillator.stop(start + note.duration)
    oscillators.push(oscillator)
  }

  return oscillators
}

/**
 * Plays the two-tone queue alert chime on its own short-lived audio context
 * (the Cashier's device, which has had a user gesture).
 */
export function playAlertChime(): void {
  if (typeof window === "undefined") return

  try {
    const AudioContextClass =
      window.AudioContext ||
      (window as unknown as { webkitAudioContext?: typeof AudioContext })
        .webkitAudioContext
    if (!AudioContextClass) return

    const ctx = new AudioContextClass()
    scheduleAlertChime(ctx)

    setTimeout(() => {
      void ctx.close().catch(() => undefined)
    }, 800)
  } catch {
    // Audio contexts may be blocked by browser autoplay policies; fail silently
  }
}

/**
 * Speech synthesis stays blocked until it has been used from a user gesture
 * (Chrome, Android) or first started in one (iOS Safari). Speaking an empty,
 * silent utterance from the tap that unlocks audio lets the real announcement
 * (which fires later, from a poll) be spoken.
 */
export function primeSpeechSynthesis(): void {
  if (typeof window === "undefined" || !("speechSynthesis" in window)) {
    return
  }

  try {
    const utterance = new SpeechSynthesisUtterance("")
    utterance.volume = 0
    window.speechSynthesis.speak(utterance)
  } catch {
    // Best effort: the visible alert does not depend on it.
  }
}

/**
 * Speaks "Now serving ticket <X X X>" using SpeechSynthesis.
 */
export function announceTicketNumber(ticketNumber: string): void {
  if (typeof window === "undefined" || !("speechSynthesis" in window)) {
    return
  }

  try {
    window.speechSynthesis.cancel()

    const formatted = formatTicketForSpeech(ticketNumber)
    const utterance = new SpeechSynthesisUtterance(`Now serving ticket ${formatted}`)
    utterance.rate = 0.9
    utterance.pitch = 1.0
    utterance.volume = 1.0

    const voices = window.speechSynthesis.getVoices?.() ?? []
    const englishVoice = voices.find((v) => v.lang.startsWith("en"))
    if (englishVoice) {
      utterance.voice = englishVoice
    }

    setTimeout(() => {
      try {
        window.speechSynthesis.speak(utterance)
      } catch {
        // Safe fallback
      }
    }, 280)
  } catch {
    // Safe fallback
  }
}

/**
 * Combines alert chime and voice announcement for calling a ticket.
 */
export function playQueueAlert(ticketNumber: string): void {
  playAlertChime()
  announceTicketNumber(ticketNumber)
}
