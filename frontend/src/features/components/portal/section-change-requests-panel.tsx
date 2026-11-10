"use client"

import { ArrowRight } from "lucide-react"
import { useState } from "react"

import { AsyncBoundary } from "@/features/components/portal/async-boundary"
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
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/features/components/ui/card"
import { Field, FieldLabel } from "@/features/components/ui/field"
import { Textarea } from "@/features/components/ui/textarea"
import {
  useDecideSectionChangeRequestMutation,
  useSectionChangeRequestsQuery,
} from "@/features/hooks/use-section-change-requests"
import { apiErrorMessages } from "@/features/lib/api-error-messages"
import { describeChangeValue } from "@/features/lib/section-change-diff"
import type {
  SectionChangeRequest,
  SectionChangeRequestStatus,
} from "@/features/schemas/section-change-request-schema"

function statusBadgeVariant(
  status: SectionChangeRequestStatus,
): "default" | "destructive" | "outline" | "secondary" {
  if (status === "approved") return "default"
  if (status === "rejected") return "destructive"
  if (status === "cancelled") return "secondary"
  return "outline"
}

interface Decision {
  request: SectionChangeRequest
  action: "approve" | "reject" | "cancel"
}

const DIALOG_COPY = {
  approve: {
    title: "Approve this schedule change?",
    description:
      "The section is updated right away and the Program Head is notified. The change is refused if the section moved since the request or the new slot now clashes.",
    confirm: "Approve and apply",
  },
  reject: {
    title: "Reject this schedule change?",
    description:
      "The section stays as published. The Program Head sees your reason.",
    confirm: "Reject request",
  },
  cancel: {
    title: "Withdraw this request?",
    description: "The Registrar Head will no longer see it as pending.",
    confirm: "Withdraw request",
  },
} as const

/**
 * Change requests on published sections (ADR 0032). The Registrar Head
 * approves or rejects; the Program Head who filed one can follow its status
 * and withdraw it while it is pending. Which buttons appear follows the
 * signed-in `mode`, the server refuses the rest.
 */
export function SectionChangeRequestsPanel({
  mode,
}: {
  mode: "decider" | "requester"
}) {
  const requestsQuery = useSectionChangeRequestsQuery()
  const mutation = useDecideSectionChangeRequestMutation()
  const [decision, setDecision] = useState<Decision | null>(null)
  const [reason, setReason] = useState("")
  const [errors, setErrors] = useState<readonly string[]>([])

  const reasonRequired = decision?.action === "reject" && !reason.trim()

  const confirm = async () => {
    if (!decision || reasonRequired) return
    setErrors([])
    try {
      await mutation.mutateAsync({
        requestId: decision.request.id,
        input: {
          action: decision.action,
          decision_reason: reason.trim() || undefined,
        },
      })
      setDecision(null)
      setReason("")
    } catch (error) {
      setDecision(null)
      setErrors(
        apiErrorMessages(
          error,
          "The decision could not be saved. Check the connection and try again.",
        ),
      )
    }
  }

  const ask = (request: SectionChangeRequest, action: Decision["action"]) => {
    setDecision({ request, action })
    setReason("")
    setErrors([])
  }

  const pendingCount = (requestsQuery.data ?? []).filter(
    (request) => request.status === "pending",
  ).length

  return (
    <>
      {errors.length > 0 && (
        <Alert variant="destructive">
          <AlertDescription>
            {errors.map((message) => (
              <p key={message}>{message}</p>
            ))}
          </AlertDescription>
        </Alert>
      )}

      <Card>
        <CardHeader>
          <CardTitle level={2}>
            {mode === "decider"
              ? "Requests from Program Heads"
              : "My change requests"}
          </CardTitle>
          <CardDescription>
            {mode === "decider"
              ? `${pendingCount} waiting for your decision. Each shows what the section has now next to what the Program Head wants.`
              : "Published sections can only be changed with the Registrar Head's approval. Follow your requests here."}
          </CardDescription>
        </CardHeader>
        <CardContent>
          <AsyncBoundary
            query={requestsQuery}
            isEmpty={(requests) => requests.length === 0}
            emptyMessage={
              mode === "decider"
                ? "No Program Head has asked to change a published schedule."
                : "You have not asked to change a published schedule."
            }
            loadingLabel="Loading schedule change requests…"
          >
            {(requests) => (
              <ul className="grid gap-3 md:grid-cols-2">
                {requests.map((request) => (
                  <li key={request.id}>
                    <Card size="sm" className="h-full">
                      <CardHeader>
                        <CardTitle>
                          {request.subject_code} · Section{" "}
                          {request.section_code}
                        </CardTitle>
                        <CardDescription>
                          {request.subject_title}
                        </CardDescription>
                      </CardHeader>
                      <CardContent className="grid gap-3">
                        <div className="flex flex-wrap items-center gap-2">
                          <Badge variant={statusBadgeVariant(request.status)}>
                            {request.status_label}
                          </Badge>
                          <span className="text-xs text-muted-foreground">
                            Requested by {request.requested_by_name}
                          </span>
                        </div>

                        <ul
                          aria-label={`Changes requested for ${request.subject_code}`}
                          className="grid gap-1.5 rounded-md border bg-muted/20 p-2 text-sm"
                        >
                          {request.changes.map((change) => (
                            <li
                              key={change.field}
                              className="flex flex-wrap items-center gap-x-2"
                            >
                              <span className="w-28 shrink-0 font-medium">
                                {change.label}
                              </span>
                              <span className="text-muted-foreground line-through">
                                {describeChangeValue(change.field, change.old)}
                              </span>
                              <ArrowRight
                                className="size-3.5 text-muted-foreground"
                                aria-label="changes to"
                              />
                              <span className="font-semibold text-foreground">
                                {describeChangeValue(change.field, change.new)}
                              </span>
                            </li>
                          ))}
                        </ul>

                        <p className="text-sm">
                          <span className="font-medium">Reason:</span>{" "}
                          {request.reason}
                        </p>

                        {request.decision_reason && (
                          <p className="rounded-md bg-muted p-2 text-sm text-muted-foreground">
                            <span className="font-medium text-foreground">
                              {request.decided_by_name ?? "Registrar Head"}:
                            </span>{" "}
                            {request.decision_reason}
                          </p>
                        )}

                        {request.status === "pending" && (
                          <div className="flex flex-wrap gap-2">
                            {mode === "decider" ? (
                              <>
                                <Button
                                  type="button"
                                  size="sm"
                                  disabled={mutation.isPending}
                                  onClick={() => ask(request, "approve")}
                                >
                                  Approve
                                </Button>
                                <Button
                                  type="button"
                                  size="sm"
                                  variant="destructive"
                                  disabled={mutation.isPending}
                                  onClick={() => ask(request, "reject")}
                                >
                                  Reject
                                </Button>
                              </>
                            ) : (
                              <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                disabled={mutation.isPending}
                                onClick={() => ask(request, "cancel")}
                              >
                                Withdraw request
                              </Button>
                            )}
                          </div>
                        )}
                      </CardContent>
                    </Card>
                  </li>
                ))}
              </ul>
            )}
          </AsyncBoundary>
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
            <AlertDialogTitle>
              {decision ? DIALOG_COPY[decision.action].title : "Decision"}
            </AlertDialogTitle>
            <AlertDialogDescription>
              {decision ? DIALOG_COPY[decision.action].description : ""}
            </AlertDialogDescription>
          </AlertDialogHeader>
          {decision?.action === "reject" && (
            <Field data-invalid={reasonRequired}>
              <FieldLabel htmlFor="section-change-decision-reason">
                Reason
              </FieldLabel>
              <Textarea
                id="section-change-decision-reason"
                value={reason}
                onChange={(event) => setReason(event.target.value)}
                disabled={mutation.isPending}
              />
              {reasonRequired && (
                <p className="text-sm text-destructive">
                  A reason is required to reject.
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
              {mutation.isPending
                ? "Saving"
                : decision
                  ? DIALOG_COPY[decision.action].confirm
                  : "Confirm"}
            </Button>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  )
}
