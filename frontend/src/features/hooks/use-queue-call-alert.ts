"use client"

import {
  useCallback,
  useEffect,
  useRef,
  useState,
  useSyncExternalStore,
} from "react"
import { toast } from "sonner"

import {
  readQueueCallSoundChoice,
  writeQueueCallSoundPreference,
} from "@/features/lib/queue-alert-preference"
import {
  announceTicketNumber,
  primeSpeechSynthesis,
  scheduleAlertChime,
} from "@/features/lib/queue-announcement"
import type { StudentQueueView } from "@/features/schemas/student-queue-schema"

type QueueTicket = StudentQueueView["ticket"]

type PreviousTicket = Pick<
  NonNullable<QueueTicket>,
  "ticket_number" | "status" | "announce_count"
>

export interface QueueCallAlertOptions {
  /**
   * Whether sound counts as wanted when the student never chose either way.
   * True on a student's own device, false on the shared kiosk. Browsers still
   * need one tap before audio can play (see the unlock listener below).
   */
  defaultSoundOn?: boolean
}

interface TitleClaim {
  title: string
  sequence: number
}

const ALERT_DURATION_MS = 10_000
const VIBRATION_PATTERN = [200, 100, 200, 100, 400]

const titleClaims = new Map<symbol, TitleClaim>()
let titleBaseline: string | null = null
let titleSequence = 0

const audioListeners = new Set<() => void>()
const audioOwners = new Set<symbol>()
const activeOscillators = new Map<symbol, OscillatorNode[]>()
let sharedAudioContext: AudioContext | null = null
let sharedSoundEnabled = false
let sharedSoundPreferred = false

function getAudioContextConstructor(): typeof AudioContext | null {
  if (typeof window === "undefined") {
    return null
  }

  const audioWindow = window as typeof window & {
    webkitAudioContext?: typeof AudioContext
  }

  return audioWindow.AudioContext ?? audioWindow.webkitAudioContext ?? null
}

function setDocumentTitleFromClaims(): void {
  if (typeof document === "undefined") {
    return
  }

  let currentClaim: TitleClaim | null = null
  for (const claim of titleClaims.values()) {
    if (!currentClaim || claim.sequence > currentClaim.sequence) {
      currentClaim = claim
    }
  }

  if (currentClaim) {
    document.title = currentClaim.title
    return
  }

  if (titleBaseline !== null) {
    document.title = titleBaseline
    titleBaseline = null
  }
}

function claimDocumentTitle(owner: symbol, title: string): void {
  if (typeof document === "undefined") {
    return
  }

  if (titleClaims.size === 0) {
    titleBaseline = document.title
  }

  titleSequence += 1
  titleClaims.set(owner, { title, sequence: titleSequence })
  setDocumentTitleFromClaims()
}

function releaseDocumentTitle(owner: symbol): void {
  if (titleClaims.delete(owner)) {
    setDocumentTitleFromClaims()
  }
}

function notifyAudioListeners(): void {
  for (const listener of audioListeners) {
    listener()
  }
}

function getSharedSoundEnabled(): boolean {
  return sharedSoundEnabled
}

function getSharedSoundPreferred(): boolean {
  return sharedSoundPreferred
}

function subscribeToSharedSound(listener: () => void): () => void {
  audioListeners.add(listener)
  return () => audioListeners.delete(listener)
}

function stopSharedOscillator(owner: symbol): void {
  const oscillators = activeOscillators.get(owner)
  if (!oscillators) {
    return
  }

  activeOscillators.delete(owner)
  for (const oscillator of oscillators) {
    oscillator.onended = null
    try {
      oscillator.stop()
    } catch {
      // A natural end or an earlier transition can stop the node first.
    }
    try {
      oscillator.disconnect()
    } catch {
      // Browser implementations differ when disconnecting a detached node.
    }
  }
}

function closeSharedAudioContext(): void {
  for (const owner of [...activeOscillators.keys()]) {
    stopSharedOscillator(owner)
  }

  sharedSoundEnabled = false
  const context = sharedAudioContext
  sharedAudioContext = null
  if (context) {
    try {
      void context.close().catch(() => undefined)
    } catch {
      // Closing an interrupted or already closed context remains best-effort.
    }
  }
  notifyAudioListeners()
}

function registerAudioOwner(owner: symbol, defaultSoundOn: boolean): void {
  if (audioOwners.size === 0) {
    sharedSoundPreferred = readQueueCallSoundChoice() ?? defaultSoundOn
  }
  audioOwners.add(owner)
  notifyAudioListeners()
}

function releaseAudioOwner(owner: symbol): void {
  stopSharedOscillator(owner)
  audioOwners.delete(owner)
  if (audioOwners.size === 0) {
    closeSharedAudioContext()
  }
}

/**
 * Creates and resumes the shared audio context. Must run inside a user
 * gesture. `persist` is true for the student's own "Turn on sound" choice;
 * the automatic first-tap unlock passes false so it never saves a choice the
 * student did not make.
 */
function enableSharedSound(persist = true): void {
  if (persist) {
    writeQueueCallSoundPreference(true)
    sharedSoundPreferred = true
    notifyAudioListeners()
  }
  primeSpeechSynthesis()

  const AudioContextConstructor = getAudioContextConstructor()
  if (!AudioContextConstructor) {
    return
  }

  try {
    const context = sharedAudioContext ?? new AudioContextConstructor()
    sharedAudioContext = context
    void context
      .resume()
      .then(() => {
        if (sharedAudioContext === context && audioOwners.size > 0) {
          sharedSoundEnabled = true
          notifyAudioListeners()
        }
      })
      .catch(() => {
        if (sharedAudioContext === context) {
          closeSharedAudioContext()
        }
      })
  } catch {
    closeSharedAudioContext()
  }
}

function disableSharedSound(): void {
  writeQueueCallSoundPreference(false)
  sharedSoundPreferred = false
  closeSharedAudioContext()
}

/**
 * The same two-note chime the Cashier hears (`ALERT_CHIME_NOTES`), on the
 * student's gesture-unlocked shared context. The oscillators are tracked per
 * owner so leaving the page or the next call stops them.
 */
function playSharedChime(owner: symbol): void {
  const context = sharedAudioContext
  if (!sharedSoundEnabled || !context) {
    return
  }

  stopSharedOscillator(owner)

  try {
    const oscillators = scheduleAlertChime(context)
    activeOscillators.set(owner, oscillators)
    const last = oscillators[oscillators.length - 1]
    if (last) {
      last.onended = () => {
        if (activeOscillators.get(owner) === oscillators) {
          stopSharedOscillator(owner)
        }
      }
    }
  } catch {
    stopSharedOscillator(owner)
  }
}

export type QueueCallAlert = ReturnType<typeof useQueueCallAlert>

export function useQueueCallAlert(
  ticket: QueueTicket,
  { defaultSoundOn = false }: QueueCallAlertOptions = {},
) {
  const [owner] = useState(() => Symbol("queue-call-alert"))
  const [isCalled, setIsCalled] = useState(false)
  const [callMessage, setCallMessage] = useState<string | null>(null)
  const soundEnabled = useSyncExternalStore(
    subscribeToSharedSound,
    getSharedSoundEnabled,
    () => false,
  )
  const soundPreferred = useSyncExternalStore(
    subscribeToSharedSound,
    getSharedSoundPreferred,
    () => false,
  )
  const previousTicketRef = useRef<PreviousTicket | null>(null)
  const alertTimerRef = useRef<number | null>(null)

  const clearCalledState = useCallback(() => {
    if (alertTimerRef.current !== null) {
      window.clearTimeout(alertTimerRef.current)
      alertTimerRef.current = null
    }

    stopSharedOscillator(owner)
    releaseDocumentTitle(owner)
    setIsCalled(false)
    setCallMessage(null)
  }, [owner])

  const enableSound = useCallback(() => {
    enableSharedSound()
  }, [])

  const disableSound = useCallback(() => {
    disableSharedSound()
  }, [])

  const ticketNumber = ticket?.ticket_number
  const ticketStatus = ticket?.status
  const announceCount = ticket?.announce_count ?? 0

  useEffect(() => {
    registerAudioOwner(owner, defaultSoundOn)
    return () => {
      clearCalledState()
      releaseAudioOwner(owner)
    }
  }, [clearCalledState, defaultSoundOn, owner])

  // Sound is wanted but the browser has not allowed audio yet: the student's
  // first tap or key press anywhere on the page is the gesture that does.
  // Waiting for them to find a button is why the call was never heard.
  useEffect(() => {
    if (!soundPreferred || soundEnabled || typeof window === "undefined") {
      return
    }

    const unlock = () => enableSharedSound(false)
    window.addEventListener("pointerdown", unlock, {
      once: true,
      capture: true,
    })
    window.addEventListener("keydown", unlock, { once: true, capture: true })

    return () => {
      window.removeEventListener("pointerdown", unlock, { capture: true })
      window.removeEventListener("keydown", unlock, { capture: true })
    }
  }, [soundPreferred, soundEnabled])

  useEffect(() => {
    const currentTicket =
      ticketNumber && ticketStatus
        ? {
            ticket_number: ticketNumber,
            status: ticketStatus,
            announce_count: announceCount,
          }
        : null
    const previousTicket = previousTicketRef.current

    // Two things ring the student's device: their own ticket going from
    // waiting to serving, and the Cashier pressing "Announce ticket" again
    // while it is serving (the counter goes up between polls).
    const wasCalled =
      currentTicket !== null &&
      previousTicket?.ticket_number === currentTicket.ticket_number &&
      previousTicket.status === "waiting" &&
      currentTicket.status === "serving"
    const wasAnnounced =
      currentTicket !== null &&
      previousTicket?.ticket_number === currentTicket.ticket_number &&
      previousTicket.status === "serving" &&
      currentTicket.status === "serving" &&
      currentTicket.announce_count > previousTicket.announce_count

    if (currentTicket && (wasCalled || wasAnnounced)) {
      const title = `Now serving ${currentTicket.ticket_number} — GRC Queue`
      claimDocumentTitle(owner, title)
      setIsCalled(true)
      setCallMessage(
        wasAnnounced
          ? `Your ticket ${currentTicket.ticket_number} is being called again.`
          : `Your ticket ${currentTicket.ticket_number} is now being served.`,
      )
      toast(title)

      try {
        navigator.vibrate?.(VIBRATION_PATTERN)
      } catch {
        // Vibration is a best-effort enhancement and must not suppress the
        // visible or assistive-technology announcement.
      }

      if (getSharedSoundEnabled()) {
        playSharedChime(owner)
        announceTicketNumber(currentTicket.ticket_number)
      }

      alertTimerRef.current = window.setTimeout(
        clearCalledState,
        ALERT_DURATION_MS,
      )
    }

    previousTicketRef.current = currentTicket

    return clearCalledState
  }, [clearCalledState, owner, ticketNumber, ticketStatus, announceCount])

  return {
    isCalled,
    callMessage,
    soundEnabled,
    soundPreferred,
    enableSound,
    disableSound,
  }
}
