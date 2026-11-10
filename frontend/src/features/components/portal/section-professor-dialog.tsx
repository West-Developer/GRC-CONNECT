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
import { SearchableCombobox } from "@/features/components/ui/searchable-combobox"
import { apiErrorMessages } from "@/features/lib/api-error-messages"

export interface SectionProfessorTarget {
  sectionId: number
  /** e.g. "IT101 · Section A" */
  sectionLabel: string
  currentProfessorId: number | null
  currentProfessorName: string | null
}

interface SectionProfessorDialogProps {
  target: SectionProfessorTarget | null
  /** Professors of the college the Dean may pick from. */
  professors: readonly { id: number; name: string; detail?: string }[]
  pending: boolean
  error: unknown
  onOpenChange: (open: boolean) => void
  /** `null` removes the professor. */
  onSubmit: (input: { professorId: number | null; reason: string }) => void
}

/**
 * Picks who teaches one section (the Dean's assignment, ADR 0033). Only the
 * professor changes; the server refuses one who is already teaching at that
 * time and shows why.
 */
export function SectionProfessorDialog({
  target,
  professors,
  pending,
  error,
  onOpenChange,
  onSubmit,
}: SectionProfessorDialogProps) {
  return (
    <Dialog open={target !== null} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[100dvh] overflow-y-auto rounded-none sm:max-h-[90dvh] sm:max-w-md sm:rounded-xl">
        {/* Keyed on the section so the choice restarts for each one. */}
        {target && (
          <ProfessorForm
            key={target.sectionId}
            target={target}
            professors={professors}
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

function ProfessorForm({
  target,
  professors,
  pending,
  error,
  onCancel,
  onSubmit,
}: {
  target: SectionProfessorTarget
  professors: SectionProfessorDialogProps["professors"]
  pending: boolean
  error: unknown
  onCancel: () => void
  onSubmit: SectionProfessorDialogProps["onSubmit"]
}) {
  const [professorId, setProfessorId] = useState(
    target.currentProfessorId === null ? "" : String(target.currentProfessorId),
  )
  const [reason, setReason] = useState("")
  const changed =
    (professorId === "" ? null : Number(professorId)) !==
    target.currentProfessorId

  return (
    <>
      <DialogHeader>
        <DialogTitle>Professor for {target.sectionLabel}</DialogTitle>
        <DialogDescription>
          {target.currentProfessorName
            ? `Now taught by ${target.currentProfessorName}. `
            : "No professor is assigned yet. "}
          Only the professor changes. You are told if the professor is already
          teaching at that time.
        </DialogDescription>
      </DialogHeader>

      <FieldGroup>
        <Field>
          <FieldLabel htmlFor="section-professor">Professor</FieldLabel>
          <SearchableCombobox
            id="section-professor"
            label="Professor"
            options={[
              { value: "", label: "No professor" },
              ...professors.map((professor) => ({
                value: String(professor.id),
                label: professor.detail
                  ? `${professor.name} · ${professor.detail}`
                  : professor.name,
              })),
            ]}
            value={professorId}
            onValueChange={setProfessorId}
            placeholder="Search professor"
            emptyMessage="No matching professor."
          />
        </Field>
        <Field>
          <FieldLabel htmlFor="section-professor-reason">
            Reason (needed when a generated assignment is changed)
          </FieldLabel>
          <Input
            id="section-professor-reason"
            value={reason}
            onChange={(event) => setReason(event.target.value)}
            disabled={pending}
          />
        </Field>
      </FieldGroup>

      {error !== null && error !== undefined && (
        <Alert variant="destructive">
          <AlertDescription>
            {apiErrorMessages(
              error,
              "The professor could not be changed. Try again.",
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
          disabled={pending || !changed}
          onClick={() =>
            onSubmit({
              professorId: professorId === "" ? null : Number(professorId),
              reason: reason.trim(),
            })
          }
        >
          {pending
            ? "Saving…"
            : professorId === ""
              ? "Remove professor"
              : "Save professor"}
        </Button>
      </DialogFooter>
    </>
  )
}
