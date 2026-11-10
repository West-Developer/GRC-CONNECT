"use client"

import { useCallback, useEffect, useRef, useState, type CSSProperties } from "react"
import { LogOutIcon, TicketCheckIcon } from "lucide-react"
import { useQueryClient } from "@tanstack/react-query"

import { QueueKioskSignOutDialog } from "@/features/components/kiosk/queue-kiosk-sign-out-dialog"
import { StudentQueueLivePanel } from "@/features/components/queue/student-queue-live-panel"
import {
  Alert,
  AlertDescription,
  AlertTitle,
} from "@/features/components/ui/alert"
import { Button } from "@/features/components/ui/button"
import {
  Card,
  CardContent,
  CardDescription,
  CardFooter,
  CardHeader,
  CardTitle,
} from "@/features/components/ui/card"
import {
  useKioskQueueClaimMutation,
  useStudentQueueQuery,
} from "@/features/hooks/use-student-queue"
import type { QueueKioskSessionState } from "@/features/hooks/use-queue-kiosk-session"
import { isApiClientError } from "@/features/services/api-client"

type ActiveState = Extract<QueueKioskSessionState, { status: "student-active" }>

/**
 * Once the Student's queue number is on screen the kiosk counts down and then finishes the session
 * by itself, exactly as pressing Done does, so a Student who walks away without pressing it never
 * leaves their name and ticket on a shared device.
 */
export const QUEUE_KIOSK_AUTO_DONE_SECONDS = 5

/**
 * "This screen clears itself in N seconds", then calls `onElapsed`. Mounted only while a ticket is
 * shown, so its count starts afresh each time and stops when the ticket goes away.
 */
function QueueKioskAutoClear({
  seconds,
  onElapsed,
}: {
  seconds: number
  onElapsed: () => void | Promise<void>
}) {
  const [secondsLeft, setSecondsLeft] = useState(seconds)

  useEffect(() => {
    const timer = window.setInterval(
      () => setSecondsLeft((current) => current - 1),
      1000,
    )
    return () => window.clearInterval(timer)
  }, [])

  useEffect(() => {
    if (secondsLeft <= 0) void onElapsed()
  }, [secondsLeft, onElapsed])

  if (secondsLeft <= 0) return null

  return (
    <div
      className="queue-kiosk-autoclear"
      role="timer"
      aria-live="off"
      style={{ "--autoclear-seconds": `${seconds}s` } as CSSProperties}
    >
      <p>
        This screen clears itself in <strong>{secondsLeft}</strong>{" "}
        {secondsLeft === 1 ? "second" : "seconds"}. Press Done to finish now.
      </p>
      <span className="queue-kiosk-autoclear__bar" aria-hidden="true" />
    </div>
  )
}

export function QueueKioskStudentSession({
  state,
  finishStudent,
  signOutDevice,
  requirePassword = true,
  autoDoneSeconds = QUEUE_KIOSK_AUTO_DONE_SECONDS,
}: {
  state: ActiveState
  finishStudent: () => void
  signOutDevice: () => void
  requirePassword?: boolean
  autoDoneSeconds?: number
}) {
  const queryClient = useQueryClient()
  const generation = useRef(0)
  const claimLock = useRef(false)
  const controller = useRef<AbortController | null>(null)
  const [claimError, setClaimError] = useState<string | null>(null)
  const [confirmingSignOut, setConfirmingSignOut] = useState(false)
  const viewerId = String(state.studentUser.id)
  const queueQuery = useStudentQueueQuery({
    viewerId,
    enabled: true,
    token: state.studentToken,
  })
  const claimMutation = useKioskQueueClaimMutation({
    viewerId,
    studentToken: state.studentToken,
    kioskToken: state.kioskToken,
  })

  const clearQueue = useCallback(() => {
    generation.current++
    controller.current?.abort()
    const cancellation = queryClient.cancelQueries({
      queryKey: ["student-queue"],
    })
    queryClient.removeQueries({ queryKey: ["student-queue"] })
    return cancellation
  }, [queryClient])

  useEffect(
    () => () => {
      generation.current++
      controller.current?.abort()
    },
    [],
  )

  useEffect(() => {
    if (
      !queueQuery.isError ||
      !isApiClientError(queueQuery.error) ||
      queueQuery.error.status !== 401
    ) {
      return
    }

    const cancellation = clearQueue()
    finishStudent()
    void cancellation
  }, [clearQueue, finishStudent, queueQuery.error, queueQuery.isError])

  const finished = useRef(false)
  const done = useCallback(async () => {
    if (finished.current) return
    finished.current = true
    const cancellation = clearQueue()
    finishStudent()
    await cancellation
  }, [clearQueue, finishStudent])

  // The countdown runs only while a ticket is shown (just claimed, or one the Student already had).
  const hasTicket = Boolean(queueQuery.data?.ticket)

  const signOut = async () => {
    const cancellation = clearQueue()
    signOutDevice()
    await cancellation
  }
  const claim = async () => {
    if (claimLock.current) return
    claimLock.current = true
    const current = generation.current
    const nextController = new AbortController()
    controller.current = nextController
    setClaimError(null)
    try {
      await claimMutation.mutateAsync({ signal: nextController.signal })
      if (current !== generation.current) return
      await queueQuery.refetch()
    } catch (cause) {
      if (
        current !== generation.current ||
        (cause instanceof Error && cause.name === "AbortError")
      )
        return
      if (isApiClientError(cause) && cause.status === 403) {
        const cancellation = clearQueue()
        finishStudent()
        signOutDevice()
        await cancellation
        return
      }
      if (isApiClientError(cause) && cause.status === 401) {
        const cancellation = clearQueue()
        finishStudent()
        await cancellation
        return
      }
      setClaimError(
        isApiClientError(cause) && cause.kind === "http"
          ? cause.message
          : "The queue number could not be claimed. Please try again.",
      )
    } finally {
      if (current === generation.current) claimLock.current = false
    }
  }

  return (
    <main className="queue-kiosk-shell">
      <header className="queue-kiosk-header">
        <div>
          <p className="queue-kiosk-eyebrow">Global Reciprocal Colleges</p>
          <h1>Cashier Queue Kiosk</h1>
        </div>
        <Button
          type="button"
          variant="outline"
          onClick={() => (requirePassword ? setConfirmingSignOut(true) : void signOut())}
        >
          <LogOutIcon data-icon="inline-start" />
          Sign out device
        </Button>
        <QueueKioskSignOutDialog
          open={confirmingSignOut}
          onOpenChange={setConfirmingSignOut}
          onConfirmSignOut={() => void signOut()}
          kioskEmail={state.kioskUser.email}
        />
      </header>
      <section
        className="queue-kiosk-student"
        aria-label="Student queue session"
      >
        <p className="queue-kiosk-eyebrow">Student: {state.studentUser.name}</p>
        {queueQuery.isPending && (
          <p role="status">Loading your live Cashier queue…</p>
        )}
        {queueQuery.isError && (
          <Card className="queue-kiosk-card">
            <CardHeader>
              <CardTitle>Queue status unavailable</CardTitle>
            </CardHeader>
            <CardContent>
              <p>Your live Cashier queue could not be loaded.</p>
            </CardContent>
            <CardFooter>
              <Button
                type="button"
                variant="outline"
                onClick={() => void queueQuery.refetch()}
              >
                Retry queue status
              </Button>
            </CardFooter>
          </Card>
        )}
        {queueQuery.data && (
          <>
            {queueQuery.data.stage === "pending_payment" &&
            queueQuery.data.can_claim &&
            !queueQuery.data.ticket ? (
              <Card className="queue-kiosk-card">
                <CardHeader>
                  <CardTitle>Claim your queue number</CardTitle>
                  <CardDescription>
                    You are ready to join the Cashier queue.
                  </CardDescription>
                </CardHeader>
                <CardContent>
                  {claimError && (
                    <Alert variant="destructive">
                      <AlertTitle>Queue claim unavailable</AlertTitle>
                      <AlertDescription>{claimError}</AlertDescription>
                    </Alert>
                  )}
                </CardContent>
                <CardFooter>
                  <Button
                    className="queue-kiosk-claim"
                    type="button"
                    size="lg"
                    disabled={claimMutation.isPending}
                    onClick={() => void claim()}
                  >
                    <TicketCheckIcon data-icon="inline-start" />
                    {claimMutation.isPending
                      ? "Claiming queue number…"
                      : "Claim queue number"}
                  </Button>
                </CardFooter>
              </Card>
            ) : (
              <StudentQueueLivePanel queue={queueQuery.data} mode="kiosk" />
            )}
          </>
        )}
        {hasTicket && (
          <QueueKioskAutoClear seconds={autoDoneSeconds} onElapsed={done} />
        )}
        <Button
          className="queue-kiosk-done"
          type="button"
          size="lg"
          variant="outline"
          onClick={() => void done()}
        >
          Done
        </Button>
      </section>
    </main>
  )
}
