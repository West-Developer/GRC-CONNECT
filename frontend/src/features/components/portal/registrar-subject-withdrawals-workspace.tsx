"use client"

import { useState } from "react"
import { toast } from "sonner"

import { useAuth } from "@/features/auth/use-auth"
import { AsyncBoundary } from "@/features/components/portal/async-boundary"
import { WorkspacePage } from "@/features/components/portal/workspace-page"
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
import { Badge } from "@/features/components/ui/badge"
import { Button } from "@/features/components/ui/button"
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/features/components/ui/card"
import { Checkbox } from "@/features/components/ui/checkbox"
import { Field, FieldError, FieldLabel } from "@/features/components/ui/field"
import { Input } from "@/features/components/ui/input"
import { Textarea } from "@/features/components/ui/textarea"
import {
  useSubjectWithdrawalOverviewQuery,
  useWithdrawSubjectsMutation,
} from "@/features/hooks/use-subject-withdrawals"
import type { AcademicRecordStudentLookup } from "@/features/schemas/academic-record-schema"
import { isApiClientError } from "@/features/services/api-client"
import { searchAcademicRecordStudents } from "@/features/services/academic-record-service"

/** The API's own sentence for a refused withdrawal when it gave one, else `fallback`. */
function apiMessage(error: unknown, fallback: string): string {
  if (isApiClientError(error)) {
    const first = Object.values(error.fieldErrors ?? {})[0]?.[0]
    if (first) return first
    if (error.message) return error.message
  }
  return fallback
}

/**
 * The Registrar Head's subject withdrawal. Find a student, tick the enrolled
 * subjects to withdraw, give a reason and confirm: each subject leaves the
 * student's enrollment, its seat is released, the student is notified and the
 * subject shows as W (Withdrawn) on their Prospectus. Nothing is refunded and
 * no fee changes here. The reason goes to the audit log.
 */
export function RegistrarSubjectWithdrawalsWorkspace() {
  const { session } = useAuth()
  const authorized = session?.role === "registrar_head"

  const [searchInput, setSearchInput] = useState("")
  const [searching, setSearching] = useState(false)
  const [searchError, setSearchError] = useState("")
  const [candidates, setCandidates] = useState<
    AcademicRecordStudentLookup[] | null
  >(null)
  const [student, setStudent] = useState<AcademicRecordStudentLookup | null>(
    null,
  )

  const [picked, setPicked] = useState<number[]>([])
  const [reason, setReason] = useState("")
  const [confirming, setConfirming] = useState(false)
  const [error, setError] = useState("")

  const overviewQuery = useSubjectWithdrawalOverviewQuery(
    student?.student_id ?? null,
  )
  const withdraw = useWithdrawSubjectsMutation()

  const reasonTooShort = reason.trim().length < 3

  async function search(event: React.FormEvent) {
    event.preventDefault()
    const term = searchInput.trim()
    if (term.length < 2) {
      setSearchError("Type at least 2 characters of a student number or name.")
      return
    }
    setSearchError("")
    setSearching(true)
    try {
      setCandidates(
        await searchAcademicRecordStudents({
          search: term,
          by: "all",
          limit: 10,
        }),
      )
    } catch {
      setSearchError("The student search failed. Try again.")
    } finally {
      setSearching(false)
    }
  }

  function chooseStudent(next: AcademicRecordStudentLookup) {
    setStudent(next)
    setCandidates(null)
    setPicked([])
    setReason("")
    setError("")
  }

  function toggle(id: number, checked: boolean) {
    setPicked((current) =>
      checked ? [...current, id] : current.filter((value) => value !== id),
    )
  }

  async function confirmWithdrawal() {
    if (!student || picked.length === 0 || reasonTooShort) return
    setError("")
    try {
      await withdraw.mutateAsync({
        studentId: student.student_id,
        input: { enrollment_subject_ids: picked, reason: reason.trim() },
      })
      toast.success(
        `${picked.length} subject${picked.length === 1 ? "" : "s"} withdrawn. Shown as W on the Prospectus.`,
      )
      setConfirming(false)
      setPicked([])
      setReason("")
    } catch (caught) {
      setConfirming(false)
      setError(
        apiMessage(caught, "The withdrawal could not be saved. Try again."),
      )
    }
  }

  return (
    <WorkspacePage
      title="Subject withdrawals"
      description="Withdraw a student from enrolled subjects. A withdrawn subject shows as W on their prospectus."
      unauthorized={!authorized}
      lastUpdated={overviewQuery.dataUpdatedAt}
    >
      <Card>
        <CardHeader>
          <CardTitle>Find a student</CardTitle>
          <CardDescription>Search by student number or name.</CardDescription>
        </CardHeader>
        <CardContent className="grid gap-3">
          <form
            onSubmit={(event) => void search(event)}
            className="flex flex-wrap items-end gap-2"
          >
            <Field
              className="min-w-56 flex-1"
              data-invalid={searchError !== ""}
            >
              <FieldLabel htmlFor="subject-withdrawal-search">
                Student number or name
              </FieldLabel>
              <Input
                id="subject-withdrawal-search"
                value={searchInput}
                onChange={(event) => setSearchInput(event.target.value)}
                disabled={searching}
              />
              {searchError && <FieldError>{searchError}</FieldError>}
            </Field>
            <Button type="submit" disabled={searching}>
              {searching ? "Searching" : "Search"}
            </Button>
          </form>

          {candidates !== null &&
            (candidates.length === 0 ? (
              <p className="text-sm text-muted-foreground">
                No student matches that search.
              </p>
            ) : (
              <ul className="grid gap-2">
                {candidates.map((candidate) => (
                  <li key={candidate.student_id}>
                    <Button
                      type="button"
                      variant="outline"
                      className="h-auto w-full justify-start whitespace-normal py-2 text-left"
                      onClick={() => chooseStudent(candidate)}
                    >
                      <span className="grid gap-0.5">
                        <span className="font-medium">{candidate.name}</span>
                        <span className="text-xs text-muted-foreground">
                          {candidate.student_number} · {candidate.program_code}
                        </span>
                      </span>
                    </Button>
                  </li>
                ))}
              </ul>
            ))}
        </CardContent>
      </Card>

      {student && (
        <Card>
          <CardHeader>
            <CardTitle>{student.name}</CardTitle>
            <CardDescription>
              {student.student_number} · {student.program_code}
            </CardDescription>
          </CardHeader>
          <CardContent className="grid gap-4">
            {error && (
              <Alert variant="destructive">
                <AlertDescription>{error}</AlertDescription>
              </Alert>
            )}

            <AsyncBoundary
              query={overviewQuery}
              isEmpty={(data) =>
                data.withdrawable.length === 0 && data.withdrawn.length === 0
              }
              emptyMessage="This student has no enrolled subjects to withdraw and none withdrawn."
              loadingLabel="Loading the student's subjects…"
            >
              {(data) => (
                <>
                  <section
                    aria-labelledby="withdrawable-heading"
                    className="grid gap-2"
                  >
                    <h3
                      id="withdrawable-heading"
                      className="text-sm font-semibold"
                    >
                      Enrolled subjects
                    </h3>
                    {data.withdrawable.length === 0 ? (
                      <p className="text-sm text-muted-foreground">
                        This student has no enrolled subject that can still be
                        withdrawn.
                      </p>
                    ) : (
                      <>
                        <ul className="grid gap-2">
                          {data.withdrawable.map((subject) => {
                            const id = `withdraw-subject-${subject.enrollment_subject_id}`
                            return (
                              <li
                                key={subject.enrollment_subject_id}
                                className="flex items-start gap-3 rounded-md border p-2 text-sm"
                              >
                                <Checkbox
                                  id={id}
                                  checked={picked.includes(
                                    subject.enrollment_subject_id,
                                  )}
                                  disabled={withdraw.isPending}
                                  onCheckedChange={(checked) =>
                                    toggle(
                                      subject.enrollment_subject_id,
                                      checked === true,
                                    )
                                  }
                                  className="mt-0.5"
                                />
                                <label htmlFor={id} className="grid gap-0.5">
                                  <span className="font-medium">
                                    {subject.subject_code} —{" "}
                                    {subject.subject_title}
                                  </span>
                                  <span className="text-xs text-muted-foreground">
                                    Section {subject.section_code} ·{" "}
                                    {subject.units} units · {subject.term_label}
                                  </span>
                                </label>
                              </li>
                            )
                          })}
                        </ul>

                        <Field data-invalid={reasonTooShort && reason !== ""}>
                          <FieldLabel htmlFor="subject-withdrawal-reason">
                            Reason for the withdrawal
                          </FieldLabel>
                          <Textarea
                            id="subject-withdrawal-reason"
                            value={reason}
                            onChange={(event) => setReason(event.target.value)}
                            disabled={withdraw.isPending}
                          />
                          {reasonTooShort && reason !== "" && (
                            <FieldError>
                              Give a reason of at least 3 characters.
                            </FieldError>
                          )}
                        </Field>

                        <div>
                          <Button
                            type="button"
                            variant="destructive"
                            disabled={
                              picked.length === 0 ||
                              reasonTooShort ||
                              withdraw.isPending
                            }
                            onClick={() => setConfirming(true)}
                          >
                            Withdraw {picked.length || ""} selected subject
                            {picked.length === 1 ? "" : "s"}
                          </Button>
                        </div>
                      </>
                    )}
                  </section>

                  <section
                    aria-labelledby="withdrawn-heading"
                    className="grid gap-2"
                  >
                    <h3
                      id="withdrawn-heading"
                      className="text-sm font-semibold"
                    >
                      Already withdrawn
                    </h3>
                    {data.withdrawn.length === 0 ? (
                      <p className="text-sm text-muted-foreground">
                        No subject has been withdrawn for this student.
                      </p>
                    ) : (
                      <ul className="grid gap-2">
                        {data.withdrawn.map((subject) => (
                          <li
                            key={subject.academic_grade_id}
                            className="grid gap-0.5 rounded-md border p-2 text-sm"
                          >
                            <span className="font-medium">
                              {subject.subject_code} — {subject.subject_title}{" "}
                              <Badge variant="secondary">W · Withdrawn</Badge>
                            </span>
                            <span className="text-xs text-muted-foreground">
                              {subject.term_label}
                              {subject.section_code
                                ? ` · Section ${subject.section_code}`
                                : ""}{" "}
                              · by {subject.withdrawn_by}
                            </span>
                            {subject.reason && (
                              <span className="text-xs">
                                Reason: {subject.reason}
                              </span>
                            )}
                          </li>
                        ))}
                      </ul>
                    )}
                  </section>
                </>
              )}
            </AsyncBoundary>
          </CardContent>
        </Card>
      )}

      <AlertDialog
        open={confirming}
        onOpenChange={(open) => {
          if (!open && !withdraw.isPending) setConfirming(false)
        }}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Withdraw the selected subjects?</AlertDialogTitle>
            <AlertDialogDescription>
              {picked.length} subject{picked.length === 1 ? "" : "s"} will be
              taken off {student?.name}&rsquo;s enrollment, the seat
              {picked.length === 1 ? " is" : "s are"} released and the student
              is notified. {picked.length === 1 ? "It shows" : "They show"} as W
              on the Prospectus and must be taken again. Fees are not changed.
              This is recorded in the audit log.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={withdraw.isPending}>
              Cancel
            </AlertDialogCancel>
            <Button
              type="button"
              variant="destructive"
              disabled={withdraw.isPending}
              onClick={() => void confirmWithdrawal()}
            >
              {withdraw.isPending ? "Withdrawing" : "Confirm withdrawal"}
            </Button>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </WorkspacePage>
  )
}
