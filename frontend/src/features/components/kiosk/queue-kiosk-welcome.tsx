"use client"

import { useEffect, useState } from "react"

import { QueueKioskDeviceLogin } from "@/features/components/kiosk/queue-kiosk-device-login"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/features/components/ui/dialog"

/** How long the Welcome animation plays before the device sign-in appears. */
export const QUEUE_KIOSK_WELCOME_MS = 2600

const WELCOME = "Welcome"

/**
 * The first screen of the Queue Kiosk: a full-screen Welcome animation, then the Cashier's device
 * sign-in in a modal. It is deliberately unlike the Student sign-in that follows on the same
 * device, so staff unlocking the device and students getting a queue number never face two
 * identical forms. The "Tap to sign in" button or any key press skips the animation; a person who
 * prefers reduced motion sees it only briefly.
 */
export function QueueKioskWelcome({
  error,
  onSubmit,
  welcomeMs = QUEUE_KIOSK_WELCOME_MS,
}: {
  error: string | null
  onSubmit: (credentials: { email: string; password: string }) => Promise<void>
  welcomeMs?: number
}) {
  const [ready, setReady] = useState(welcomeMs <= 0)

  useEffect(() => {
    if (ready) return
    const reducedMotion =
      typeof window.matchMedia === "function" &&
      window.matchMedia("(prefers-reduced-motion: reduce)").matches
    const timer = window.setTimeout(
      () => setReady(true),
      reducedMotion ? Math.min(welcomeMs, 500) : welcomeMs,
    )
    const skip = () => setReady(true)
    window.addEventListener("keydown", skip, { once: true })
    return () => {
      window.clearTimeout(timer)
      window.removeEventListener("keydown", skip)
    }
  }, [ready, welcomeMs])

  return (
    <main className="queue-kiosk-welcome" data-ready={ready ? "true" : "false"}>
      <div className="queue-kiosk-welcome__glow" aria-hidden="true" />
      <div className="queue-kiosk-welcome__content">
        <p className="queue-kiosk-welcome__eyebrow">Global Reciprocal Colleges</p>
        <h1 className="queue-kiosk-welcome__title" aria-label={WELCOME}>
          {WELCOME.split("").map((letter, index) => (
            <span
              key={`${letter}-${index}`}
              aria-hidden="true"
              style={{ animationDelay: `${index * 110}ms` }}
            >
              {letter}
            </span>
          ))}
        </h1>
        <p className="queue-kiosk-welcome__subtitle">Cashier Queue Kiosk</p>
        <span className="queue-kiosk-welcome__rule" aria-hidden="true" />
        {!ready && (
          <button
            type="button"
            className="queue-kiosk-welcome__skip"
            onClick={() => setReady(true)}
          >
            Tap to sign in
          </button>
        )}
      </div>

      {/* The device cannot be used until it is unlocked, so the modal has no way to be dismissed. */}
      <Dialog open={ready} onOpenChange={() => undefined}>
        <DialogContent
          showCloseButton={false}
          className="queue-kiosk-welcome__modal sm:max-w-md"
          onEscapeKeyDown={(event) => event.preventDefault()}
          onInteractOutside={(event) => event.preventDefault()}
        >
          <DialogHeader>
            <DialogTitle>Queue Kiosk sign-in</DialogTitle>
            <DialogDescription>
              Authorized Cashier staff: unlock this device before assisting a
              Student.
            </DialogDescription>
          </DialogHeader>
          <QueueKioskDeviceLogin error={error} onSubmit={onSubmit} />
        </DialogContent>
      </Dialog>
    </main>
  )
}
