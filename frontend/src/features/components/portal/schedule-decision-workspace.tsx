"use client"

import { useMutation, useQueryClient } from "@tanstack/react-query"
import { useState } from "react"

import { useAuth } from "@/features/auth/use-auth"
import type { UserRole } from "@/features/auth/roles"
import { AsyncBoundary } from "@/features/components/portal/async-boundary"
import { ScheduleReviewDialog } from "@/features/components/portal/schedule-review-dialog"
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
import { Button } from "@/features/components/ui/button"
import { Badge } from "@/features/components/ui/badge"
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/features/components/ui/card"
import { Field, FieldError, FieldLabel } from "@/features/components/ui/field"
import { Textarea } from "@/features/components/ui/textarea"
import { sectionsQueryKey } from "@/features/hooks/use-reference-data"
import {
  scheduleProposalsQueryKey,
  useScheduleProposalsQuery,
} from "@/features/hooks/use-scheduling"
import { scheduleProposalPresentation } from "@/features/lib/schedule-status"
import type {
  ScheduleAction,
  ScheduleProposal,
} from "@/features/schemas/scheduling-schema"
import {
  availableScheduleActions,
  transitionScheduleProposal,
} from "@/features/services/scheduling-service"

const actionLabel: Record<ScheduleAction, string> = {
  dean_approve: "Approve schedule",
  dean_return: "Return with notes",
  executive_return: "Return with notes",
  publish: "Publish schedule",
  close: "Close proposal",
}
function requiresReason(action: ScheduleAction) {
  return action === "dean_return" || action === "executive_return"
}

export function ScheduleDecisionControls({
  actorRole,
  proposals,
  viewMode = "all",
  readOnly = false,
}: {
  actorRole: UserRole
  proposals: readonly ScheduleProposal[]
  viewMode?: "all" | "review_only" | "history_only"
  /** Show every proposal as history and offer no decision buttons. */
  readOnly?: boolean
}) {
  const actionsFor = (proposal: ScheduleProposal) =>
    readOnly ? [] : availableScheduleActions(actorRole, proposal)
  const { session } = useAuth()
  const queryClient = useQueryClient()
  const [pending, setPending] = useState<{
    proposal: ScheduleProposal
    action: ScheduleAction
  } | null>(null)
  const [reason, setReason] = useState("")
  const [error, setError] = useState("")
  const [reviewingProposal, setReviewingProposal] = useState<ScheduleProposal | null>(null)
  const mutation = useMutation({
    mutationFn: ({
      id,
      action,
      decision_reason,
    }: {
      id: number
      action: ScheduleAction
      decision_reason?: string
    }) =>
      transitionScheduleProposal(id, {
        action,
        ...(decision_reason ? { decision_reason } : {}),
      }),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({
          queryKey: scheduleProposalsQueryKey(session?.userId ?? null),
          exact: true,
        }),
        queryClient.invalidateQueries({
          queryKey: sectionsQueryKey(session?.userId ?? null),
          exact: true,
        }),
      ])
      setPending(null)
      setReason("")
      setReviewingProposal(null)
    },
  })
  const reasonRequired =
    pending !== null && requiresReason(pending.action) && !reason.trim()

  const confirm = async () => {
    if (!pending || reasonRequired) return
    setError("")
    try {
      await mutation.mutateAsync({
        id: pending.proposal.id,
        action: pending.action,
        decision_reason: reason.trim() || undefined,
      })
    } catch {
      setError(
        "The schedule decision could not be saved. Check the connection and try again.",
      )
    }
  }

  const [activeTab, setActiveTab] = useState<"pending" | "history">("pending")
  const isExecutive = actorRole === "executive_director"

  // Proposals where the current role can perform an action
  const selectable = proposals.filter(
    (proposal) => actionsFor(proposal).length > 0,
  )

  // Proposals returned by Executive Director that are in draft state
  const returnedForReview = isExecutive
    ? proposals.filter(
        (proposal) =>
          proposal.status === "draft" &&
          (proposal.decision_history ?? []).some(
            (decision) => decision.action === "executive_return",
          ),
      )
    : []

  // Items to show under "For review":
  const reviewItems = isExecutive
    ? [...selectable, ...returnedForReview]
    : selectable

  // Proposals to show under "Decision History":
  // For Executive Director: all proposals that Dean has approved or that reached Executive
  const historyItems = isExecutive
    ? proposals.filter(
        (proposal) =>
          proposal.status === "dean_approved" ||
          proposal.status === "published" ||
          proposal.status === "closed" ||
          (proposal.decision_history ?? []).some(
            (decision) =>
              decision.action === "dean_approve" ||
              decision.action === "executive_return" ||
              decision.action === "executive_approve",
          ),
      )
    : proposals.filter(
        (proposal) => actionsFor(proposal).length === 0,
      )

  const showReviewList =
    viewMode === "all" ? activeTab === "pending" : viewMode === "review_only"
  const showHistoryList =
    viewMode === "all" ? activeTab === "history" : viewMode === "history_only"

  return (
    <div className="space-y-4">
      {error && (
        <Alert variant="destructive">
          <AlertDescription>{error}</AlertDescription>
        </Alert>
      )}

      {viewMode === "all" && (
        <div className="flex flex-wrap items-center gap-2 border-b pb-3">
          <Button
            type="button"
            size="sm"
            variant={activeTab === "pending" ? "default" : "outline"}
            onClick={() => setActiveTab("pending")}
          >
            Pending decisions ({reviewItems.length})
          </Button>
          <Button
            type="button"
            size="sm"
            variant={activeTab === "history" ? "default" : "outline"}
            onClick={() => setActiveTab("history")}
          >
            Decision history ({historyItems.length})
          </Button>
        </div>
      )}

      {showReviewList && (
        reviewItems.length === 0 ? (
          <p className="py-6 text-center text-muted-foreground">
            No schedule decisions are currently available.
          </p>
        ) : (
          <ul className="grid gap-3 md:grid-cols-2">
            {reviewItems.map((proposal) => {
              const actions = actionsFor(proposal)
              const isActionable = actions.length > 0
              const presentation = scheduleProposalPresentation(proposal)
              const priorReturn = [...(proposal.decision_history ?? [])]
                .reverse()
                .find(
                  (decision) =>
                    decision.action === "dean_return" ||
                    decision.action === "executive_return",
                )

              return (
                <li key={proposal.id}>
                  <Card size="sm" className="h-full">
                    <CardHeader>
                      <CardTitle>
                        {proposal.college_label ??
                          proposal.college?.toUpperCase() ??
                          `Proposal #${proposal.id}`}
                      </CardTitle>
                      <CardDescription>
                        {proposal.academic_term_label ??
                          `Academic term #${proposal.academic_term_id}`}
                      </CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-3">
                      <p>
                        Submitted by{" "}
                        {proposal.submitted_by_name ??
                          `Program Head #${proposal.submitted_by}`}
                      </p>
                      <div className="flex items-center gap-2">
                        <Badge variant={isActionable ? presentation.badgeVariant : "destructive"}>
                          {isActionable ? presentation.label : "Returned for revision"}
                        </Badge>
                      </div>
                      {priorReturn && (
                        <p className="rounded-md bg-muted p-2 text-sm text-muted-foreground">
                          <span className="font-medium text-foreground">
                            {isActionable ? "Previously returned" : "Returned"}
                          </span>{" "}
                          by {priorReturn.actor_name}
                          {priorReturn.notes ? `: ${priorReturn.notes}` : "."}
                        </p>
                      )}
                      {!isActionable && (
                        <p className="text-xs text-muted-foreground">
                          Waiting for Program Head revision and Dean resubmission.
                        </p>
                      )}
                      <div className="flex flex-wrap gap-2">
                        <Button
                          type="button"
                          variant="outline"
                          onClick={() => setReviewingProposal(proposal)}
                        >
                          Review schedule
                        </Button>
                        {actions.map((action) => (
                          <Button
                            key={action}
                            type="button"
                            variant={
                              requiresReason(action) ? "outline" : "default"
                            }
                            disabled={mutation.isPending}
                            onClick={() => {
                              setPending({ proposal, action })
                              setReason("")
                              setError("")
                            }}
                          >
                            {actionLabel[action]}
                          </Button>
                        ))}
                      </div>
                    </CardContent>
                  </Card>
                </li>
              )
            })}
          </ul>
        )
      )}

      {showHistoryList && (
        historyItems.length === 0 ? (
          <p className="py-6 text-center text-muted-foreground">
            No schedule decision history is currently available.
          </p>
        ) : (
          <ul className="grid gap-3 md:grid-cols-2">
            {historyItems.map((proposal) => {
              const presentation = scheduleProposalPresentation(proposal)
              const decisions = [...(proposal.decision_history ?? [])].reverse()

              let badgeVariant = presentation.badgeVariant
              let statusLabel = presentation.label

              if (proposal.status === "dean_approved") {
                badgeVariant = "warning"
                statusLabel = "Pending Decision"
              } else if (proposal.status === "published") {
                badgeVariant = "default"
                statusLabel = "Published"
              } else if (
                proposal.status === "draft" &&
                decisions.some(
                  (d) =>
                    d.action === "executive_return" || d.action === "dean_return",
                )
              ) {
                badgeVariant = "destructive"
                statusLabel = "Returned"
              } else if (proposal.status === "closed") {
                badgeVariant = "secondary"
                statusLabel = "Closed"
              }

              return (
                <li key={proposal.id}>
                  <Card size="sm" className="h-full">
                    <CardHeader>
                      <CardTitle>
                        {proposal.college_label ??
                          proposal.college?.toUpperCase() ??
                          `Proposal #${proposal.id}`}
                      </CardTitle>
                      <CardDescription>
                        {proposal.academic_term_label ??
                          `Academic term #${proposal.academic_term_id}`}
                      </CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-3">
                      <p>
                        Submitted by{" "}
                        {proposal.submitted_by_name ??
                          `Program Head #${proposal.submitted_by}`}
                      </p>
                      <div className="flex items-center gap-2">
                        <Badge variant={badgeVariant}>
                          {statusLabel}
                        </Badge>
                        {proposal.decided_at && (
                          <span className="text-xs text-muted-foreground">
                            {new Date(proposal.decided_at).toLocaleDateString()}
                          </span>
                        )}
                      </div>
                      {proposal.decision_reason && (
                        <p className="rounded-md bg-muted p-2 text-sm text-muted-foreground">
                          <span className="font-medium text-foreground">
                            Remarks:
                          </span>{" "}
                          {proposal.decision_reason}
                        </p>
                      )}
                      {decisions.length > 0 && (
                        <div className="rounded-md border bg-muted/20 p-2 text-xs space-y-1">
                          <p className="font-semibold text-muted-foreground uppercase text-[10px]">
                            Decision history
                          </p>
                          {decisions.slice(0, 3).map((d, i) => (
                            <p key={i}>
                              <span className="font-medium">
                                {d.action_label}
                              </span>{" "}
                              by {d.actor_name} on{" "}
                              {new Date(d.decided_at).toLocaleDateString()}
                              {d.notes ? ` — "${d.notes}"` : ""}
                            </p>
                          ))}
                        </div>
                      )}
                      <Button
                        type="button"
                        variant="outline"
                        onClick={() => setReviewingProposal(proposal)}
                      >
                        View schedule
                      </Button>
                    </CardContent>
                  </Card>
                </li>
              )
            })}
          </ul>
        )
      )}
      <ScheduleReviewDialog
        actorRole={actorRole}
        proposal={reviewingProposal}
        decisionPending={mutation.isPending}
        readOnly={readOnly}
        onOpenChange={(open) => {
          if (!open) setReviewingProposal(null)
        }}
        onDecision={(proposal, action) => {
          setPending({ proposal, action })
          setReason("")
          setError("")
        }}
      />
      <AlertDialog
        open={pending !== null}
        onOpenChange={(open) => {
          if (!open && !mutation.isPending) setPending(null)
        }}
      >
        <AlertDialogContent className="max-h-[100dvh] overflow-y-auto rounded-none sm:max-h-[90dvh] sm:rounded-xl">
          <AlertDialogHeader>
            <AlertDialogTitle>Confirm schedule decision</AlertDialogTitle>
            <AlertDialogDescription>
              This lifecycle transition is recorded in the operational audit
              log.
            </AlertDialogDescription>
          </AlertDialogHeader>
          {pending && requiresReason(pending.action) && (
            <Field data-invalid={reasonRequired}>
              <FieldLabel htmlFor="decision-reason">Notes for Program Head</FieldLabel>
              <Textarea
                id="decision-reason"
                value={reason}
                onChange={(event) => setReason(event.target.value)}
                disabled={mutation.isPending}
                aria-describedby={
                  reasonRequired ? "decision-reason-error" : undefined
                }
              />
              {reasonRequired && <FieldError id="decision-reason-error">Remarks are required before returning this schedule.</FieldError>}
            </Field>
          )}
          <AlertDialogFooter className="[&_button]:w-full sm:[&_button]:w-auto">
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
    </div>
  )
}

export function ScheduleDecisionWorkspace() {
  const { session } = useAuth()
  const authorized = session?.role === "dean"
  const proposalsQuery = useScheduleProposalsQuery({ enabled: authorized })

  return (
    <WorkspacePage
      title="Enrollment planning review"
      description="Review each department's submitted enrollment plan, approve it, or return it with clear notes for the Program Head."
      unauthorized={!authorized}
      lastUpdated={proposalsQuery.dataUpdatedAt}
    >
      <AsyncBoundary
        query={proposalsQuery}
        loadingLabel="Loading schedule proposals…"
      >
        {(proposals) => (
          <Card>
            <CardHeader>
              <CardTitle level={2}>Enrollment planning review</CardTitle>
            </CardHeader>
            <CardContent>
              <ScheduleDecisionControls
                actorRole="dean"
                proposals={proposals}
              />
            </CardContent>
          </Card>
        )}
      </AsyncBoundary>
    </WorkspacePage>
  )
}
