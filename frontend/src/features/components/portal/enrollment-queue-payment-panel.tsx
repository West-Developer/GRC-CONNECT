"use client"

import { useAuth } from "@/features/auth/use-auth"
import { AccordionCard } from "@/features/components/portal/accordion-card"
import { StudentQueueLivePanel } from "@/features/components/queue/student-queue-live-panel"
import {
  Alert,
  AlertDescription,
  AlertTitle,
} from "@/features/components/ui/alert"
import { Badge } from "@/features/components/ui/badge"
import { Button } from "@/features/components/ui/button"
import { useQueueCallAlert } from "@/features/hooks/use-queue-call-alert"
import { useStudentQueueQuery } from "@/features/hooks/use-student-queue"
import type { Enrollment } from "@/features/schemas/enrollment-schema"

/** A negative line (a scholarship discount, ADR 0025) reads as a deduction. */
function formatLineAmount(amount: string | null): string {
  return amount?.startsWith("-") ? `−₱${amount.slice(1)}` : `₱${amount ?? ""}`
}

/**
 * The Queue & Payment detail card, embedded directly in `EnrollmentWorkspace`
 * once a student has an active enrollment for the selected term — no longer
 * a standalone portal module (the top-level `StatusStepper` in
 * `EnrollmentWorkspace` already covers the stage progress this used to show
 * on its own; this card is just the queue ticket / payment-date detail).
 */
export function EnrollmentQueuePaymentPanel({
  enrollment,
  defaultOpen = true,
  advancePaymentHeld = false,
}: {
  enrollment: Enrollment
  defaultOpen?: boolean
  /**
   * A freshman's first payment is held as credit (ADR 0043), so the Registrar's approval completes
   * this enrollment: there is no queue ticket to claim and nothing more to pay.
   */
  advancePaymentHeld?: boolean
}) {
  const isEnrolled = enrollment.status === "enrolled"
  const paidInAdvance = advancePaymentHeld && !isEnrolled
  const { session } = useAuth()
  const queueQuery = useStudentQueueQuery({
    viewerId: session?.userId ?? null,
    enabled: session?.role === "student" && !isEnrolled && !paidInAdvance,
  })
  // Owned here, above the collapsible card: the card unmounts its content
  // when collapsed, which used to drop the audio context and silence the call.
  const callAlert = useQueueCallAlert(queueQuery.data?.ticket ?? null, {
    defaultSoundOn: true,
  })

  return (
    <AccordionCard
      id="queue-payment"
      title={`Enrollment #${enrollment.id}`}
      badges={<Badge variant="secondary">{enrollment.status_label}</Badge>}
      defaultOpen={defaultOpen}
    >
      {paidInAdvance && (
        <Alert>
          <AlertTitle>Your payment is already on file</AlertTitle>
          <AlertDescription>
            You paid the Cashier before enrolling. Once Registrar Staff approve
            this enrollment you are enrolled — there is no queue number to
            claim and nothing more to pay for it.
          </AlertDescription>
        </Alert>
      )}
      {!isEnrolled && !paidInAdvance && (
        <>
          {queueQuery.isPending && (
            <Alert>
              <AlertTitle>Loading queue status</AlertTitle>
              <AlertDescription>Loading your Cashier queue…</AlertDescription>
            </Alert>
          )}
          {queueQuery.isError && (
            <Alert variant="destructive">
              <AlertTitle>Queue status unavailable</AlertTitle>
              <AlertDescription>
                Your live Cashier queue could not be loaded. Try again to
                refresh it.
              </AlertDescription>
              <Button
                type="button"
                variant="outline"
                onClick={() => void queueQuery.refetch()}
              >
                Retry queue status
              </Button>
            </Alert>
          )}
          {queueQuery.data && queueQuery.data.stage !== "enrolled" && (
            <StudentQueueLivePanel
              queue={queueQuery.data}
              mode="default"
              alert={callAlert}
            />
          )}
        </>
      )}
      <dl className="grid gap-3 sm:grid-cols-2">
        <div className="rounded-lg border p-3">
          <dt className="text-xs text-muted-foreground">Payment confirmed</dt>
          <dd className="text-sm font-medium">
            {enrollment.payment_confirmed_at ? (
              new Date(enrollment.payment_confirmed_at).toLocaleString()
            ) : paidInAdvance ? (
              <span className="font-normal text-muted-foreground">
                On file — applied when approved
              </span>
            ) : (
              <span className="font-normal text-muted-foreground">Not yet</span>
            )}
          </dd>
        </div>
      </dl>
      {enrollment.assessment && (
        <div className="rounded-lg border p-3">
          <p className="text-xs text-muted-foreground">Amount due</p>
          <p className="text-lg font-semibold">
            ₱{enrollment.assessment.total_amount}
          </p>
          <ul className="mt-2 grid gap-1 text-sm text-muted-foreground">
            {enrollment.assessment.items.map((item, index) => (
              <li key={index} className="flex justify-between gap-4">
                <span>{item.label}</span>
                <span>{formatLineAmount(item.amount)}</span>
              </li>
            ))}
          </ul>
        </div>
      )}
    </AccordionCard>
  )
}
