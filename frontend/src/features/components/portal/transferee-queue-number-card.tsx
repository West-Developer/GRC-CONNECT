"use client"

import { useAuth } from "@/features/auth/use-auth"
import { StudentQueueLivePanel } from "@/features/components/queue/student-queue-live-panel"
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/features/components/ui/card"
import { useQueueCallAlert } from "@/features/hooks/use-queue-call-alert"
import { useStudentQueueQuery } from "@/features/hooks/use-student-queue"

/**
 * A transferee is given a queue number the moment their credit mapping closes (ADR 0043,
 * amended 2026-10-05), before they have chosen any subjects, so there is no enrollment card
 * yet to show it in. This shows the number, and the live queue, above the subject picker.
 * Nothing renders until the Cashier's queue holds a number for the student.
 */
export function TransfereeQueueNumberCard() {
  const { session } = useAuth()
  const queueQuery = useStudentQueueQuery({
    viewerId: session?.userId ?? null,
    enabled: session?.role === "student",
  })
  const ticket = queueQuery.data?.ticket ?? null
  // Owned here, above the card, so the call sound survives the card re-rendering.
  const callAlert = useQueueCallAlert(ticket, { defaultSoundOn: true })

  if (queueQuery.data === undefined || ticket === null) return null

  return (
    <Card role="region" aria-label="Your queue number">
      <CardHeader>
        <CardTitle level={2}>Your queue number</CardTitle>
        <CardDescription>
          Choose your subjects below, then take this number to the Cashier and
          make your first payment. The Registrar approves your enrollment once
          you have paid.
        </CardDescription>
      </CardHeader>
      <CardContent>
        <StudentQueueLivePanel
          queue={queueQuery.data}
          mode="default"
          alert={callAlert}
        />
      </CardContent>
    </Card>
  )
}
