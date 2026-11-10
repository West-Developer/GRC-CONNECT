"use client"

import { zodResolver } from "@hookform/resolvers/zod"
import { useState } from "react"
import { Controller, useForm } from "react-hook-form"

import { useAuth } from "@/features/auth/use-auth"
import { ArchiveTermDialog } from "@/features/components/portal/archive-term-dialog"
import { AsyncBoundary } from "@/features/components/portal/async-boundary"
import { DataTable } from "@/features/components/portal/data-table"
import { EnrollmentScheduleCard } from "@/features/components/portal/enrollment-schedule-card"
import { WorkspacePage } from "@/features/components/portal/workspace-page"
import { Alert, AlertDescription } from "@/features/components/ui/alert"
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
import { Input } from "@/features/components/ui/input"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/features/components/ui/select"
import {
  useAcademicTermsQuery,
  useCreateAcademicTermMutation,
} from "@/features/hooks/use-reference-data"
import { applyApiFieldErrors } from "@/features/lib/api-form-errors"
import {
  storeAcademicTermInputSchema,
  type StoreAcademicTermInput,
} from "@/features/schemas/academic-term-schema"
import { formatAcademicTerm } from "@/features/services/reference-data-service"

const fresh: StoreAcademicTermInput = {
  school_year: "",
  semester: "1st",
  enrollment_opens_at: "",
  enrollment_closes_at: "",
  add_drop_deadline_at: "",
}

export function AcademicTermWorkspace() {
  useAuth()
  const termsQuery = useAcademicTermsQuery()
  const mutation = useCreateAcademicTermMutation()
  const [requestError, setRequestError] = useState("")
  const [receiptSchoolYear, setReceiptSchoolYear] = useState("")
  const form = useForm<StoreAcademicTermInput>({
    resolver: zodResolver(storeAcademicTermInputSchema),
    defaultValues: fresh,
  })

  const save = async (input: StoreAcademicTermInput) => {
    setRequestError("")
    setReceiptSchoolYear("")
    try {
      const created = await mutation.mutateAsync(input)
      form.reset(fresh)
      setReceiptSchoolYear(`${created.school_year} · ${created.semester}`)
    } catch (error) {
      if (!applyApiFieldErrors(error, form.setError))
        setRequestError(
          "Academic term could not be created. Check the connection or conflicting dates, then retry.",
        )
    }
  }

  const currentTerm =
    termsQuery.data?.find((term) => term.is_actionable_current) ??
    termsQuery.data?.find((term) => term.status === "semester_ongoing") ??
    null
  // Term creation only happens two ways from here on: archiving the current
  // term opens the next one, or — the one exception — there is no
  // non-archived term at all yet (a brand-new deployment) and this card is
  // the sole way to start the very first cycle.
  const hasNonArchivedTerm = (termsQuery.data ?? []).some(
    (term) => term.status !== "archived",
  )
  const lifecycleSteps = [
    { label: "Term Setup", active: currentTerm?.status === "draft" },
    {
      label: "College Planning",
      active: currentTerm?.status === "for_dean_approval",
    },
    {
      label: "Enrollment Ongoing",
      active: currentTerm?.status === "semester_ongoing",
    },
    {
      label: "Archive",
      active:
        currentTerm?.status === "semester_closed" ||
        currentTerm?.status === "archived",
    },
  ]

  return (
    <WorkspacePage
      title="Enrollment"
      description="Create the school year and semester that starts the enrollment cycle — Process 1."
      lastUpdated={termsQuery.dataUpdatedAt}
    >
      {requestError && (
        <Alert variant="destructive">
          <AlertDescription>{requestError}</AlertDescription>
        </Alert>
      )}
      {receiptSchoolYear && (
        <Alert>
          <AlertDescription>
            Created {receiptSchoolYear} as a Draft term.
          </AlertDescription>
        </Alert>
      )}
      <Card>
        <CardHeader>
          <CardTitle>Enrollment cycle</CardTitle>
          <CardDescription>
            One clear path for the current school year and semester. Archive
            closes the active semester and keeps the record available for
            history.
          </CardDescription>
        </CardHeader>
        <CardContent className="grid gap-4">
          <ol
            className="grid gap-2 md:grid-cols-4"
            aria-label="Enrollment lifecycle progress"
          >
            {lifecycleSteps.map((step, index) => (
              <li
                key={step.label}
                className={
                  step.active
                    ? "rounded-lg border border-primary bg-primary/10 p-3 text-sm font-medium"
                    : "rounded-lg border border-border p-3 text-sm text-muted-foreground"
                }
              >
                <span className="mr-2" aria-hidden="true">
                  {index + 1}.
                </span>
                {step.label}
              </li>
            ))}
          </ol>
          {currentTerm ? (
            <div className="flex flex-wrap items-center gap-3">
              <p className="text-sm">
                Current: <strong>{formatAcademicTerm(currentTerm)}</strong> ·{" "}
                {currentTerm.status_label}
              </p>
              {(currentTerm.status === "semester_ongoing" ||
                currentTerm.status === "semester_closed") && (
                <ArchiveTermDialog
                  term={currentTerm}
                  trigger={
                    <Button
                      type="button"
                      variant="outline"
                      className="w-full sm:w-auto"
                    >
                      Archive current semester
                    </Button>
                  }
                />
              )}
            </div>
          ) : (
            <Alert>
              <AlertDescription>
                No current school year and semester yet. Create the next term
                above to open enrollment planning for Program Heads.
              </AlertDescription>
            </Alert>
          )}
        </CardContent>
      </Card>
      {currentTerm &&
        currentTerm.status !== "semester_closed" &&
        currentTerm.status !== "archived" && (
          <EnrollmentScheduleCard currentTerm={currentTerm} />
        )}
      {!termsQuery.isPending && !hasNonArchivedTerm && (
        <Card>
          <CardHeader>
            <CardTitle>Start the first school year</CardTitle>
            <CardDescription>
              No school year exists yet. Every later term is created by
              archiving the one before it — this form only runs once, to start
              the very first cycle. The new term starts in Draft status.
            </CardDescription>
          </CardHeader>
          <CardContent>
            <form
              noValidate
              onSubmit={(event) => void form.handleSubmit(save)(event)}
            >
              <FieldGroup>
                <div className="grid gap-3 md:grid-cols-2">
                  <Field
                    data-invalid={Boolean(form.formState.errors.school_year)}
                  >
                    <FieldLabel htmlFor="term-school-year">
                      School year
                    </FieldLabel>
                    <Input
                      id="term-school-year"
                      placeholder="2026-2027"
                      aria-invalid={Boolean(form.formState.errors.school_year)}
                      {...form.register("school_year")}
                    />
                    <FieldError>
                      {form.formState.errors.school_year?.message}
                    </FieldError>
                  </Field>
                  <Field>
                    <FieldLabel htmlFor="term-semester">Semester</FieldLabel>
                    <Controller
                      control={form.control}
                      name="semester"
                      render={({ field }) => (
                        <Select
                          value={field.value}
                          onValueChange={field.onChange}
                        >
                          <SelectTrigger id="term-semester" className="w-full">
                            <SelectValue />
                          </SelectTrigger>
                          <SelectContent>
                            <SelectItem value="1st">1st</SelectItem>
                            <SelectItem value="2nd">2nd</SelectItem>
                          </SelectContent>
                        </Select>
                      )}
                    />
                  </Field>
                </div>
                <div className="grid gap-3 md:grid-cols-2">
                  <Field
                    data-invalid={Boolean(
                      form.formState.errors.enrollment_opens_at,
                    )}
                  >
                    <FieldLabel htmlFor="term-enrollment-opens">
                      Enrollment start
                    </FieldLabel>
                    <Input
                      id="term-enrollment-opens"
                      type="datetime-local"
                      aria-invalid={Boolean(
                        form.formState.errors.enrollment_opens_at,
                      )}
                      {...form.register("enrollment_opens_at")}
                    />
                    <FieldError>
                      {form.formState.errors.enrollment_opens_at?.message}
                    </FieldError>
                  </Field>
                  <Field
                    data-invalid={Boolean(
                      form.formState.errors.enrollment_closes_at,
                    )}
                  >
                    <FieldLabel htmlFor="term-enrollment-closes">
                      Enrollment deadline
                    </FieldLabel>
                    <Input
                      id="term-enrollment-closes"
                      type="datetime-local"
                      aria-invalid={Boolean(
                        form.formState.errors.enrollment_closes_at,
                      )}
                      {...form.register("enrollment_closes_at")}
                    />
                    <FieldError>
                      {form.formState.errors.enrollment_closes_at?.message}
                    </FieldError>
                  </Field>
                  <Field
                    data-invalid={Boolean(
                      form.formState.errors.add_drop_deadline_at,
                    )}
                  >
                    <FieldLabel htmlFor="term-add-drop">
                      Add/drop/Change subject deadline
                    </FieldLabel>
                    <Input
                      id="term-add-drop"
                      type="datetime-local"
                      aria-invalid={Boolean(
                        form.formState.errors.add_drop_deadline_at,
                      )}
                      {...form.register("add_drop_deadline_at")}
                    />
                    <FieldError>
                      {form.formState.errors.add_drop_deadline_at?.message}
                    </FieldError>
                  </Field>
                </div>
                <Button
                  type="submit"
                  className="w-full sm:w-auto"
                  disabled={mutation.isPending}
                >
                  {mutation.isPending ? "Creating term" : "Create school year"}
                </Button>
              </FieldGroup>
            </form>
          </CardContent>
        </Card>
      )}

      <AsyncBoundary query={termsQuery} loadingLabel="Loading academic terms…">
        {(terms) => (
          <DataTable
            caption="Every academic term, most recent first"
            rows={[...terms].sort((a, b) => {
              if (a.is_actionable_current && !b.is_actionable_current) return -1
              if (!a.is_actionable_current && b.is_actionable_current) return 1
              if (a.school_year !== b.school_year) {
                return b.school_year.localeCompare(a.school_year)
              }
              return b.semester.localeCompare(a.semester)
            })}
            rowKey={(term) => term.id}
            emptyMessage="No academic terms exist yet — create the first one above."
            columns={[
              {
                key: "term",
                header: "School year · semester",
                render: (term) => formatAcademicTerm(term),
              },
              {
                key: "status",
                header: "Status",
                render: (term) => term.status_label,
              },
              {
                key: "lifecycle_action",
                header: "Action",
                render: (term) =>
                  term.status === "semester_ongoing" ||
                  term.status === "semester_closed" ? (
                    <ArchiveTermDialog
                      term={term}
                      trigger={
                        <Button type="button" size="sm" variant="outline">
                          Archive
                        </Button>
                      }
                    />
                  ) : (
                    "—"
                  ),
              },
              {
                key: "enrollment_window",
                header: "Enrollment window",
                render: (term) =>
                  term.enrollment_opens_at && term.enrollment_closes_at
                    ? `${term.enrollment_opens_at} – ${term.enrollment_closes_at}`
                    : "Not set",
              },
            ]}
            renderCard={(term) => {
              const termLabel = formatAcademicTerm(term)
              const canArchive =
                term.status === "semester_ongoing" ||
                term.status === "semester_closed"
              const enrollmentWindow =
                term.enrollment_opens_at && term.enrollment_closes_at
                  ? `${term.enrollment_opens_at} – ${term.enrollment_closes_at}`
                  : "Not set"

              return (
                <Card
                  role="article"
                  aria-label={`${termLabel} enrollment cycle`}
                  size="sm"
                >
                  <CardHeader>
                    <CardTitle level={3}>{termLabel}</CardTitle>
                    <CardDescription>{term.status_label}</CardDescription>
                  </CardHeader>
                  <CardContent className="grid gap-3">
                    <dl className="grid gap-2 text-sm">
                      <div className="grid grid-cols-[8.5rem_minmax(0,1fr)] gap-2">
                        <dt className="text-muted-foreground">
                          Enrollment window
                        </dt>
                        <dd className="min-w-0 text-right font-medium">
                          {enrollmentWindow}
                        </dd>
                      </div>
                    </dl>
                    {canArchive && (
                      <ArchiveTermDialog
                        term={term}
                        trigger={
                          <Button
                            type="button"
                            className="w-full"
                            variant="outline"
                          >
                            Archive term
                          </Button>
                        }
                      />
                    )}
                  </CardContent>
                </Card>
              )
            }}
          />
        )}
      </AsyncBoundary>
    </WorkspacePage>
  )
}
