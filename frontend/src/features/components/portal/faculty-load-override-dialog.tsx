"use client"

import { useState } from "react"

import { Alert, AlertDescription } from "@/features/components/ui/alert"
import { Button } from "@/features/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/features/components/ui/dialog"
import { Field, FieldGroup, FieldLabel } from "@/features/components/ui/field"
import { Input } from "@/features/components/ui/input"
import { Textarea } from "@/features/components/ui/textarea"
import { apiErrorMessages } from "@/features/lib/api-error-messages"

export interface FacultyLoadOverrideTarget {
  professorId: number
  professorName: string
  /** The maximum in force today, to start the field from. */
  currentMaxUnits: number | null
  currentReason: string | null
}

interface FacultyLoadOverrideDialogProps {
  target: FacultyLoadOverrideTarget | null
  pending: boolean
  error: unknown
  onOpenChange: (open: boolean) => void
  onSubmit: (input: { maxUnits: number; reason: string }) => void
}

/**
 * Sets one professor's own maximum teaching load for the term, with the
 * reason it is needed (ADR 0033). Shared by the Program Head's Faculty Loading
 * page and the Dean's Faculty Load page.
 */
export function FacultyLoadOverrideDialog({
  target,
  pending,
  error,
  onOpenChange,
  onSubmit,
}: FacultyLoadOverrideDialogProps) {
  return (
    <Dialog open={target !== null} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[100dvh] overflow-y-auto rounded-none sm:max-h-[90dvh] sm:max-w-md sm:rounded-xl">
        {/* Keyed on the professor so the fields restart for each one. */}
        {target && (
          <OverrideForm
            key={target.professorId}
            target={target}
            pending={pending}
            error={error}
            onCancel={() => onOpenChange(false)}
            onSubmit={onSubmit}
          />
        )}
      </DialogContent>
    </Dialog>
  )
}

function OverrideForm({
  target,
  pending,
  error,
  onCancel,
  onSubmit,
}: {
  target: FacultyLoadOverrideTarget
  pending: boolean
  error: unknown
  onCancel: () => void
  onSubmit: (input: { maxUnits: number; reason: string }) => void
}) {
  const [maxUnits, setMaxUnits] = useState(
    target.currentMaxUnits === null ? "" : String(target.currentMaxUnits),
  )
  const [reason, setReason] = useState(target.currentReason ?? "")
  const parsedUnits = Number(maxUnits)
  const unitsValid =
    maxUnits.trim() !== "" &&
    Number.isFinite(parsedUnits) &&
    parsedUnits > 0 &&
    parsedUnits <= 99
  const reasonValid = reason.trim().length >= 3

  return (
    <>
      <DialogHeader>
        <DialogTitle>Set max load for {target.professorName}</DialogTitle>
        <DialogDescription>
          This professor&apos;s own maximum replaces the normal limit for this
          term only. Use it when no other professor is available and a load has
          to go higher (or lower). The reason is kept in the audit log.
        </DialogDescription>
      </DialogHeader>

      <FieldGroup>
        <Field data-invalid={maxUnits !== "" && !unitsValid}>
          <FieldLabel htmlFor="faculty-load-override-units">
            Maximum units
          </FieldLabel>
          <Input
            id="faculty-load-override-units"
            type="number"
            min="1"
            max="99"
            step="0.5"
            value={maxUnits}
            onChange={(event) => setMaxUnits(event.target.value)}
            disabled={pending}
          />
        </Field>
        <Field>
          <FieldLabel htmlFor="faculty-load-override-reason">Reason</FieldLabel>
          <Textarea
            id="faculty-load-override-reason"
            value={reason}
            onChange={(event) => setReason(event.target.value)}
            placeholder="e.g. No other professor can teach Networking this term"
            disabled={pending}
          />
        </Field>
      </FieldGroup>

      {error !== null && error !== undefined && (
        <Alert variant="destructive">
          <AlertDescription>
            {apiErrorMessages(
              error,
              "The maximum load could not be saved. Try again.",
            ).map((message) => (
              <p key={message}>{message}</p>
            ))}
          </AlertDescription>
        </Alert>
      )}

      <DialogFooter>
        <Button
          type="button"
          variant="outline"
          onClick={onCancel}
          disabled={pending}
        >
          Cancel
        </Button>
        <Button
          type="button"
          disabled={pending || !unitsValid || !reasonValid}
          onClick={() =>
            onSubmit({ maxUnits: parsedUnits, reason: reason.trim() })
          }
        >
          {pending ? "Saving…" : "Save max load"}
        </Button>
      </DialogFooter>
    </>
  )
}
