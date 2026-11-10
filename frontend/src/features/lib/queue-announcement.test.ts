import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import {
  announceTicketNumber,
  formatTicketForSpeech,
  playAlertChime,
  playQueueAlert,
} from "@/features/lib/queue-announcement"

describe("queue-announcement", () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })

  afterEach(() => {
    vi.restoreAllMocks()
    vi.useRealTimers()
  })

  describe("formatTicketForSpeech", () => {
    it("spaces characters out for clear speech", () => {
      expect(formatTicketForSpeech("Q003")).toBe("Q 0 0 3")
      expect(formatTicketForSpeech("REG12")).toBe("R E G 1 2")
      expect(formatTicketForSpeech("Q-001")).toBe("Q 0 0 1")
    })
  })

  describe("announceTicketNumber", () => {
    it("calls window.speechSynthesis.speak with formatted ticket text", () => {
      const speakMock = vi.fn()
      const cancelMock = vi.fn()
      const getVoicesMock = vi.fn().mockReturnValue([
        { lang: "en-US", name: "English Voice" },
      ])

      const fakeSpeechSynthesis = {
        speak: speakMock,
        cancel: cancelMock,
        getVoices: getVoicesMock,
      }

      vi.stubGlobal("speechSynthesis", fakeSpeechSynthesis)

      class FakeUtterance {
        text: string
        rate = 1
        pitch = 1
        volume = 1
        voice = null
        constructor(text: string) {
          this.text = text
        }
      }
      vi.stubGlobal("SpeechSynthesisUtterance", FakeUtterance)

      announceTicketNumber("Q003")

      expect(cancelMock).toHaveBeenCalledOnce()
      vi.advanceTimersByTime(300)

      expect(speakMock).toHaveBeenCalledOnce()
      const utterance = speakMock.mock.calls[0]?.[0] as FakeUtterance
      expect(utterance.text).toBe("Now serving ticket Q 0 0 3")
      expect(utterance.rate).toBe(0.9)
    })
  })

  describe("playAlertChime", () => {
    it("creates oscillator and gain nodes for the two-tone chime", () => {
      const startMock = vi.fn()
      const stopMock = vi.fn()
      const connectMock = vi.fn()
      const setValueAtTimeMock = vi.fn()
      const exponentialRampMock = vi.fn()
      const closeMock = vi.fn().mockResolvedValue(undefined)

      class FakeAudioContext {
        currentTime = 0
        destination = {}
        createOscillator() {
          return {
            type: "sine",
            frequency: { setValueAtTime: setValueAtTimeMock },
            connect: connectMock,
            start: startMock,
            stop: stopMock,
          }
        }
        createGain() {
          return {
            gain: {
              setValueAtTime: setValueAtTimeMock,
              exponentialRampToValueAtTime: exponentialRampMock,
            },
            connect: connectMock,
          }
        }
        close = closeMock
      }

      vi.stubGlobal("AudioContext", FakeAudioContext)

      playAlertChime()

      expect(startMock).toHaveBeenCalledTimes(2)
      expect(stopMock).toHaveBeenCalledTimes(2)
      expect(setValueAtTimeMock).toHaveBeenCalledWith(587.33, 0)
      expect(setValueAtTimeMock).toHaveBeenCalledWith(880, 0.15)

      vi.advanceTimersByTime(1000)
      expect(closeMock).toHaveBeenCalledOnce()
    })
  })

  describe("playQueueAlert", () => {
    it("triggers both chime and announcement", () => {
      const speakMock = vi.fn()
      const cancelMock = vi.fn()
      vi.stubGlobal("speechSynthesis", {
        speak: speakMock,
        cancel: cancelMock,
        getVoices: () => [],
      })
      vi.stubGlobal("SpeechSynthesisUtterance", class {
        text: string
        constructor(text: string) {
          this.text = text
        }
      })

      playQueueAlert("Q005")

      expect(cancelMock).toHaveBeenCalledOnce()
      vi.advanceTimersByTime(300)
      expect(speakMock).toHaveBeenCalledOnce()
    })
  })
})
