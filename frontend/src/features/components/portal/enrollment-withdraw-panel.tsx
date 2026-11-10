"use client"

import { useState } from "react"

import { AsyncBoundary } from "@/features/components/portal/async-boundary"
import { DataUseNotice } from "@/features/components/privacy/data-use-notice"
import { DataTable } from "@/features/components/portal/data-table"
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
  useCreateWithdrawalRequestMutation,
  useWithdrawalRequestsQuery,
} from "@/features/hooks/use-withdrawal-requests"
import type { Enrollment } from "@/features/schemas/enrollment-schema"
import { isApiClientError } from "@/features/services/api-client"

function firstApiErrorMessage(error: unknown): string | undefined {
  if (!isApiClientError(error)) return undefined
  return Object.values(error.fieldErrors ?? {})[0]?.[0]
}

function statusBadgeVariant(
  status: "pending" | "approved" | "rejected",
): "default" | "destructive" | "outline" {
  if (status === "approved") return "default"
  if (status === "rejected") return "destructive"
  return "outline"
}

/**
 * The Withdraw stage of the enrollment process, embedded directly in
 * `EnrollmentWorkspace` once a student's enrollment reaches `enrolled` —
 * same placement as `EnrollmentAddDropPanel`, but for leaving the term
 * entirely rather than swapping subjects. Backed by
 * `useCreateWithdrawalRequestMutation`, which was already fully
 * implemented but had no calling component until now.
 */
export function EnrollmentWithdrawPanel({
  enrollment,
}: {
  enrollment: Enrollment
}) {
  const requestsQuery = useWithdrawalRequestsQuery({ page: 1, per_page: 20 })
  const createMutation = useCreateWithdrawalRequestMutation()

  const [confirmOpen, setConfirmOpen] = useState(false)
  const [reason, setReason] = useState("")
  const [error, setError] = useState("")

  const reasonRequired = confirmOpen && !reason.trim()
  const hasPendingRequest = (requestsQuery.data?.data ?? []).some(
    (request) =>
      request.enrollment_id === enrollment.id && request.status === "pending",
  )

  const confirmWithdraw = async () => {
    if (reasonRequired) return
    setError("")
    try {
      await createMutation.mutateAsync({
        enrollmentId: enrollment.id,
        reason: reason.trim(),
      })
      setConfirmOpen(false)
      setReason("")
    } catch (submitError) {
      setError(
        firstApiErrorMessage(submitError) ??
          "The withdrawal request could not be submitted. Check the connection and try again.",
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
          <CardTitle level={2}>Withdraw from this term</CardTitle>
        </CardHeader>
        <CardContent className="grid gap-3">
          {hasPendingRequest ? (
            <Alert>
              <AlertDescription>
                You already have a withdrawal request pending Registrar Head
                decision.
              </AlertDescription>
            </Alert>
          ) : (
            <>
              <p className="text-sm text-muted-foreground">
                Withdrawing removes every subject on this enrollment for the
                term. This requires Registrar Head approval — it does not
                take effect immediately.
              </p>
              <Button
                type="button"
                variant="destructive"
                className="w-fit"
                onClick={() => {
                  setReason("")
                  setError("")
                  setConfirmOpen(true)
                }}
              >
                Withdraw
              </Button>
            </>
          )}
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle level={2}>Your withdrawal requests</CardTitle>
        </CardHeader>
        <CardContent>
          <AsyncBoundary
            query={{ ...requestsQuery, data: requestsQuery.data?.data }}
            isEmpty={(rows) => rows.length === 0}
            emptyMessage="You have not submitted any withdrawal requests."
            loadingLabel="Loading your withdrawal requests…"
          >
            {(requests) => (
              <DataTable
                caption="Your withdrawal requests"
                rowKey={(request) => request.id}
                rows={requests}
                columns={[
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
                    key: "processed_at",
                    header: "Decided at",
                    render: (request) =>
                      request.processed_at
                        ? new Date(request.processed_at).toLocaleString()
                        : "—",
                  },
                ]}
              />
            )}
          </AsyncBoundary>
        </CardContent>
      </Card>

      <AlertDialog
        open={confirmOpen}
        onOpenChange={(open) => {
          if (!open && !createMutation.isPending) setConfirmOpen(false)
        }}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Withdraw from this term?</AlertDialogTitle>
            <AlertDialogDescription>
              This submits a withdrawal request for Registrar Head approval —
              your enrollment stays active until it is decided.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <Field data-invalid={reasonRequired}>
            <FieldLabel htmlFor="withdraw-reason">Reason</FieldLabel>
            <Textarea
              id="withdraw-reason"
              value={reason}
              onChange={(event) => setReason(event.target.value)}
              disabled={createMutation.isPending}
              aria-describedby={
                reasonRequired ? "withdraw-reason-error" : undefined
              }
            />
            {reasonRequired && (
              <p id="withdraw-reason-error" className="text-sm text-destructive">
                Reason is required.
              </p>
            )}
          </Field>
          <DataUseNotice notice="withdrawal" />
          <AlertDialogFooter>
            <AlertDialogCancel disabled={createMutation.isPending}>
              Cancel
            </AlertDialogCancel>
            <Button
              type="button"
              variant="destructive"
              disabled={createMutation.isPending}
              onClick={() => void confirmWithdraw()}
            >
              {createMutation.isPending ? "Submitting" : "Submit request"}
            </Button>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  )
}
