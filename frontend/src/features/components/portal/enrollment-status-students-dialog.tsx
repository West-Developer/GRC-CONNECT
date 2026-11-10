"use client"

import { useState } from "react"

import { DataTable } from "@/features/components/portal/data-table"
import { formatYearLevelOrdinal } from "@/features/lib/curriculum-ordinal"
import { Paginator } from "@/features/components/portal/paginator"
import { Alert, AlertDescription } from "@/features/components/ui/alert"
import { Badge } from "@/features/components/ui/badge"
import { Button } from "@/features/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/features/components/ui/dialog"
import { Skeleton } from "@/features/components/ui/skeleton"
import { useEnrollmentsListQuery } from "@/features/hooks/use-enrollment"
import type { Enrollment } from "@/features/schemas/enrollment-schema"

interface EnrollmentStatusStudentsDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  status: string | null
  statusLabel: string
  academicTermId?: number
  academicTermLabel?: string
}

function statusBadgeVariant(
  status: Enrollment["status"],
): "default" | "destructive" | "outline" | "secondary" {
  if (status === "rejected" || status === "cancelled") return "destructive"
  if (status === "enrolled") return "default"
  if (
    status === "pending_program_head_approval" ||
    status === "pending_student_review" ||
    status === "awaiting_first_payment" ||
    status === "pending_registrar_approval" ||
    status === "pending_payment"
  )
    return "secondary"
  return "outline"
}

function formatDate(iso: string | null | undefined): string {
  if (!iso) return "—"
  try {
    const date = new Date(iso)
    return date.toLocaleDateString("en-US", {
      month: "short",
      day: "numeric",
      year: "numeric",
      hour: "2-digit",
      minute: "2-digit",
    })
  } catch {
    return iso
  }
}

export function EnrollmentStatusStudentsDialog({
  open,
  onOpenChange,
  status,
  statusLabel,
  academicTermId,
  academicTermLabel,
}: EnrollmentStatusStudentsDialogProps) {
  const [page, setPage] = useState(1)

  // Reset page when status or term changes — adjusted during render, not in
  // an effect, so the stale page never commits (and never fetches).
  const pageScope = `${status ?? ""}|${academicTermId ?? ""}`
  const [pagedScope, setPagedScope] = useState(pageScope)
  if (pagedScope !== pageScope) {
    setPagedScope(pageScope)
    setPage(1)
  }

  const enrollmentsQuery = useEnrollmentsListQuery(
    {
      status: (status as Enrollment["status"]) ?? undefined,
      academic_term_id: academicTermId,
      page,
      per_page: 15,
    },
    { enabled: open && Boolean(status) },
  )

  const enrollments = enrollmentsQuery.data?.data ?? []
  const meta = enrollmentsQuery.data?.meta
  const totalCount = meta?.total ?? enrollments.length

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90dvh] w-[calc(100vw-2rem)] overflow-y-auto sm:max-w-4xl">
        <DialogHeader>
          <div className="flex flex-wrap items-center justify-between gap-2 pr-6">
            <DialogTitle>Students · {statusLabel}</DialogTitle>
            <Badge variant="outline">{totalCount} total</Badge>
          </div>
          <DialogDescription>
            Enrolled or prospective students with status{" "}
            <span className="font-medium text-foreground">{statusLabel}</span>
            {academicTermLabel ? ` in ${academicTermLabel}` : ""}.
          </DialogDescription>
        </DialogHeader>

        {enrollmentsQuery.isPending ? (
          <div
            className="grid gap-3 py-4"
            role="status"
            aria-label="Loading student roster"
          >
            <Skeleton className="h-10 w-full" />
            <Skeleton className="h-10 w-full" />
            <Skeleton className="h-10 w-full" />
            <Skeleton className="h-10 w-full" />
          </div>
        ) : enrollmentsQuery.isError ? (
          <Alert variant="destructive" className="my-4">
            <AlertDescription className="flex flex-wrap items-center justify-between gap-3">
              <span>Could not load the student roster for this status.</span>
              <Button
                type="button"
                size="sm"
                variant="outline"
                onClick={() => void enrollmentsQuery.refetch()}
              >
                Retry
              </Button>
            </AlertDescription>
          </Alert>
        ) : enrollments.length === 0 ? (
          <div className="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground">
            No students found with status &ldquo;{statusLabel}&rdquo;
            {academicTermLabel ? ` in ${academicTermLabel}` : ""}.
          </div>
        ) : (
          <div className="space-y-4">
            <DataTable
              caption={`Students with status ${statusLabel}`}
              rowKey={(enrollment) => enrollment.id}
              rows={enrollments}
              columns={[
                {
                  key: "student_number",
                  header: "Student number",
                  render: (enrollment) => (
                    <span className="font-mono font-medium">
                      {enrollment.student_number}
                    </span>
                  ),
                },
                {
                  key: "student_name",
                  header: "Student name",
                  render: (enrollment) =>
                    enrollment.student_name ? (
                      <span className="font-medium text-foreground">
                        {enrollment.student_name}
                      </span>
                    ) : (
                      <span className="text-muted-foreground">—</span>
                    ),
                },
                {
                  key: "year_level",
                  header: "Year level",
                  render: (enrollment) => (
                    <Badge variant="outline">
                      {formatYearLevelOrdinal(enrollment.student_year_level)}
                    </Badge>
                  ),
                },
                {
                  key: "units",
                  header: "Total units",
                  render: (enrollment) => `${enrollment.total_units} units`,
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
                  key: "date",
                  header: "Timestamp",
                  render: (enrollment) => (
                    <span className="text-xs text-muted-foreground">
                      {formatDate(
                        enrollment.enrolled_at ??
                          enrollment.payment_confirmed_at ??
                          enrollment.registrar_decided_at ??
                          enrollment.submitted_at,
                      )}
                    </span>
                  ),
                },
              ]}
            />
            {meta && meta.last_page > 1 && (
              <div className="pt-2">
                <Paginator
                  currentPage={meta.current_page}
                  lastPage={meta.last_page}
                  onPageChange={setPage}
                />
              </div>
            )}
          </div>
        )}
      </DialogContent>
    </Dialog>
  )
}
