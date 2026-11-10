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
  useDecideWithdrawalRequestMutation,
  useWithdrawalRequestsQuery,
} from "@/features/hooks/use-withdrawal-requests"

function statusBadgeVariant(
  status: "pending" | "approved" | "rejected",
): "default" | "destructive" | "outline" {
  if (status === "approved") return "default"
  if (status === "rejected") return "destructive"
  return "outline"
}

interface Decision {
  id: number
  action: "approve" | "reject"
}

/**
 * Withdrawal requests with their decision dialog, for the Registrar's
 * "Enrollment requests" hub. Both Registrar Staff and the Registrar Head decide
 * (stakeholder Doc 14: "Withdrawal is missing to the Registrar Head"); approving
 * moves the enrollment to `withdrawn` and gives the seats back, exactly once,
 * on the server.
 */
export function WithdrawalRequestsPanel() {
  const { session } = useAuth()
  const authorized =
    session?.role === "registrar_head" || session?.role === "registrar_staff"

  const [page, setPage] = useState(1)
  const [decision, setDecision] = useState<Decision | null>(null)
  const [reason, setReason] = useState("")
  const [error, setError] = useState("")

  const requestsQuery = useWithdrawalRequestsQuery(
    { page, per_page: 20 },
    { enabled: authorized },
  )
  const mutation = useDecideWithdrawalRequestMutation()
  const reasonRequired = decision?.action === "reject" && !reason.trim()

  const confirm = async () => {
    if (!decision || reasonRequired) return
    setError("")
    try {
      await mutation.mutateAsync({
        id: decision.id,
        input: { action: decision.action, reason: reason.trim() || undefined },
      })
      setDecision(null)
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
          <CardTitle level={2}>Withdrawal requests</CardTitle>
        </CardHeader>
        <CardContent>
          <AsyncBoundary
            query={{ ...requestsQuery, data: requestsQuery.data?.data }}
            isEmpty={(rows) => rows.length === 0}
            emptyMessage="No withdrawal requests are pending review."
            loadingLabel="Loading withdrawal requests…"
          >
            {(requests) => (
              <DataTable
                caption="Withdrawal requests"
                rowKey={(request) => request.id}
                rows={requests}
                columns={[
                  {
                    key: "student",
                    header: "Student",
                    render: (request) => request.student_number,
                  },
                  {
                    key: "enrollment",
                    header: "Enrollment",
                    render: (request) => `#${request.enrollment_id}`,
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
                  {
                    key: "actions",
                    header: "Actions",
                    render: (request) =>
                      request.status === "pending" && (
                        <div className="flex flex-wrap gap-2">
                          <Button
                            type="button"
                            size="sm"
                            disabled={mutation.isPending}
                            onClick={() => {
                              setDecision({ id: request.id, action: "approve" })
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
                              setDecision({ id: request.id, action: "reject" })
                              setReason("")
                              setError("")
                            }}
                          >
                            Reject
                          </Button>
                        </div>
                      ),
                  },
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
        open={decision !== null}
        onOpenChange={(open) => {
          if (!open && !mutation.isPending) setDecision(null)
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
          {decision?.action === "reject" && (
            <Field data-invalid={reasonRequired}>
              <FieldLabel htmlFor="withdrawal-decision-reason">
                Reason
              </FieldLabel>
              <Textarea
                id="withdrawal-decision-reason"
                value={reason}
                onChange={(event) => setReason(event.target.value)}
                disabled={mutation.isPending}
              />
              {reasonRequired && (
                <p className="text-sm text-destructive">
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
