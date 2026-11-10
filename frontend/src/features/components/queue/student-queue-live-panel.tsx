"use client"

import { useId } from "react"
import {
  BellRingIcon,
  Clock3Icon,
  Volume2Icon,
  VolumeXIcon,
} from "lucide-react"

import {
  Alert,
  AlertDescription,
  AlertTitle,
} from "@/features/components/ui/alert"
import { Badge } from "@/features/components/ui/badge"
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
  useQueueCallAlert,
  type QueueCallAlert,
} from "@/features/hooks/use-queue-call-alert"
import { cn } from "@/features/lib/utils"
import type { StudentQueueView } from "@/features/schemas/student-queue-schema"

export type QueueLivePanelMode = "kiosk" | "default" | "compact"

interface StudentQueueLivePanelProps {
  queue: StudentQueueView
  mode?: QueueLivePanelMode
  /**
   * The call alert, when a parent that stays mounted owns it. The Enrollment
   * page keeps it outside its collapsible card, so collapsing the card does
   * not tear the audio down and silence the call. Without it the panel runs
   * its own.
   */
  alert?: QueueCallAlert
}

function getStageGuidance(queue: StudentQueueView): string {
  switch (queue.stage) {
    case "no_active_enrollment":
      return "You do not have an active enrollment for the current term."
    case "pending_program_head_approval":
      return "Program Head approval, then Registrar approval, is required before a queue number can be issued. Once approved, claim your queuing ticket in person at the school Cashier kiosk."
    case "pending_student_review":
      return "Your Program Chair changed your subjects. Open Enrollment to accept or decline the changes; once you accept, the Registrar can approve your enrollment and a queue number can be issued."
    case "pending_registrar_approval":
      return "Registrar approval is required before a queue number can be issued. Once approved, claim your queuing ticket in person at the school Cashier kiosk."
    case "pending_payment":
      if (!queue.ticket && queue.can_claim) {
        return "Your enrollment is approved! Claim your queuing ticket in person at the school Cashier kiosk."
      }
      return queue.ticket
        ? "Your queue ticket is active."
        : "Your queue number is not available yet. Claim it in person at the school Cashier kiosk."
    case "enrolled":
      return "Payment has been confirmed and your enrollment is complete."
    case "freshman_awaiting_payment":
      return "Go to the Cashier and wait for your number to be called. Your student account is set up after the Cashier confirms your payment; you will get an email then."
    case "freshman_paid":
      return "Your payment has been confirmed. Check your email for the account setup email to create your password."
    case "transferee_awaiting_payment":
      return "Choose your subjects on the Enrollment page if you have not yet, then go to the Cashier and wait for your number to be called."
    case "awaiting_first_payment":
      return "Your subjects are saved. Take your queue number to the Cashier and wait for it to be called; once you have paid, your enrollment goes to the Registrar for approval."
    case "transferee_paid":
      return "Your payment has been confirmed. Your enrollment now waits for Registrar approval; you will get an email once you are enrolled."
    case "transferee_enrolled":
      return "You are enrolled: your payment was confirmed and the Registrar approved your enrollment. You no longer need this queue number."
  }
}

function getStatusVariant(
  status: NonNullable<StudentQueueView["ticket"]>["status"],
) {
  switch (status) {
    case "serving":
      return "success" as const
    case "cancelled":
      return "destructive" as const
    case "served":
      return "outline" as const
    case "waiting":
      return "secondary" as const
  }
}

function getPositionCopy(position: number): string {
  return position === 0
    ? "You're next in line."
    : `${position} ${position === 1 ? "student is" : "students are"} ahead of you.`
}

export function StudentQueueLivePanel({
  alert,
  ...props
}: StudentQueueLivePanelProps) {
  return alert ? (
    <QueueLivePanelView {...props} alert={alert} />
  ) : (
    <StudentQueueLivePanelWithOwnAlert {...props} />
  )
}

function StudentQueueLivePanelWithOwnAlert(
  props: Omit<StudentQueueLivePanelProps, "alert">,
) {
  // A student's own device defaults sound on; the shared kiosk does not.
  const alert = useQueueCallAlert(props.queue.ticket, {
    defaultSoundOn: props.mode !== "kiosk",
  })

  return <QueueLivePanelView {...props} alert={alert} />
}

function QueueLivePanelView({
  queue,
  mode = "default",
  alert,
}: StudentQueueLivePanelProps & { alert: QueueCallAlert }) {
  const upcomingTicketNumbersId = useId()
  const {
    isCalled,
    callMessage,
    soundEnabled,
    soundPreferred,
    enableSound,
    disableSound,
  } = alert
  const ticket = queue.ticket
  const isStudentSurface = mode !== "kiosk"

  if (isStudentSurface && queue.stage === "enrolled") {
    return null
  }

  const isAwaitingApproval =
    isStudentSurface &&
    ticket === null &&
    (queue.stage === "pending_program_head_approval" ||
      queue.stage === "pending_student_review" ||
      queue.stage === "pending_registrar_approval")
  const awaitingApprovalLabel =
    queue.stage === "pending_program_head_approval"
      ? "Waiting for Program Head approval"
      : queue.stage === "pending_student_review"
        ? "Waiting for your answer on the Program Chair's changes"
        : "Waiting for Registrar approval"

  return (
    <Card
      role="region"
      aria-label="Your Cashier queue"
      className={cn(
        "queue-live-panel",
        `queue-live-panel--${mode}`,
        isCalled && "queue-live-panel--called",
      )}
    >
      <CardHeader>
        <CardTitle level={2}>Your Cashier queue</CardTitle>
        <CardDescription>
          Your live queue details refresh while this page is open.
        </CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        {callMessage && (
          <Alert
            role="alert"
            aria-live="assertive"
            className="queue-live-panel__call-alert"
          >
            <BellRingIcon />
            <AlertTitle>Now serving</AlertTitle>
            <AlertDescription>{callMessage}</AlertDescription>
          </Alert>
        )}

        <div className="queue-live-panel__facts">
          <div
            className={cn(
              "queue-live-panel__ticket",
              !ticket &&
                isStudentSurface &&
                "queue-live-panel__ticket--unavailable",
              isAwaitingApproval &&
                "queue-live-panel__ticket--awaiting-approval",
            )}
          >
            <p className="queue-live-panel__label">Your ticket</p>
            {ticket ? (
              <p className="queue-live-panel__ticket-number">
                {ticket.ticket_number}
              </p>
            ) : (
              <p className="queue-live-panel__ticket-number queue-live-panel__ticket-number--status">
                Not issued
              </p>
            )}
            {isAwaitingApproval && (
              <div className="space-y-1">
                <p className="queue-live-panel__approval-status">
                  <Clock3Icon aria-hidden="true" />
                  {awaitingApprovalLabel}
                </p>
                <p className="text-xs text-muted-foreground">
                  Once approved, go in person to the school Cashier kiosk on
                  campus to claim your queuing ticket.
                </p>
              </div>
            )}
            {ticket && (
              <div className="flex flex-wrap gap-2">
                <Badge variant={getStatusVariant(ticket.status)}>
                  {ticket.status_label}
                </Badge>
                <Badge
                  variant={
                    ticket.priority === "priority" ? "warning" : "outline"
                  }
                >
                  {ticket.priority_label}
                </Badge>
              </div>
            )}
          </div>

          <div
            className={cn(
              "queue-live-panel__serving",
              (!queue.now_serving_ticket_number || queue.cut_off_today) &&
                isStudentSurface &&
                "queue-live-panel__serving--unavailable",
            )}
          >
            <p className="queue-live-panel__label">Now serving</p>
            {queue.cut_off_today ? (
              <div className="space-y-1">
                {queue.now_serving_ticket_number && (
                  <p className="queue-live-panel__serving-number font-mono">
                    {queue.now_serving_ticket_number}
                  </p>
                )}
                <p className="queue-live-panel__serving-number--status text-destructive font-semibold">
                  Today&apos;s cut-off has been reached
                </p>
              </div>
            ) : (
              <p
                className={cn(
                  "queue-live-panel__serving-number",
                  !queue.now_serving_ticket_number &&
                    "queue-live-panel__serving-number--status",
                )}
              >
                {queue.now_serving_ticket_number ??
                  "No number is currently being served."}
              </p>
            )}
          </div>
        </div>

        {ticket?.status === "waiting" && ticket.position !== null && (
          <p className="font-medium">{getPositionCopy(ticket.position)}</p>
        )}

        {mode !== "compact" && queue.upcoming_ticket_numbers.length > 0 && (
          <section aria-labelledby={upcomingTicketNumbersId}>
            <p id={upcomingTicketNumbersId} className="queue-live-panel__label">
              Upcoming ticket numbers
            </p>
            <ul
              aria-labelledby={upcomingTicketNumbersId}
              className="queue-live-panel__upcoming-list"
            >
              {queue.upcoming_ticket_numbers.map((ticketNumber) => (
                <li key={ticketNumber}>{ticketNumber}</li>
              ))}
            </ul>
          </section>
        )}

        <Alert>
          <AlertTitle>Queue guidance</AlertTitle>
          <AlertDescription>{getStageGuidance(queue)}</AlertDescription>
        </Alert>

        {queue.cut_off_today && (
          <Alert variant="destructive">
            <AlertTitle>Today&apos;s cut-off has been reached</AlertTitle>
            <AlertDescription>
              Today&apos;s queue has reached its cut-off. Please check with the
              Cashier for next steps.
            </AlertDescription>
          </Alert>
        )}
      </CardContent>
      <CardFooter className="queue-live-panel__footer">
        <div className="flex flex-col gap-1">
          <p className="text-sm font-medium">Queue alerts</p>
          <p className="text-sm text-muted-foreground">
            {soundEnabled
              ? "Sound is on: this device rings when your ticket is called."
              : soundPreferred
                ? "Sound is on for this device; tap anywhere on this page once so your browser allows it to play."
                : "Turn on sound so this device rings when your ticket is called."}{" "}
            Vibration is best-effort; iOS Safari does not support web vibration.
          </p>
        </div>
        <Button
          type="button"
          variant={soundEnabled ? "secondary" : "outline"}
          onClick={soundEnabled ? disableSound : enableSound}
        >
          {soundEnabled ? (
            <VolumeXIcon data-icon="inline-start" />
          ) : (
            <Volume2Icon data-icon="inline-start" />
          )}
          {soundEnabled ? "Turn off sound" : "Turn on sound"}
        </Button>
        <p className="queue-live-panel__keep-open">
          Keep this page open and visible near the time of service. Browsers and
          operating systems may throttle background tabs.
        </p>
      </CardFooter>
    </Card>
  )
}
