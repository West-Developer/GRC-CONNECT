"use client"

import { useState } from "react"

import { Alert, AlertDescription } from "@/features/components/ui/alert"
import { Badge } from "@/features/components/ui/badge"
import { Button } from "@/features/components/ui/button"
import { Field, FieldError, FieldLabel } from "@/features/components/ui/field"
import { Skeleton } from "@/features/components/ui/skeleton"
import { Textarea } from "@/features/components/ui/textarea"
import {
  useGrantSubjectWaiverMutation,
  useRevokeSubjectWaiverMutation,
  useSubjectWaiverOverviewQuery,
} from "@/features/hooks/use-subject-waivers"
import type { BlockedSubject } from "@/features/schemas/subject-waiver-schema"
import { isApiClientError } from "@/features/services/api-client"

/**
 * The Registrar Head's prerequisite waivers for one student in one term
 * (stakeholder Doc 14, ADR 0031). It lists the waivers already granted and the
 * subjects the student is blocked from only because of a prerequisite (a
 * failed prerequisite included), and lets the Head waive or take one back. A
 * reason is required and goes to the audit log.
 */
export function PrerequisiteWaiverSection({
  studentId,
  academicTermId,
}: {
  studentId: number
  academicTermId: number
}) {
  const query = useSubjectWaiverOverviewQuery(studentId, academicTermId)
  const grant = useGrantSubjectWaiverMutation()
  const revoke = useRevokeSubjectWaiverMutation()
  const [openSubjectId, setOpenSubjectId] = useState<number | null>(null)
  const [reason, setReason] = useState("")
  const [error, setError] = useState("")

  const reasonTooShort = reason.trim().length < 3

  async function submit(subject: BlockedSubject) {
    if (reasonTooShort) return
    setError("")
    try {
      await grant.mutateAsync({
        studentId,
        input: {
          subject_id: subject.subject_id,
          academic_term_id: academicTermId,
          reason: reason.trim(),
        },
      })
      setOpenSubjectId(null)
      setReason("")
    } catch (caught) {
      setError(
        isApiClientError(caught) && caught.message
          ? caught.message
          : "The waiver could not be saved. Try again.",
      )
    }
  }

  async function takeBack(waiverId: number) {
    setError("")
    try {
      await revoke.mutateAsync(waiverId)
    } catch {
      setError("The waiver could not be taken back. Try again.")
    }
  }

  return (
    <section
      aria-labelledby="waiver-heading"
      className="grid gap-3 border-t pt-4"
    >
      <div className="grid gap-1">
        <h3 id="waiver-heading" className="text-sm font-semibold">
          Prerequisite waivers
        </h3>
        <p className="text-xs text-muted-foreground">
          Let this student take a subject this term although a prerequisite is
          not met, including one they failed. Each waiver needs a reason and is
          recorded in the audit log.
        </p>
      </div>

      {error && (
        <Alert variant="destructive">
          <AlertDescription>{error}</AlertDescription>
        </Alert>
      )}

      {query.isPending ? (
        <div role="status" aria-label="Loading waivers" className="grid gap-2">
          <Skeleton className="h-5 w-2/3" />
          <Skeleton className="h-5 w-1/2" />
        </div>
      ) : query.isError ? (
        <Alert variant="destructive">
          <AlertDescription>
            The prerequisite waivers could not be loaded.
          </AlertDescription>
        </Alert>
      ) : (
        <>
          <div className="grid gap-2">
            <h4 className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
              Granted this term
            </h4>
            {query.data.data.length === 0 ? (
              <p className="text-sm text-muted-foreground">
                No waivers have been granted this term.
              </p>
            ) : (
              <ul className="grid gap-2">
                {query.data.data.map((waiver) => (
                  <li
                    key={waiver.id}
                    className="flex flex-wrap items-start justify-between gap-2 rounded-md border p-2 text-sm"
                  >
                    <div className="grid gap-0.5">
                      <span className="font-medium">
                        {waiver.subject_code} — {waiver.subject_title}{" "}
                        <Badge
                          variant={waiver.is_active ? "default" : "outline"}
                        >
                          {waiver.is_active ? "Active" : "Revoked"}
                        </Badge>
                      </span>
                      <span className="text-xs text-muted-foreground">
                        {waiver.reason}
                      </span>
                    </div>
                    {waiver.is_active && (
                      <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        disabled={revoke.isPending}
                        onClick={() => void takeBack(waiver.id)}
                      >
                        Take back
                        <span className="sr-only"> {waiver.subject_code}</span>
                      </Button>
                    )}
                  </li>
                ))}
              </ul>
            )}
          </div>

          <div className="grid gap-2">
            <h4 className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
              Blocked by a prerequisite
            </h4>
            {query.data.meta.blocked_subjects.length === 0 ? (
              <p className="text-sm text-muted-foreground">
                No subject is blocked only by a prerequisite.
              </p>
            ) : (
              <ul className="grid gap-2">
                {query.data.meta.blocked_subjects.map((subject) => (
                  <li
                    key={subject.subject_id}
                    className="grid gap-2 rounded-md border p-2 text-sm"
                  >
                    <div className="flex flex-wrap items-start justify-between gap-2">
                      <div className="grid gap-0.5">
                        <span className="font-medium">
                          {subject.subject_code} — {subject.subject_title}
                        </span>
                        {subject.reasons.map((message) => (
                          <span
                            key={message}
                            className="text-xs text-muted-foreground"
                          >
                            {message}
                          </span>
                        ))}
                      </div>
                      {openSubjectId !== subject.subject_id && (
                        <Button
                          type="button"
                          size="sm"
                          onClick={() => {
                            setOpenSubjectId(subject.subject_id)
                            setReason("")
                            setError("")
                          }}
                        >
                          Waive
                          <span className="sr-only">
                            {" "}
                            {subject.subject_code}
                          </span>
                        </Button>
                      )}
                    </div>
                    {openSubjectId === subject.subject_id && (
                      <div className="grid gap-2">
                        <Field data-invalid={reasonTooShort && reason !== ""}>
                          <FieldLabel
                            htmlFor={`waiver-reason-${subject.subject_id}`}
                          >
                            Reason for waiving {subject.subject_code}
                          </FieldLabel>
                          <Textarea
                            id={`waiver-reason-${subject.subject_id}`}
                            value={reason}
                            onChange={(event) => setReason(event.target.value)}
                            disabled={grant.isPending}
                          />
                          {reasonTooShort && reason !== "" && (
                            <FieldError>
                              Give a reason of at least 3 characters.
                            </FieldError>
                          )}
                        </Field>
                        <div className="flex flex-wrap gap-2">
                          <Button
                            type="button"
                            size="sm"
                            disabled={grant.isPending || reasonTooShort}
                            onClick={() => void submit(subject)}
                          >
                            {grant.isPending ? "Saving" : "Grant waiver"}
                          </Button>
                          <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            disabled={grant.isPending}
                            onClick={() => setOpenSubjectId(null)}
                          >
                            Cancel
                          </Button>
                        </div>
                      </div>
                    )}
                  </li>
                ))}
              </ul>
            )}
          </div>
        </>
      )}
    </section>
  )
}
