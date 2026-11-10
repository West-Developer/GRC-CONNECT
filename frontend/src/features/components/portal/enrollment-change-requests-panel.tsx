"use client"

import { useState } from "react"

import { useAuth } from "@/features/auth/use-auth"
import { AsyncBoundary } from "@/features/components/portal/async-boundary"
import { DataTable } from "@/features/components/portal/data-table"
import { Paginator } from "@/features/components/portal/paginator"
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
  CardHeader,
  CardTitle,
} from "@/features/components/ui/card"
import { Field, FieldLabel } from "@/features/components/ui/field"
import { Textarea } from "@/features/components/ui/textarea"
import {
  useEnrollmentChangeRequestsQuery,
  useUpdateEnrollmentChangeRequestMutation,
} from "@/features/hooks/use-enrollment-change-requests"
import type { EnrollmentChangeRequest } from "@/features/schemas/enrollment-change-request-schema"

function statusBadgeVariant(
  status: EnrollmentChangeRequest["status"],
): "default" | "destructive" | "outline" {
  if (status === "approved") return "default"
  if (status === "rejected") return "destructive"
  return "outline"
}

interface Pending {
  request: EnrollmentChangeRequest
  action: "approve" | "reject"
}

interface EnrollmentChangeRequestsPanelProps {
  /** Limit the list to one request type; leave out to list every type. */
  type?: EnrollmentChangeRequest["request_type"]
  title?: string
  caption?: string
  emptyMessage?: string
  loadingLabel?: string
}

/**
 * The add / drop / change-section request list with its decision dialog.
 * Shared by the standalone "Add/Drop requests" page and the Registrar's
 * "Enrollment requests" hub (stakeholder Doc 14). Only the Registrar Head
 * decides these; Registrar Staff reads them (assumption A1 of the Doc 14
 * handoff, the state before the hub existed).
 */
export function EnrollmentChangeRequestsPanel({
  type,
  title = "Add/drop requests",
  caption = "Add/drop requests",
  emptyMessage = "No add/drop requests have been submitted.",
  loadingLabel = "Loading add/drop requests…",
}: EnrollmentChangeRequestsPanelProps) {
  const { session } = useAuth()
  const authorized =
    session?.role === "registrar_head" || session?.role === "registrar_staff"
  const canDecide = session?.role === "registrar_head"

  const [page, setPage] = useState(1)
  const [pending, setPending] = useState<Pending | null>(null)
  const [reason, setReason] = useState("")
  const [error, setError] = useState("")

  const requestsQuery = useEnrollmentChangeRequestsQuery(
    { page, per_page: 20, type },
    { enabled: authorized },
  )
  const mutation = useUpdateEnrollmentChangeRequestMutation()
  const reasonRequired = pending?.action === "reject" && !reason.trim()

  const confirm = async () => {
    if (!pending || reasonRequired) return
    setError("")
    try {
      await mutation.mutateAsync({
        id: pending.request.id,
        input: {
          action: pending.action,
          reason: reason.trim() || undefined,
        },
      })
      setPending(null)
      setReason("")
    } catch {
      setError(
        "The decision could not be saved. Check the connection and try again.",
      )
    }
  }

  return (
    <>
      {error && (
        <Alert variant="destructive">
          <AlertDescription>{error}</AlertDescription>
        </Alert>
      )}
      <Card>
        <CardHeader>
          <CardTitle level={2}>{title}</CardTitle>
        </CardHeader>
        <CardContent>
          <AsyncBoundary
            query={{ ...requestsQuery, data: requestsQuery.data?.data }}
            isEmpty={(rows) => rows.length === 0}
            emptyMessage={emptyMessage}
            loadingLabel={loadingLabel}
          >
            {(requests) => (
              <DataTable
                caption={caption}
                rowKey={(request) => request.id}
                rows={requests}
                columns={[
                  {
                    key: "student",
                    header: "Student",
                    render: (request) => request.student_number,
                  },
                  {
                    key: "type",
                    header: "Type",
                    render: (request) => request.request_type_label,
                  },
                  {
                    key: "subject",
                    header: "Subject",
                    render: (request) => request.subject_code,
                  },
                  {
                    key: "sections",
                    header: "Sections",
                    render: (request) =>
                      [request.from_section_code, request.to_section_code]
                        .filter(Boolean)
                        .join(" → ") || "—",
                  },
                  {
                    key: "reason",
                    header: "Reason",
                    render: (request) => request.reason,
                  },
                  {
                    key: "status",
                    header: "Status",
                    render: (request) => (
                      <Badge variant={statusBadgeVariant(request.status)}>
                        {request.status_label}
                      </Badge>
                    ),
                  },
                  ...(canDecide
                    ? [
                        {
                          key: "actions",
                          header: "Actions",
                          render: (request: EnrollmentChangeRequest) =>
                            request.status === "pending" && (
                              <div className="flex flex-wrap gap-2">
                                <Button
                                  type="button"
                                  size="sm"
                                  disabled={mutation.isPending}
                                  onClick={() => {
                                    setPending({ request, action: "approve" })
                                    setReason("")
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
                                    setPending({ request, action: "reject" })
                                    setReason("")
                                    setError("")
                                  }}
                                >
                                  Reject
                                </Button>
                              </div>
                            ),
                        },
                      ]
                    : []),
                ]}
              />
            )}
          </AsyncBoundary>
          <div className="mt-4">
            <Paginator
              currentPage={requestsQuery.data?.meta.current_page ?? 1}
              lastPage={requestsQuery.data?.meta.last_page ?? 1}
              onPageChange={setPage}
            />
          </div>
        </CardContent>
      </Card>

      <AlertDialog
        open={pending !== null}
        onOpenChange={(open) => {
          if (!open && !mutation.isPending) setPending(null)
        }}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Confirm decision</AlertDialogTitle>
            <AlertDialogDescription>
              This decision is recorded in the operational audit log and
              notifies the student.
            </AlertDialogDescription>
          </AlertDialogHeader>
          {pending?.action === "reject" && (
            <Field data-invalid={reasonRequired}>
              <FieldLabel htmlFor="change-request-decision-reason">
                Reason
              </FieldLabel>
              <Textarea
                id="change-request-decision-reason"
                value={reason}
                onChange={(event) => setReason(event.target.value)}
                disabled={mutation.isPending}
                aria-describedby={
                  reasonRequired
                    ? "change-request-decision-reason-error"
                    : undefined
                }
              />
              {reasonRequired && (
                <p
                  id="change-request-decision-reason-error"
                  className="text-sm text-destructive"
                >
                  Reason is required to reject.
                </p>
              )}
            </Field>
          )}
          <AlertDialogFooter>
            <AlertDialogCancel disabled={mutation.isPending}>
              Cancel
            </AlertDialogCancel>
            <Button
              type="button"
              disabled={mutation.isPending || reasonRequired}
              onClick={() => void confirm()}
            >
              {mutation.isPending ? "Saving decision" : "Confirm decision"}
            </Button>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  )
}
