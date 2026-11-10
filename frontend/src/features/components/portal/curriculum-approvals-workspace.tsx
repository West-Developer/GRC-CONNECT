"use client"

import { useMutation, useQueryClient } from "@tanstack/react-query"
import { useState } from "react"

import { useAuth } from "@/features/auth/use-auth"
import { AsyncBoundary } from "@/features/components/portal/async-boundary"
import { CurriculumView } from "@/features/components/portal/curriculum-view"
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
import { Button } from "@/features/components/ui/button"
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/features/components/ui/card"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/features/components/ui/dialog"
import { Field, FieldError, FieldLabel } from "@/features/components/ui/field"
import { Textarea } from "@/features/components/ui/textarea"
import {
  curriculaQueryKey,
  useCurriculaQuery,
} from "@/features/hooks/use-curricula"
import { useProgramsQuery } from "@/features/hooks/use-reference-data"
import { transitionCurriculum } from "@/features/services/curriculum-service"
import type { Curriculum } from "@/features/schemas/reference-data-schema"
import type { CurriculumAction } from "@/features/schemas/curriculum-schema"

const statusForRole = {
  dean: "pending_dean_review",
  executive_director: "pending_executive_review",
} as const

const approveActionForRole = {
  dean: "dean_approve",
  executive_director: "executive_approve",
} as const

const returnActionForRole = {
  dean: "dean_return",
  executive_director: "executive_return",
} as const

export function CurriculumApprovalsWorkspace() {
  const { session } = useAuth()
  const queryClient = useQueryClient()
  const curriculaQuery = useCurriculaQuery()
  const programsQuery = useProgramsQuery()
  const [reviewing, setReviewing] = useState<Curriculum | null>(null)
  const [returning, setReturning] = useState<Curriculum | null>(null)
  const [reason, setReason] = useState("")
  const [reasonError, setReasonError] = useState("")

  const role = session?.role
  const pendingStatus =
    role === "dean" || role === "executive_director"
      ? statusForRole[role]
      : null

  const mutation = useMutation({
    mutationFn: ({
      id,
      action,
      reason: mutationReason,
    }: {
      id: number
      action: CurriculumAction
      reason?: string
    }) =>
      transitionCurriculum(id, {
        action,
        ...(mutationReason ? { reason: mutationReason } : {}),
      }),
    // The transition response already carries the authoritative post-write
    // status, so write it straight into the cache instead of invalidating —
    // an invalidate-triggered refetch could otherwise race this update and
    // briefly show the pre-decision status again.
    onSuccess: (updated) => {
      queryClient.setQueryData<Curriculum[]>(
        curriculaQueryKey(session?.userId ?? null),
        (old) =>
          (old ?? []).map((item) => (item.id === updated.id ? updated : item)),
      )
      setReviewing(null)
      setReturning(null)
      setReason("")
      setReasonError("")
    },
  })

  if (!role || (role !== "dean" && role !== "executive_director")) return null

  const pending = (curriculaQuery.data ?? []).filter(
    (item) => item.status === pendingStatus,
  )
  const programFor = (programId: number) =>
    (programsQuery.data ?? []).find((program) => program.id === programId)

  const approve = (curriculum: Curriculum) =>
    mutation.mutate({ id: curriculum.id, action: approveActionForRole[role] })

  const confirmReturn = () => {
    if (!returning) return
    if (!reason.trim()) {
      setReasonError("A reason is required to return this curriculum.")
      return
    }
    mutation.mutate({
      id: returning.id,
      action: returnActionForRole[role],
      reason: reason.trim(),
    })
  }

  const query = {
    isPending: curriculaQuery.isPending || programsQuery.isPending,
    isError: curriculaQuery.isError || programsQuery.isError,
    error: curriculaQuery.error ?? programsQuery.error,
    data: true as const,
    refetch: () => {
      void curriculaQuery.refetch()
      void programsQuery.refetch()
    },
  }

  return (
    <WorkspacePage
      title="Curriculum Approvals"
      description="Review curricula submitted by Program Heads and record your decision."
      lastUpdated={curriculaQuery.dataUpdatedAt}
    >
      <AsyncBoundary query={query} loadingLabel="Loading pending curricula…">
        {() =>
          pending.length === 0 ? (
            <p className="text-sm text-muted-foreground">
              No curricula are pending your review.
            </p>
          ) : (
            <div className="grid gap-3">
              {pending.map((curriculum) => (
                <Card key={curriculum.id}>
                  <CardHeader>
                    <CardTitle>{curriculum.name}</CardTitle>
                    <CardDescription>
                      {programFor(curriculum.program_id)?.code ?? "—"} ·{" "}
                      {curriculum.effective_school_year}
                    </CardDescription>
                  </CardHeader>
                  <CardContent className="flex gap-2">
                    <Button
                      type="button"
                      onClick={() => setReviewing(curriculum)}
                    >
                      Review
                    </Button>
                  </CardContent>
                </Card>
              ))}
            </div>
          )
        }
      </AsyncBoundary>

      <Dialog
        open={reviewing !== null}
        onOpenChange={(open) => !open && setReviewing(null)}
      >
        <DialogContent className="max-h-[90vh] max-w-5xl gap-0 overflow-hidden p-0 sm:max-w-5xl">
          <DialogHeader>
            <div className="border-b bg-muted/30 px-6 py-5 pr-14">
              <DialogTitle>{reviewing?.name}</DialogTitle>
              <DialogDescription>
                {reviewing && programFor(reviewing.program_id)?.name} ·{" "}
                {reviewing?.effective_school_year}
              </DialogDescription>
            </div>
          </DialogHeader>
          <div className="max-h-[calc(90vh-11rem)] overflow-y-auto px-6 py-5">
            {reviewing && programsQuery.data && (
              <CurriculumView
                preview
                programs={programsQuery.data.filter(
                  (program) => program.id === reviewing.program_id,
                )}
                // CurriculumView only renders published curricula. This
                // review-only preview temporarily supplies the pending
                // record as active without persisting that status.
                curricula={[{ ...reviewing, status: "active" as const }]}
              />
            )}
          </div>
          <DialogFooter className="border-t bg-background px-6 py-4 sm:justify-end">
            <Button
              type="button"
              variant="outline"
              onClick={() => {
                setReturning(reviewing)
                setReviewing(null)
              }}
            >
              Return with notes
            </Button>
            <Button
              type="button"
              disabled={mutation.isPending}
              onClick={() => reviewing && approve(reviewing)}
            >
              Approve
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <AlertDialog
        open={returning !== null}
        onOpenChange={(open) => !open && setReturning(null)}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Return {returning?.name}?</AlertDialogTitle>
            <AlertDialogDescription>
              The curriculum goes back to the Program Head as a Draft. Explain
              what needs to change.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <Field data-invalid={Boolean(reasonError)}>
            <FieldLabel htmlFor="return-reason">
              Notes for Program Head
            </FieldLabel>
            <Textarea
              id="return-reason"
              value={reason}
              onChange={(event) => {
                setReason(event.target.value)
                setReasonError("")
              }}
            />
            <FieldError>{reasonError}</FieldError>
          </Field>
          <AlertDialogFooter>
            <AlertDialogCancel onClick={() => setReturning(null)}>
              Cancel
            </AlertDialogCancel>
            <Button type="button" onClick={confirmReturn}>
              Confirm return
            </Button>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </WorkspacePage>
  )
}
