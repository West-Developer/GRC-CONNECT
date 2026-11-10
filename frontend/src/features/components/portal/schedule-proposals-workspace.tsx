"use client"

import { zodResolver } from "@hookform/resolvers/zod"
import { useMutation, useQueryClient } from "@tanstack/react-query"
import { useState } from "react"
import { Controller, useForm } from "react-hook-form"

import { useAuth } from "@/features/auth/use-auth"
import { AsyncBoundary } from "@/features/components/portal/async-boundary"
import { WorkspacePage } from "@/features/components/portal/workspace-page"
import { Alert, AlertDescription } from "@/features/components/ui/alert"
import { Badge } from "@/features/components/ui/badge"
import { Button } from "@/features/components/ui/button"
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/features/components/ui/card"
import {
  Field,
  FieldError,
  FieldGroup,
  FieldLabel,
} from "@/features/components/ui/field"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/features/components/ui/select"
import {
  useScheduleProposalsQuery,
  scheduleProposalsQueryKey,
} from "@/features/hooks/use-scheduling"
import { useAcademicTermsQuery } from "@/features/hooks/use-reference-data"
import { applyApiFieldErrors } from "@/features/lib/api-form-errors"
import { scheduleProposalPresentation } from "@/features/lib/schedule-status"
import {
  scheduleProposalInputSchema,
  type ScheduleProposalInput,
} from "@/features/schemas/scheduling-schema"
import { createScheduleProposal } from "@/features/services/scheduling-service"
import {
  formatAcademicTerm,
  getActiveAcademicTerm,
} from "@/features/services/reference-data-service"

export function ScheduleProposalsWorkspace() {
  const { session } = useAuth()
  const queryClient = useQueryClient()
  const termsQuery = useAcademicTermsQuery()
  const proposalsQuery = useScheduleProposalsQuery()
  const activeTerm = getActiveAcademicTerm(termsQuery.data)
  const [requestError, setRequestError] = useState("")
  // No form-level `defaultValues` for `academic_term_id`: `useForm`'s
  // `defaultValues` seeds the field's value synchronously at creation time,
  // before the active term is known, and — once seeded — takes precedence
  // over `Controller`'s own `defaultValue` prop even after `Controller`
  // later mounts with the real value (react-hook-form only honors a
  // `Controller`'s `defaultValue` for a field it has never seen a
  // form-level default for). Leaving it unset here lets the `Controller`
  // below own the field's one true default once the active term is known.
  const form = useForm<ScheduleProposalInput>({
    resolver: zodResolver(scheduleProposalInputSchema),
  })
  const mutation = useMutation({
    mutationFn: createScheduleProposal,
    onSuccess: () =>
      queryClient.invalidateQueries({
        queryKey: scheduleProposalsQueryKey(session?.userId ?? null),
        exact: true,
      }),
  })
  const save = async (input: ScheduleProposalInput) => {
    setRequestError("")
    try {
      await mutation.mutateAsync(input)
    } catch (error) {
      if (!applyApiFieldErrors(error, form.setError))
        setRequestError(
          "The draft proposal could not be created. Check the connection and try again.",
        )
    }
  }
  const combinedQuery = {
    isPending: termsQuery.isPending || proposalsQuery.isPending,
    isError: termsQuery.isError || proposalsQuery.isError,
    error: termsQuery.error ?? proposalsQuery.error,
    data:
      termsQuery.data && proposalsQuery.data
        ? { terms: termsQuery.data, proposals: proposalsQuery.data }
        : undefined,
    refetch: () => {
      void termsQuery.refetch()
      void proposalsQuery.refetch()
    },
  }
  return (
    <WorkspacePage
      title="Schedule proposals"
      description="Create a draft proposal for review. Dean and executive decisions are intentionally unavailable in this Program Head workspace."
      lastUpdated={proposalsQuery.dataUpdatedAt}
    >
      {requestError && (
        <Alert variant="destructive">
          <AlertDescription>{requestError}</AlertDescription>
        </Alert>
      )}
      <AsyncBoundary
        query={combinedQuery}
        loadingLabel="Loading schedule reference data…"
      >
        {({ terms, proposals }) => (
          <>
            <Card>
              <CardHeader>
                <CardTitle level={2}>Create draft proposal</CardTitle>
                <CardDescription>
                  Only one active proposal is allowed for each academic term.
                </CardDescription>
              </CardHeader>
              <CardContent>
                <form
                  noValidate
                  onSubmit={(event) => void form.handleSubmit(save)(event)}
                >
                  <FieldGroup>
                    <Field
                      data-invalid={Boolean(
                        form.formState.errors.academic_term_id,
                      )}
                    >
                      <FieldLabel htmlFor="proposal-term">
                        Academic term
                      </FieldLabel>
                      <Controller
                        key={activeTerm?.id ?? "unselected"}
                        control={form.control}
                        name="academic_term_id"
                        defaultValue={activeTerm?.id ?? 0}
                        render={({ field }) => (
                          <Select
                            value={field.value ? String(field.value) : ""}
                            onValueChange={(value) =>
                              field.onChange(Number(value))
                            }
                          >
                            <SelectTrigger
                              id="proposal-term"
                              className="w-full"
                            >
                              <SelectValue placeholder="Select an academic term" />
                            </SelectTrigger>
                            <SelectContent>
                              {terms.map((term) => (
                                <SelectItem
                                  key={term.id}
                                  value={String(term.id)}
                                >
                                  {formatAcademicTerm(term)}
                                </SelectItem>
                              ))}
                            </SelectContent>
                          </Select>
                        )}
                      />
                      <FieldError>
                        {form.formState.errors.academic_term_id?.message}
                      </FieldError>
                    </Field>
                    <Button type="submit" disabled={mutation.isPending}>
                      {mutation.isPending
                        ? "Creating draft proposal"
                        : "Create draft proposal"}
                    </Button>
                  </FieldGroup>
                </form>
              </CardContent>
            </Card>
            <section aria-label="Existing schedule proposals">
              <h2>Existing proposals</h2>
              {proposals.length === 0 ? (
                <p>No proposals have been created.</p>
              ) : (
                <ul className="grid gap-2 md:grid-cols-2">
                  {proposals.map((proposal) => {
                    const presentation = scheduleProposalPresentation(proposal)

                    return (
                      <li key={proposal.id} className="rounded-md border p-3">
                        <p>
                          {terms.find(
                            (term) => term.id === proposal.academic_term_id,
                          )
                            ? formatAcademicTerm(
                                terms.find(
                                  (term) => term.id === proposal.academic_term_id,
                                )!,
                              )
                            : "Academic term unavailable"}
                        </p>
                        <Badge variant={presentation.badgeVariant}>
                          {presentation.label}
                        </Badge>
                      </li>
                    )
                  })}
                </ul>
              )}
            </section>
          </>
        )}
      </AsyncBoundary>
    </WorkspacePage>
  )
}
