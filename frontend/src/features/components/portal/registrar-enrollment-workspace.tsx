"use client"

import { useState } from "react"

import { useDebouncedValue } from "@/features/hooks/use-debounced-value"

import { useAuth } from "@/features/auth/use-auth"
import { AsyncBoundary } from "@/features/components/portal/async-boundary"
import { DataTable } from "@/features/components/portal/data-table"
import { EnrollmentReviewDialog } from "@/features/components/portal/enrollment-review-dialog"
import { Paginator } from "@/features/components/portal/paginator"
import { StudentInfoDialog } from "@/features/components/portal/student-info-dialog"
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
  CardFooter,
  CardHeader,
  CardTitle,
} from "@/features/components/ui/card"
import { Field, FieldLabel } from "@/features/components/ui/field"
import { Input } from "@/features/components/ui/input"
import { Textarea } from "@/features/components/ui/textarea"
import {
  useEnrollmentsListQuery,
  useUpdateEnrollmentMutation,
} from "@/features/hooks/use-enrollment"
import type { Enrollment } from "@/features/schemas/enrollment-schema"

const workspaceHeadings: Record<string, string> = {
  "enrollment-approvals": "Enrollment approvals",
}

const workspaceDescriptions: Record<string, string> = {
  "enrollment-approvals":
    "Give the final approval to every submitted enrollment, regular or irregular. Irregular submissions arrive here after the Program Head has approved them. An approved student then claims a queue number at the Cashier kiosk.",
}

type RegistrarAction = "registrar_approve" | "registrar_reject"

const actionLabel: Record<RegistrarAction, string> = {
  registrar_approve: "Approve",
  registrar_reject: "Reject",
}

function requiresReason(action: RegistrarAction) {
  return action === "registrar_reject"
}

/**
 * Which actions a row offers depends on the enrollment's status. Registrar
 * Staff and the Registrar Head own the approval checkpoint for every
 * enrollment (ADR 0030); an irregular one only reaches it after the Program
 * Head has approved, and until then it is shown here view-only. The Registrar
 * can only approve or reject here; there is no Void button (removed on request).
 * An approved enrollment that is still unpaid (`pending_payment`) can be
 * rejected instead of voided.
 */
function availableActions(
  enrollment: Enrollment,
  moduleId: string,
): readonly RegistrarAction[] {
  if (moduleId !== "enrollment-approvals") return []
  if (enrollment.status === "pending_registrar_approval") {
    return ["registrar_approve", "registrar_reject"]
  }
  if (enrollment.status === "pending_payment") return ["registrar_reject"]
  return []
}

function statusBadgeVariant(
  status: Enrollment["status"],
): "default" | "destructive" | "outline" {
  if (status === "rejected" || status === "cancelled") return "destructive"
  if (status === "enrolled") return "default"
  return "outline"
}

function EnrollmentQueueCard({
  enrollment,
  moduleId,
  mutationPending,
  onReview,
  onStartDecision,
}: {
  enrollment: Enrollment
  moduleId: string
  mutationPending: boolean
  onReview: (enrollment: Enrollment) => void
  onStartDecision: (enrollment: Enrollment, action: RegistrarAction) => void
}) {
  const actions = availableActions(enrollment, moduleId)

  return (
    <Card role="article" aria-label={`Enrollment #${enrollment.id}`}>
      <CardHeader>
        <CardTitle level={3}>Enrollment #{enrollment.id}</CardTitle>
        <Badge variant={statusBadgeVariant(enrollment.status)}>
          {enrollment.status_label}
        </Badge>
      </CardHeader>
      <CardContent>
        <dl className="grid gap-3 text-sm">
          <div className="grid gap-1">
            <dt className="text-muted-foreground">Student</dt>
            <dd className="flex flex-wrap items-center gap-2 font-medium">
              {enrollment.student_number}
              {enrollment.student_financial_status_label && (
                <Badge variant="secondary">
                  {enrollment.student_financial_status_label}
                </Badge>
              )}
            </dd>
          </div>
          <div className="grid gap-1">
            <dt className="text-muted-foreground">Units</dt>
            <dd className="flex flex-wrap items-center gap-2">
              {enrollment.total_units} units
              {enrollment.requires_overload_approval && (
                <Badge variant="outline">Overload</Badge>
              )}
            </dd>
          </div>
        </dl>
      </CardContent>
      <CardFooter className="flex-col items-stretch gap-2">
        <Button
          type="button"
          variant="outline"
          onClick={() => onReview(enrollment)}
        >
          {enrollment.status === "enrolled"
            ? "Check Schedule & Info"
            : "Review"}
        </Button>
        {actions.map((action) => (
          <Button
            key={action}
            type="button"
            variant={action === "registrar_approve" ? "default" : "destructive"}
            disabled={mutationPending}
            onClick={() => onStartDecision(enrollment, action)}
          >
            {actionLabel[action]}
          </Button>
        ))}
      </CardFooter>
    </Card>
  )
}

interface RegistrarEnrollmentWorkspaceProps {
  initialModuleId?: string
}

export function RegistrarEnrollmentWorkspace({
  initialModuleId = "enrollment-approvals",
}: RegistrarEnrollmentWorkspaceProps) {
  const { session } = useAuth()
  const authorized =
    session?.role === "registrar_staff" || session?.role === "registrar_head"
  // Only the Registrar Head may open the student's profile from the queue.
  const canViewStudentInfo = session?.role === "registrar_head"
  const [infoStudent, setInfoStudent] = useState<{
    studentId: number
    termId: number
  } | null>(null)
  const [page, setPage] = useState(1)
  const [pending, setPending] = useState<{
    enrollment: Enrollment
    action: RegistrarAction
  } | null>(null)
  const [reviewingEnrollment, setReviewingEnrollment] =
    useState<Enrollment | null>(null)
  const [reason, setReason] = useState("")
  const [overloadAcknowledged, setOverloadAcknowledged] = useState(false)
  const [error, setError] = useState("")
  const [search, setSearch] = useState("")
  const debouncedSearch = useDebouncedValue(search, 300)
  const [statusFilter, setStatusFilter] = useState<
    | "pending_program_head_approval"
    | "pending_registrar_approval"
    | "pending_payment"
    | "enrolled"
    | "rejected"
    | "all"
  >("pending_registrar_approval")
  const heading =
    workspaceHeadings[initialModuleId] ??
    workspaceHeadings["enrollment-approvals"]
  const description =
    workspaceDescriptions[initialModuleId] ??
    workspaceDescriptions["enrollment-approvals"]

  const queryStatus = statusFilter === "all" ? undefined : statusFilter

  const enrollmentsQuery = useEnrollmentsListQuery(
    {
      status: queryStatus,
      search: debouncedSearch.trim() || undefined,
      page,
      per_page: 20,
    },
    // Worked live by the Registrar: poll every 5s (the shared default is 15s, ADR 0029).
    { enabled: authorized, refetchIntervalMs: 5_000 },
  )
  const mutation = useUpdateEnrollmentMutation()
  const reasonRequired =
    pending !== null && requiresReason(pending.action) && !reason.trim()
  const overloadAcknowledgementRequired =
    pending !== null &&
    pending.action === "registrar_approve" &&
    pending.enrollment.requires_overload_approval &&
    !overloadAcknowledged

  const confirm = async () => {
    if (!pending || reasonRequired || overloadAcknowledgementRequired) return
    setError("")
    try {
      await mutation.mutateAsync({
        id: pending.enrollment.id,
        action: pending.action,
        reason: reason.trim() || undefined,
        overload_acknowledged: pending.enrollment.requires_overload_approval
          ? overloadAcknowledged
          : undefined,
      })
      setPending(null)
      setReason("")
      setOverloadAcknowledged(false)
    } catch {
      setError(
        "The enrollment decision could not be saved. It may already have been decided by someone else, or the connection dropped. The list was refreshed; check it and try again.",
      )
      void enrollmentsQuery.refetch()
    }
  }

  return (
    <WorkspacePage
      title={heading}
      description={description}
      unauthorized={!authorized}
      lastUpdated={enrollmentsQuery.dataUpdatedAt}
    >
      {error && (
        <Alert variant="destructive">
          <AlertDescription>{error}</AlertDescription>
        </Alert>
      )}
      <Card>
        <CardHeader className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <CardTitle level={2}>Enrollment queue</CardTitle>
          {initialModuleId === "enrollment-approvals" && (
            <div className="flex flex-wrap items-center gap-2">
              <Button
                type="button"
                size="sm"
                variant={
                  statusFilter === "pending_registrar_approval"
                    ? "default"
                    : "outline"
                }
                onClick={() => {
                  setStatusFilter("pending_registrar_approval")
                  setPage(1)
                }}
              >
                Pending review
              </Button>
              <Button
                type="button"
                size="sm"
                variant={
                  statusFilter === "pending_program_head_approval"
                    ? "default"
                    : "outline"
                }
                onClick={() => {
                  setStatusFilter("pending_program_head_approval")
                  setPage(1)
                }}
              >
                Awaiting Program Head
              </Button>
              <Button
                type="button"
                size="sm"
                variant={
                  statusFilter === "pending_payment" ? "default" : "outline"
                }
                onClick={() => {
                  setStatusFilter("pending_payment")
                  setPage(1)
                }}
              >
                Approved
              </Button>
              <Button
                type="button"
                size="sm"
                variant={statusFilter === "enrolled" ? "default" : "outline"}
                onClick={() => {
                  setStatusFilter("enrolled")
                  setPage(1)
                }}
              >
                Enrolled students
              </Button>
              <Button
                type="button"
                size="sm"
                variant={statusFilter === "rejected" ? "default" : "outline"}
                onClick={() => {
                  setStatusFilter("rejected")
                  setPage(1)
                }}
              >
                Rejected
              </Button>
              <Button
                type="button"
                size="sm"
                variant={statusFilter === "all" ? "default" : "outline"}
                onClick={() => {
                  setStatusFilter("all")
                  setPage(1)
                }}
              >
                All
              </Button>
            </div>
          )}
        </CardHeader>
        <CardContent>
          <div className="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div className="w-full max-w-sm">
              <Input
                type="search"
                placeholder="Search by student number, name, or email…"
                value={search}
                onChange={(e) => {
                  setSearch(e.target.value)
                  setPage(1)
                }}
                aria-label="Search enrollments"
              />
            </div>
            {search && (
              <Button
                type="button"
                variant="ghost"
                size="sm"
                onClick={() => {
                  setSearch("")
                  setPage(1)
                }}
              >
                Clear search
              </Button>
            )}
          </div>
          <AsyncBoundary
            query={{ ...enrollmentsQuery, data: enrollmentsQuery.data?.data }}
            isEmpty={(rows) => rows.length === 0}
            emptyMessage="No enrollments match this queue."
            loadingLabel="Loading the enrollment queue…"
          >
            {(enrollments) => (
              <DataTable
                caption="Enrollment queue"
                rowKey={(enrollment) => enrollment.id}
                rows={enrollments}
                renderCard={(enrollment) => (
                  <EnrollmentQueueCard
                    enrollment={enrollment}
                    moduleId={initialModuleId}
                    mutationPending={mutation.isPending}
                    onReview={setReviewingEnrollment}
                    onStartDecision={(target, action) => {
                      setPending({ enrollment: target, action })
                      setReason("")
                      setOverloadAcknowledged(false)
                      setError("")
                    }}
                  />
                )}
                columns={[
                  {
                    key: "student",
                    header: "Student",
                    render: (enrollment) => (
                      <span className="flex items-center gap-2">
                        {canViewStudentInfo ? (
                          <button
                            type="button"
                            className="text-left font-medium underline-offset-2 hover:underline focus-visible:underline"
                            onClick={() =>
                              setInfoStudent({
                                studentId: enrollment.student_id,
                                termId: enrollment.academic_term_id,
                              })
                            }
                          >
                            {enrollment.student_name ??
                              enrollment.student_number}
                            <span className="sr-only">
                              {" "}
                              ({enrollment.student_number}) — open student
                              information
                            </span>
                          </button>
                        ) : (
                          enrollment.student_number
                        )}
                        {enrollment.student_financial_status_label && (
                          <Badge variant="secondary">
                            {enrollment.student_financial_status_label}
                          </Badge>
                        )}
                      </span>
                    ),
                  },
                  {
                    key: "enrollment",
                    header: "Enrollment",
                    render: (enrollment) => `#${enrollment.id}`,
                  },
                  {
                    key: "status",
                    header: "Status",
                    render: (enrollment) => (
                      <Badge variant={statusBadgeVariant(enrollment.status)}>
                        {enrollment.status_label}
                      </Badge>
                    ),
                  },
                  {
                    key: "units",
                    header: "Units",
                    render: (enrollment) => (
                      <div className="flex items-center gap-2">
                        {enrollment.total_units}
                        {enrollment.requires_overload_approval && (
                          <Badge variant="outline">Overload</Badge>
                        )}
                        {enrollment.status ===
                        "pending_program_head_approval" ? (
                          <Badge variant="secondary" className="text-xs">
                            Awaiting Program Head · View only
                          </Badge>
                        ) : enrollment.status === "pending_student_review" ? (
                          <Badge variant="secondary" className="text-xs">
                            Awaiting Student · View only
                          </Badge>
                        ) : enrollment.is_irregular ||
                          enrollment.student_enrollment_category ===
                            "irregular" ? (
                          <Badge variant="secondary" className="text-xs">
                            Irregular
                          </Badge>
                        ) : null}
                      </div>
                    ),
                  },
                  {
                    key: "actions",
                    header: "Actions",
                    render: (enrollment) => (
                      <div className="flex flex-wrap gap-2">
                        <Button
                          type="button"
                          size="sm"
                          variant="outline"
                          onClick={() => setReviewingEnrollment(enrollment)}
                        >
                          {enrollment.status === "enrolled"
                            ? "Check Schedule & Info"
                            : "Review"}
                        </Button>
                        {availableActions(enrollment, initialModuleId).map(
                          (action) => (
                            <Button
                              key={action}
                              type="button"
                              size="sm"
                              variant={
                                action === "registrar_approve"
                                  ? "default"
                                  : "destructive"
                              }
                              disabled={mutation.isPending}
                              onClick={() => {
                                setPending({ enrollment, action })
                                setReason("")
                                setOverloadAcknowledged(false)
                                setError("")
                              }}
                            >
                              {actionLabel[action]}
                            </Button>
                          ),
                        )}
                      </div>
                    ),
                  },
                ]}
              />
            )}
          </AsyncBoundary>
          <div className="mt-4">
            <Paginator
              currentPage={enrollmentsQuery.data?.meta.current_page ?? 1}
              lastPage={enrollmentsQuery.data?.meta.last_page ?? 1}
              onPageChange={setPage}
            />
          </div>
        </CardContent>
      </Card>
      <EnrollmentReviewDialog
        enrollment={reviewingEnrollment}
        onOpenChange={(open) => {
          if (!open) setReviewingEnrollment(null)
        }}
      />
      {canViewStudentInfo && (
        <StudentInfoDialog
          studentId={infoStudent?.studentId ?? null}
          academicTermId={infoStudent?.termId ?? null}
          onOpenChange={(open) => {
            if (!open) setInfoStudent(null)
          }}
        />
      )}
      <AlertDialog
        open={pending !== null}
        onOpenChange={(open) => {
          if (!open && !mutation.isPending) setPending(null)
        }}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Confirm enrollment decision</AlertDialogTitle>
            <AlertDialogDescription>
              This decision is recorded in the operational audit log and
              notifies the student.
            </AlertDialogDescription>
          </AlertDialogHeader>
          {pending && requiresReason(pending.action) && (
            <Field data-invalid={reasonRequired}>
              <FieldLabel htmlFor="registrar-decision-reason">
                Reason
              </FieldLabel>
              <Textarea
                id="registrar-decision-reason"
                value={reason}
                onChange={(event) => setReason(event.target.value)}
                disabled={mutation.isPending}
                aria-describedby={
                  reasonRequired ? "registrar-decision-reason-error" : undefined
                }
              />
              {reasonRequired && (
                <p
                  id="registrar-decision-reason-error"
                  className="text-sm text-destructive"
                >
                  A reason is required to reject this enrollment.
                </p>
              )}
            </Field>
          )}
          {pending?.action === "registrar_reject" &&
            pending.enrollment.status === "pending_payment" && (
              <Alert>
                <AlertDescription>
                  This enrollment was already approved and is waiting for
                  payment. Rejecting it releases the student&apos;s seats and
                  cancels their waiting queue number.
                </AlertDescription>
              </Alert>
            )}
          {pending?.action === "registrar_approve" &&
            pending.enrollment.requires_overload_approval && (
              <Alert variant="destructive">
                <AlertDescription>
                  <p>
                    This enrollment totals {pending.enrollment.total_units}{" "}
                    units, exceeding the regular load. Approving it requires
                    explicit overload acknowledgement.
                  </p>
                  <label className="mt-2 flex items-center gap-2 text-sm font-normal">
                    <input
                      type="checkbox"
                      checked={overloadAcknowledged}
                      onChange={(event) =>
                        setOverloadAcknowledged(event.target.checked)
                      }
                      disabled={mutation.isPending}
                      aria-describedby="overload-acknowledgement-description"
                    />
                    <span id="overload-acknowledgement-description">
                      I acknowledge this enrollment exceeds the regular unit
                      load.
                    </span>
                  </label>
                </AlertDescription>
              </Alert>
            )}
          <AlertDialogFooter>
            <AlertDialogCancel disabled={mutation.isPending}>
              Cancel
            </AlertDialogCancel>
            <Button
              type="button"
              disabled={
                mutation.isPending ||
                reasonRequired ||
                overloadAcknowledgementRequired
              }
              onClick={() => void confirm()}
            >
              {mutation.isPending ? "Saving decision" : "Confirm decision"}
            </Button>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </WorkspacePage>
  )
}
