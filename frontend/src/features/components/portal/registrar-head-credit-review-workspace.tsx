"use client"

import { useState } from "react"

import { useAuth } from "@/features/auth/use-auth"
import { AsyncBoundary } from "@/features/components/portal/async-boundary"
import {
  CreditEntryCell,
  creditEntryTitle,
} from "@/features/components/portal/credit-entry-cell"
import { DataTable } from "@/features/components/portal/data-table"
import { StudentTorFiles } from "@/features/components/portal/tor-documents-panel"
import { WorkspacePage } from "@/features/components/portal/workspace-page"
import { Alert, AlertDescription } from "@/features/components/ui/alert"
import { Button } from "@/features/components/ui/button"
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/features/components/ui/card"
import { Textarea } from "@/features/components/ui/textarea"
import {
  useAllTransfereeCreditsQuery,
  useApproveAllStudentCreditsMutation,
  useDecideTransfereeCreditMutation,
} from "@/features/hooks/use-transferee-credits"
import { groupCreditsByStudent } from "@/features/lib/credit-mapping"
import { isApiClientError } from "@/features/services/api-client"
import type { TransfereeCredit } from "@/features/schemas/transferee-credit-schema"

interface ReviewStudent {
  student_id: number
  student_name: string
  student_number: string
}

/** The API's own sentence for a refused step when it gave one, else `fallback`. */
function apiMessage(error: unknown, fallback: string): string {
  if (isApiClientError(error)) {
    const first = Object.values(error.fieldErrors ?? {})[0]?.[0]
    if (first) return first
  }
  return fallback
}

function studentName(student: ReviewStudent): string {
  return student.student_name || student.student_number
}

/**
 * The Registrar Head's credit mapping workspace (ADR 0026, amended): the
 * students whose credits the Program Head has endorsed. Open one to see every
 * endorsed credit side by side (what the student entered, and what the Program
 * Head mapped it to), then approve all of that student's credits at once, or
 * return or reject a single one with a reason. The Head does not edit a mapping:
 * a credit that is wrong goes back to the Program Head.
 */
export function RegistrarHeadCreditReviewWorkspace() {
  const { session } = useAuth()
  const authorized = session?.role === "registrar_head"

  const [reviewing, setReviewing] = useState<ReviewStudent | null>(null)
  const [note, setNote] = useState("")

  // Every page: the queue is grouped by student, and a transferee with a full
  // transcript has dozens of rows, so one page of rows would hide students.
  const endorsedQuery = useAllTransfereeCreditsQuery(
    { status: "endorsed" },
    { enabled: authorized },
  )
  const waitingStudents = groupCreditsByStudent(endorsedQuery.data ?? [])

  return (
    <WorkspacePage
      title="Credit mappings"
      description="Review the credits the Program Head has sent, side by side. Approve all of a student's credits at once, or return or reject a single one."
      unauthorized={!authorized}
      lastUpdated={endorsedQuery.dataUpdatedAt}
    >
      {note && (
        <Alert>
          <AlertDescription>{note}</AlertDescription>
        </Alert>
      )}

      <Card>
        <CardHeader>
          <CardTitle level={2}>Students waiting for approval</CardTitle>
          <CardDescription>
            Students whose credits the Program Head has mapped and endorsed.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <AsyncBoundary
            query={{ ...endorsedQuery, data: waitingStudents }}
            isEmpty={(rows) => rows.length === 0}
            emptyMessage="No credit is waiting for your approval."
            loadingLabel="Loading credits…"
          >
            {(students) => (
              <DataTable
                caption="Students waiting for approval"
                rowKey={(student) => student.student_id}
                rows={students}
                columns={[
                  {
                    key: "student",
                    header: "Student",
                    render: (student) =>
                      student.student_name
                        ? `${student.student_name} (${student.student_number})`
                        : student.student_number,
                  },
                  {
                    key: "credits",
                    header: "Credits",
                    render: (student) =>
                      `${student.credits.length} ${student.credits.length === 1 ? "credit" : "credits"}`,
                  },
                  {
                    key: "actions",
                    header: "Actions",
                    render: (student) => (
                      <Button
                        type="button"
                        size="sm"
                        aria-label={`Review ${studentName(student)}`}
                        onClick={() => {
                          setNote("")
                          setReviewing({
                            student_id: student.student_id,
                            student_name: student.student_name,
                            student_number: student.student_number,
                          })
                        }}
                      >
                        Review
                      </Button>
                    ),
                  },
                ]}
              />
            )}
          </AsyncBoundary>
        </CardContent>
      </Card>

      {reviewing && (
        <StudentCreditReview
          key={reviewing.student_id}
          student={reviewing}
          onClose={() => setReviewing(null)}
          onApproved={(count) => {
            setNote(
              `Approved ${count} ${count === 1 ? "credit" : "credits"} of ${studentName(reviewing)}.`,
            )
            setReviewing(null)
          }}
        />
      )}
    </WorkspacePage>
  )
}

/** One student's endorsed credits, read-only, with the Head's three decisions. */
function StudentCreditReview({
  student,
  onClose,
  onApproved,
}: {
  student: ReviewStudent
  onClose: () => void
  onApproved: (count: number) => void
}) {
  const [deciding, setDeciding] = useState<{
    id: number
    action: "return" | "reject"
  } | null>(null)
  const [reason, setReason] = useState("")
  const [error, setError] = useState("")

  const creditsQuery = useAllTransfereeCreditsQuery({
    status: "endorsed",
    student_id: student.student_id,
  })
  const decideMutation = useDecideTransfereeCreditMutation()
  const approveAllMutation = useApproveAllStudentCreditsMutation()
  const busy = decideMutation.isPending || approveAllMutation.isPending

  const credits: TransfereeCredit[] = [...(creditsQuery.data ?? [])].sort(
    (a, b) => a.id - b.id,
  )

  const decide = async (credit: TransfereeCredit) => {
    if (deciding === null || !reason.trim()) return
    setError("")
    try {
      await decideMutation.mutateAsync({
        id: credit.id,
        input: { action: deciding.action, reason: reason.trim() },
      })
      setDeciding(null)
      setReason("")
    } catch (decideError) {
      setError(
        apiMessage(
          decideError,
          "The decision could not be saved. Check the connection and try again.",
        ),
      )
    }
  }

  const approveAll = async () => {
    setError("")
    try {
      const approved = await approveAllMutation.mutateAsync(student.student_id)
      onApproved(approved.length)
    } catch (approveError) {
      setError(
        apiMessage(
          approveError,
          "The credits could not be approved. Check the connection and try again.",
        ),
      )
    }
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle level={2}>Credits of {studentName(student)}</CardTitle>
        <CardDescription>
          {student.student_number}. Return a credit to the Program Head if its
          mapping is wrong; reject it if it cannot be credited.
        </CardDescription>
      </CardHeader>
      <CardContent className="grid gap-4">
        <StudentTorFiles studentId={student.student_id} headingLevel={3} />

        {error && (
          <Alert variant="destructive">
            <AlertDescription>{error}</AlertDescription>
          </Alert>
        )}

        {creditsQuery.isSuccess && credits.length === 0 && (
          <p className="text-sm text-muted-foreground">
            Nothing is waiting for your approval for this student.
          </p>
        )}

        {credits.length > 0 && (
          <>
            <div
              aria-hidden="true"
              className="hidden gap-4 px-3 text-xs font-medium text-muted-foreground md:grid md:grid-cols-[1fr_1fr]"
            >
              <span>Student&apos;s entry</span>
              <span>Mapped by the Program Head to</span>
            </div>
            <ul aria-label="Credits to review" className="grid gap-3">
              {credits.map((credit) => (
                <li
                  key={credit.id}
                  className="grid gap-3 rounded-lg border p-3 md:grid-cols-[1fr_1fr]"
                >
                  <CreditEntryCell credit={credit} />
                  <div className="grid content-start gap-2">
                    <div className="grid gap-0.5 text-sm">
                      <span className="font-medium">
                        {credit.subject_code} — {credit.subject_title ?? ""}
                      </span>
                      <span className="text-xs text-muted-foreground">
                        {credit.credited_units} units
                      </span>
                    </div>
                    <div className="flex flex-wrap gap-2">
                      <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        disabled={busy}
                        aria-label={`Return ${creditEntryTitle(credit)}`}
                        onClick={() => {
                          setDeciding({ id: credit.id, action: "return" })
                          setReason("")
                          setError("")
                        }}
                      >
                        Return
                      </Button>
                      <Button
                        type="button"
                        size="sm"
                        variant="destructive"
                        disabled={busy}
                        aria-label={`Reject ${creditEntryTitle(credit)}`}
                        onClick={() => {
                          setDeciding({ id: credit.id, action: "reject" })
                          setReason("")
                          setError("")
                        }}
                      >
                        Reject
                      </Button>
                    </div>
                    {deciding?.id === credit.id && (
                      <div className="grid gap-2">
                        <Textarea
                          id={`credit-decision-${credit.id}`}
                          aria-label={`Reason for ${creditEntryTitle(credit)}`}
                          placeholder={
                            deciding.action === "return"
                              ? "Why it goes back. The Program Head is told this reason."
                              : "Why it is rejected. The student is told this reason."
                          }
                          value={reason}
                          onChange={(event) => setReason(event.target.value)}
                          disabled={busy}
                        />
                        <div className="flex gap-2">
                          <Button
                            type="button"
                            size="sm"
                            variant={
                              deciding.action === "reject"
                                ? "destructive"
                                : "default"
                            }
                            disabled={busy || !reason.trim()}
                            onClick={() => void decide(credit)}
                          >
                            {deciding.action === "return"
                              ? "Confirm return"
                              : "Confirm rejection"}
                          </Button>
                          <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            disabled={busy}
                            onClick={() => setDeciding(null)}
                          >
                            Cancel
                          </Button>
                        </div>
                      </div>
                    )}
                  </div>
                </li>
              ))}
            </ul>
          </>
        )}

        <div className="flex flex-wrap items-center gap-2">
          <Button
            type="button"
            disabled={busy || credits.length === 0}
            onClick={() => void approveAll()}
          >
            {approveAllMutation.isPending
              ? "Approving…"
              : `Approve all (${credits.length})`}
          </Button>
          <Button
            type="button"
            variant="outline"
            disabled={busy}
            onClick={onClose}
          >
            Close
          </Button>
        </div>
      </CardContent>
    </Card>
  )
}
