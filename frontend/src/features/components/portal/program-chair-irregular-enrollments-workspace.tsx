"use client"

import { useMutation, useQueryClient } from "@tanstack/react-query"
import { useState } from "react"

import { useAuth } from "@/features/auth/use-auth"
import { AsyncBoundary } from "@/features/components/portal/async-boundary"
import {
  DataTable,
  type DataTableColumn,
} from "@/features/components/portal/data-table"
import { EnrollmentReviewDialog } from "@/features/components/portal/enrollment-review-dialog"
import { Paginator } from "@/features/components/portal/paginator"
import { ProspectusDocument } from "@/features/components/portal/prospectus-document"
import { ProgramChairEnrollmentTabs } from "@/features/components/portal/program-chair-enrollment-tabs"
import { WorkspacePage } from "@/features/components/portal/workspace-page"
import { Alert, AlertDescription } from "@/features/components/ui/alert"
import {
  AlertDialog,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/features/components/ui/alert-dialog"
import { Badge } from "@/features/components/ui/badge"
import { Button } from "@/features/components/ui/button"
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
} from "@/features/components/ui/card"
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
} from "@/features/components/ui/dialog"
import { Field, FieldError, FieldLabel } from "@/features/components/ui/field"
import { Input } from "@/features/components/ui/input"
import { Textarea } from "@/features/components/ui/textarea"
import { useEnrollmentsListQuery } from "@/features/hooks/use-enrollment"
import { formatYearLevelOrdinal } from "@/features/lib/curriculum-ordinal"
import type { Enrollment } from "@/features/schemas/enrollment-schema"
import { updateEnrollment } from "@/features/services/enrollment-service"

type DecisionAction = "program_head_approve" | "program_head_reject"

function statusBadgeVariant(
  status: Enrollment["status"],
): "default" | "destructive" | "outline" {
  if (status === "rejected" || status === "cancelled") return "destructive"
  if (status === "enrolled") return "default"
  return "outline"
}

export function ProgramChairIrregularEnrollmentsWorkspace() {
  const { session } = useAuth()
  const authorized = session?.role === "program_chair"
  const [page, setPage] = useState(1)
  const [search, setSearch] = useState("")
  const [statusFilter, setStatusFilter] = useState<
    | "pending_program_head_approval"
    | "pending_student_review"
    | "pending_registrar_approval"
    | "pending_payment"
    | "enrolled"
    | "rejected"
    | "all"
  >("pending_program_head_approval")
  // Narrow the list to the students who submitted more units than the maximum.
  const [overMaximumOnly, setOverMaximumOnly] = useState(false)

  const [reviewingEnrollment, setReviewingEnrollment] =
    useState<Enrollment | null>(null)
  const [prospectusStudent, setProspectusStudent] = useState<{
    id: number
    name: string
    studentNumber: string
  } | null>(null)

  const [pendingDecision, setPendingDecision] = useState<{
    enrollment: Enrollment
    action: DecisionAction
  } | null>(null)
  const [reason, setReason] = useState("")
  const [comment, setComment] = useState("")
  const [overloadAcknowledged, setOverloadAcknowledged] = useState(false)
  const [error, setError] = useState("")

  const queryClient = useQueryClient()

  const enrollmentsQuery = useEnrollmentsListQuery(
    {
      page,
      status: statusFilter === "all" ? undefined : statusFilter,
      ...(overMaximumOnly ? { exceeds_unit_maximum: true } : {}),
    },
    { enabled: authorized },
  )

  const mutation = useMutation({
    mutationFn: async ({
      enrollmentId,
      action,
      decisionReason,
      decisionComment,
      overloadAcknowledged,
    }: {
      enrollmentId: number
      action: DecisionAction
      decisionReason?: string
      decisionComment?: string
      overloadAcknowledged?: boolean
    }) => {
      return updateEnrollment(enrollmentId, {
        action,
        reason: decisionReason,
        overload_acknowledged: overloadAcknowledged,
        program_head_comment: decisionComment,
      })
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        queryKey: ["enrollments"],
      })
      setPendingDecision(null)
      setReason("")
      setComment("")
      setOverloadAcknowledged(false)
      setError("")
    },
    onError: (err: unknown) => {
      setError(
        err instanceof Error
          ? err.message
          : "An unexpected error occurred while saving decision.",
      )
    },
  })

  if (!authorized) {
    return (
      <WorkspacePage
        title="Irregular Advising"
        description="Review and advise irregular students on their subject selections and schedule."
      >
        <Alert variant="destructive">
          <AlertDescription>
            You are not authorized to access irregular student advising.
          </AlertDescription>
        </Alert>
      </WorkspacePage>
    )
  }

  const reasonRequired =
    pendingDecision?.action === "program_head_reject" && reason.trim() === ""
  const overloadAcknowledgementRequired =
    pendingDecision?.action === "program_head_approve" &&
    Boolean(pendingDecision.enrollment.requires_overload_approval) &&
    !overloadAcknowledged

  const handleConfirmDecision = async () => {
    if (!pendingDecision) return
    if (reasonRequired || overloadAcknowledgementRequired) return

    await mutation.mutateAsync({
      enrollmentId: pendingDecision.enrollment.id,
      action: pendingDecision.action,
      decisionReason: reason.trim() ? reason.trim() : undefined,
      decisionComment: comment.trim() ? comment.trim() : undefined,
      overloadAcknowledged,
    })
  }

  const rawRows = enrollmentsQuery.data?.data ?? []
  const filteredRows = search.trim()
    ? rawRows.filter(
        (e) =>
          e.student_number
            .toLowerCase()
            .includes(search.toLowerCase().trim()) ||
          e.student_name
            ?.toLowerCase()
            .includes(search.toLowerCase().trim()),
      )
    : rawRows

  const columns: DataTableColumn<Enrollment>[] = [
    {
      key: "student",
      header: "Student",
      render: (enrollment) => (
        <div>
          <div className="flex items-center gap-2 font-medium">
            <span>{enrollment.student_name ?? "—"}</span>
            <span className="text-xs text-muted-foreground font-mono">
              ({enrollment.student_number})
            </span>
          </div>
          <div className="text-xs text-muted-foreground">
            {formatYearLevelOrdinal(enrollment.student_year_level)}
          </div>
        </div>
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
      render: (enrollment) => {
        // After a decline the enrollment is back with the Chair; say why, so the
        // reason is not buried inside the review dialog.
        const lastRevision = enrollment.revisions[enrollment.revisions.length - 1]
        const declined =
          enrollment.status === "pending_program_head_approval" &&
          lastRevision?.status === "declined"

        return (
          // A flex column, not a grid: a badge is `overflow-hidden`, which lets a grid track (and so
          // the table column) shrink below the badge and the badge spill over the next column.
          <div className="flex flex-col items-start gap-1">
            <Badge variant={statusBadgeVariant(enrollment.status)}>
              {enrollment.status_label}
            </Badge>
            {declined && (
              <p className="max-w-64 text-xs text-destructive">
                The student did not accept your changes
                {lastRevision.student_reason
                  ? `: ${lastRevision.student_reason}`
                  : "."}
              </p>
            )}
          </div>
        )
      },
    },
    {
      key: "units",
      header: "Units",
      render: (enrollment) => {
        const regularMax = enrollment.regular_unit_maximum ?? 24
        const overloadMax = enrollment.unit_maximum
        return (
          <div className="flex flex-col gap-0.5">
            <div className="flex items-center gap-2">
              <span className="font-medium">{enrollment.total_units} units</span>
              {enrollment.exceeds_unit_maximum ? (
                <Badge variant="destructive">
                  Over the {enrollment.unit_maximum} unit maximum
                </Badge>
              ) : (
                enrollment.requires_overload_approval && (
                  <Badge variant="warning">Overload</Badge>
                )
              )}
            </div>
            <span className="text-[11px] text-muted-foreground">
              Allowed: {regularMax} regular{overloadMax ? ` · max ${overloadMax}` : ""}
            </span>
          </div>
        )
      },
    },
    {
      key: "actions",
      header: "Actions",
      render: (enrollment) => (
        <div className="flex flex-wrap items-center gap-2">
          <Button
            type="button"
            size="sm"
            variant="outline"
            onClick={() => setReviewingEnrollment(enrollment)}
          >
            Check Schedule
          </Button>
          {enrollment.student_id && (
            <Button
              type="button"
              size="sm"
              variant="outline"
              onClick={() =>
                setProspectusStudent({
                  id: enrollment.student_id,
                  name: enrollment.student_name ?? enrollment.student_number,
                  studentNumber: enrollment.student_number,
                })
              }
            >
              Prospectus
            </Button>
          )}
          {enrollment.status === "pending_program_head_approval" && (
            <>
              <Button
                type="button"
                size="sm"
                variant="default"
                disabled={mutation.isPending}
                onClick={() => {
                  setPendingDecision({
                    enrollment,
                    action: "program_head_approve",
                  })
                  setReason("")
                  setComment("")
                  setOverloadAcknowledged(false)
                  setError("")
                }}
              >
                Approve
              </Button>
              <Button
                type="button"
                size="sm"
                variant="destructive"
                disabled={mutation.isPending}
                onClick={() => {
                  setPendingDecision({
                    enrollment,
                    action: "program_head_reject",
                  })
                  setReason("")
                  setComment("")
                  setOverloadAcknowledged(false)
                  setError("")
                }}
              >
                Reject
              </Button>
            </>
          )}
        </div>
      ),
    },
  ]

  return (
    <WorkspacePage
      title="Irregular Student Advising & Approvals"
      description="Check submitted schedules for irregular students, verify their curriculum prospectus, and approve or reject submissions. If you change a student's subjects, they go back to the student to accept first. Approved submissions go on to the Registrar."
    >
      <ProgramChairEnrollmentTabs current="irregular-advising" />
      {error && (
        <Alert variant="destructive">
          <AlertDescription>{error}</AlertDescription>
        </Alert>
      )}

      <Card>
        <CardHeader className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div className="grid gap-1">
            <CardTitle level={2}>Submissions Queue</CardTitle>
            <p className="text-sm text-muted-foreground">
              Review irregular student subject loads before confirming
              enrollment.
            </p>
          </div>
          <div className="flex flex-wrap items-center gap-2">
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
              Pending Review
            </Button>
            <Button
              type="button"
              size="sm"
              variant={
                statusFilter === "pending_student_review"
                  ? "default"
                  : "outline"
              }
              onClick={() => {
                setStatusFilter("pending_student_review")
                setPage(1)
              }}
            >
              Waiting for Student
            </Button>
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
              With Registrar
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
              Enrolled
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
            <Button
              type="button"
              size="sm"
              variant={overMaximumOnly ? "default" : "outline"}
              aria-pressed={overMaximumOnly}
              onClick={() => {
                setOverMaximumOnly((current) => !current)
                setPage(1)
              }}
            >
              Over the unit maximum
            </Button>
          </div>
        </CardHeader>
        <CardContent className="grid gap-4">
          <div className="flex items-center gap-3">
            <Input
              type="search"
              placeholder="Search by student number or name…"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              className="max-w-md"
            />
          </div>

          <AsyncBoundary
            query={{ ...enrollmentsQuery, data: filteredRows }}
            isEmpty={(rows) => rows.length === 0}
            emptyMessage="No irregular student enrollments match this filter."
            loadingLabel="Loading irregular enrollments…"
          >
            {(rows) => (
              <DataTable
                caption="Irregular student enrollments"
                rowKey={(e) => e.id}
                rows={rows}
                columns={columns}
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

      {/* Review Dialog with Schedule — editable: a Program Head may add or
          remove whole subjects before deciding on the enrollment. */}
      <EnrollmentReviewDialog
        enrollment={reviewingEnrollment}
        editable={reviewingEnrollment?.status === "pending_program_head_approval"}
        onRevised={(revised) => {
          setReviewingEnrollment(revised)
          setPendingDecision((current) =>
            current?.enrollment.id === revised.id
              ? { ...current, enrollment: revised }
              : current,
          )
        }}
        onOpenChange={(open) => {
          if (!open) setReviewingEnrollment(null)
        }}
      />

      {/* Dedicated Prospectus Dialog */}
      <Dialog
        open={prospectusStudent !== null}
        onOpenChange={(open) => {
          if (!open) setProspectusStudent(null)
        }}
      >
        <DialogContent className="max-h-[85dvh] w-[calc(100vw-2rem)] overflow-y-auto sm:max-w-5xl">
          <DialogHeader>
            <DialogTitle>
              Curriculum Prospectus — {prospectusStudent?.name} (
              {prospectusStudent?.studentNumber})
            </DialogTitle>
          </DialogHeader>
          {prospectusStudent && (
            <ProspectusDocument studentId={prospectusStudent.id} />
          )}
        </DialogContent>
      </Dialog>

      {/* Approval / Rejection Decision Confirmation */}
      <AlertDialog
        open={pendingDecision !== null}
        onOpenChange={(open) => {
          if (!open && !mutation.isPending) setPendingDecision(null)
        }}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>
              {pendingDecision?.action === "program_head_approve"
                ? "Approve irregular student enrollment?"
                : "Reject irregular student enrollment"}
            </AlertDialogTitle>
            <AlertDialogDescription>
              {pendingDecision?.action === "program_head_approve"
                ? "Approving this enrollment sends it on to the Registrar, who makes the final approval before the student can pay."
                : "Rejecting this enrollment requires providing a reason explaining what schedule corrections the student must make."}
            </AlertDialogDescription>
          </AlertDialogHeader>

          {pendingDecision?.action === "program_head_reject" && (
            <Field data-invalid={reasonRequired}>
              <FieldLabel htmlFor="decision-reason">
                Reason for rejection
              </FieldLabel>
              <Textarea
                id="decision-reason"
                value={reason}
                onChange={(e) => setReason(e.target.value)}
                placeholder="e.g. Schedule conflicts with core curriculum prerequisites; please choose an afternoon section."
                disabled={mutation.isPending}
                required
              />
              {reasonRequired && (
                <FieldError>
                  A reason is required to reject an enrollment.
                </FieldError>
              )}
            </Field>
          )}

          <Field>
            <FieldLabel htmlFor="decision-comment">
              Note for the student (optional)
            </FieldLabel>
            <Textarea
              id="decision-comment"
              value={comment}
              onChange={(e) => setComment(e.target.value)}
              placeholder="e.g. Swapped IT201 for IT205 since the former was full — please double-check your schedule."
              disabled={mutation.isPending}
            />
          </Field>

          {pendingDecision?.action === "program_head_approve" &&
            Boolean(pendingDecision.enrollment.requires_overload_approval) && (
              <Alert variant="destructive">
                <AlertDescription>
                  <p>
                    This enrollment totals{" "}
                    <strong>{pendingDecision.enrollment.total_units} units</strong>, which
                    exceeds the regular load of{" "}
                    <strong>{pendingDecision.enrollment.regular_unit_maximum ?? 24} units</strong>
                    {pendingDecision.enrollment.unit_maximum
                      ? ` (maximum allowed: ${pendingDecision.enrollment.unit_maximum} units)`
                      : ""}. Approving it requires explicit overload acknowledgement.
                  </p>
                  <label className="mt-2 flex items-center gap-2 text-sm font-normal">
                    <input
                      type="checkbox"
                      checked={overloadAcknowledged}
                      onChange={(e) =>
                        setOverloadAcknowledged(e.target.checked)
                      }
                      disabled={mutation.isPending}
                    />
                    <span>
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
              variant={
                pendingDecision?.action === "program_head_approve"
                  ? "default"
                  : "destructive"
              }
              disabled={
                mutation.isPending ||
                reasonRequired ||
                overloadAcknowledgementRequired
              }
              onClick={() => void handleConfirmDecision()}
            >
              {mutation.isPending
                ? "Saving decision…"
                : pendingDecision?.action === "program_head_approve"
                  ? "Confirm Approval"
                  : "Confirm Rejection"}
            </Button>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </WorkspacePage>
  )
}
