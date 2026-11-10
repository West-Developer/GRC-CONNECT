"use client"

import { useState } from "react"

import {
  AlertDialog,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/features/components/ui/alert-dialog"
import { Alert, AlertDescription } from "@/features/components/ui/alert"
import { Button } from "@/features/components/ui/button"
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/features/components/ui/card"
import { Field, FieldError, FieldLabel } from "@/features/components/ui/field"
import { Textarea } from "@/features/components/ui/textarea"
import { useUpdateEnrollmentMutation } from "@/features/hooks/use-enrollment"
import type { Enrollment } from "@/features/schemas/enrollment-schema"
import { isApiClientError } from "@/features/services/api-client"

/** The statuses a student may still cancel from: before the Registrar approves. */
const CANCELLABLE_STATUSES: readonly Enrollment["status"][] = [
  "pending_program_head_approval",
  "pending_student_review",
  "awaiting_first_payment",
  "pending_registrar_approval",
]

/**
 * Lets a student undo a submission until the Registrar approves it, for
 * example after picking the wrong section (stakeholder Doc 14). Cancelling
 * gives the seats back and lets the student enroll again; once the
 * enrollment is approved the Registrar's office has to void it instead.
 */
export function EnrollmentCancelPanel({
  enrollment,
}: {
  enrollment: Enrollment
}) {
  const [open, setOpen] = useState(false)
  const [reason, setReason] = useState("")
  const [error, setError] = useState("")
  const mutation = useUpdateEnrollmentMutation()

  if (!CANCELLABLE_STATUSES.includes(enrollment.status)) {
    return null
  }

  const reasonMissing = reason.trim() === ""

  async function confirm() {
    if (reasonMissing) return
    setError("")
    try {
      await mutation.mutateAsync({
        id: enrollment.id,
        action: "student_cancel",
        reason: reason.trim(),
      })
      setOpen(false)
      setReason("")
    } catch (caught) {
      setError(
        isApiClientError(caught) && caught.message
          ? caught.message
          : "Your enrollment could not be cancelled. Check the connection and try again.",
      )
    }
  }

  return (
    <>
      <Card role="region" aria-label="Cancel enrollment">
        <CardHeader>
          <CardTitle level={2}>Picked the wrong section?</CardTitle>
          <CardDescription>
            You can cancel this enrollment until the Registrar approves it. Your
            seats are released and you can choose again.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <Button
            type="button"
            variant="outline"
            onClick={() => {
              setError("")
              setOpen(true)
            }}
          >
            Cancel enrollment
          </Button>
        </CardContent>
      </Card>

      <AlertDialog
        open={open}
        onOpenChange={(next) => {
          if (!mutation.isPending) setOpen(next)
        }}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Cancel this enrollment?</AlertDialogTitle>
            <AlertDialogDescription>
              This releases the seats you chose. The cancellation is recorded,
              and you can submit a new enrollment afterwards while enrollment is
              open.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <Field data-invalid={reasonMissing && reason !== ""}>
            <FieldLabel htmlFor="student-cancel-reason">
              Why are you cancelling?
            </FieldLabel>
            <Textarea
              id="student-cancel-reason"
              value={reason}
              onChange={(event) => setReason(event.target.value)}
              placeholder="e.g. I picked the wrong section."
              disabled={mutation.isPending}
              required
            />
            {reasonMissing && reason !== "" && (
              <FieldError>A reason is required.</FieldError>
            )}
          </Field>
          {error && (
            <Alert variant="destructive">
              <AlertDescription>{error}</AlertDescription>
            </Alert>
          )}
          <AlertDialogFooter>
            <AlertDialogCancel disabled={mutation.isPending}>
              Keep my enrollment
            </AlertDialogCancel>
            <Button
              type="button"
              variant="destructive"
              disabled={mutation.isPending || reasonMissing}
              onClick={() => void confirm()}
            >
              {mutation.isPending ? "Cancelling" : "Cancel enrollment"}
            </Button>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  )
}
