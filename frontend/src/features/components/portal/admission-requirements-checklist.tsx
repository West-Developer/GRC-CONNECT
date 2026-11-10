"use client"

import { zodResolver } from "@hookform/resolvers/zod"
import { CheckCircle2, Circle } from "lucide-react"
import { useState } from "react"
import { useForm } from "react-hook-form"
import { toast } from "sonner"

import { AsyncBoundary } from "@/features/components/portal/async-boundary"
import { Alert, AlertDescription } from "@/features/components/ui/alert"
import { Button } from "@/features/components/ui/button"
import { Checkbox } from "@/features/components/ui/checkbox"
import {
  Field,
  FieldError,
  FieldGroup,
  FieldLabel,
} from "@/features/components/ui/field"
import { Input } from "@/features/components/ui/input"
import {
  useAdmissionChecklistQuery,
  useCreateAdmissionRequirementTypeMutation,
  useSetAdmissionRequirementMutation,
} from "@/features/hooks/use-admission-requirements"
import {
  createAdmissionRequirementTypeSchema,
  type CreateAdmissionRequirementTypeInput,
} from "@/features/schemas/admission-requirements-schema"

function formatDate(value: string): string {
  return new Intl.DateTimeFormat("en-PH", {
    dateStyle: "medium",
    timeZone: "Asia/Manila",
  }).format(new Date(value))
}

function AddRequirementForm({ onDone }: { onDone: () => void }) {
  const create = useCreateAdmissionRequirementTypeMutation()
  const {
    formState: { errors },
    handleSubmit,
    register,
    reset,
  } = useForm<CreateAdmissionRequirementTypeInput>({
    resolver: zodResolver(createAdmissionRequirementTypeSchema),
    defaultValues: { name: "", category: "additional" },
  })

  const submit = async (values: CreateAdmissionRequirementTypeInput) => {
    try {
      await create.mutateAsync(values)
      toast.success("Requirement added to the list.")
      reset({ name: "", category: values.category })
      onDone()
    } catch {
      toast.error(
        "The requirement could not be added. It may already be on the list.",
      )
    }
  }

  return (
    <form
      className="grid gap-3 rounded-lg border bg-card p-3"
      aria-label="Add a requirement"
      onSubmit={(event) => void handleSubmit(submit)(event)}
    >
      <FieldGroup className="grid gap-3 sm:grid-cols-[1fr_12rem_auto] sm:items-end">
        <Field data-invalid={errors.name ? true : undefined}>
          <FieldLabel htmlFor="new-requirement-name">Requirement</FieldLabel>
          <Input
            id="new-requirement-name"
            placeholder="e.g. Barangay Clearance"
            aria-invalid={errors.name ? true : undefined}
            {...register("name")}
          />
          {errors.name && <FieldError>{errors.name.message}</FieldError>}
        </Field>
        <Field>
          <FieldLabel htmlFor="new-requirement-category">Category</FieldLabel>
          <select
            id="new-requirement-category"
            className="h-9 w-full rounded-md border bg-background px-2 text-sm"
            {...register("category")}
          >
            <option value="freshman">Freshman</option>
            <option value="transferee">Transferee</option>
            <option value="additional">Additional</option>
          </select>
        </Field>
        <Button type="submit" disabled={create.isPending}>
          {create.isPending ? "Adding…" : "Add requirement"}
        </Button>
      </FieldGroup>
    </form>
  )
}

/**
 * A student's Admission requirements (ADR 0037). `studentId` null is the
 * signed-in Student's own, read-only. With a student id and `editable`,
 * Admission Staff tick requirements off and add new ones to the shared list.
 */
export function AdmissionRequirementsChecklist({
  studentId,
  editable = false,
}: {
  studentId: number | null
  editable?: boolean
}) {
  const query = useAdmissionChecklistQuery(studentId)
  const set = useSetAdmissionRequirementMutation(studentId ?? 0)
  const [adding, setAdding] = useState(false)

  const toggle = (requirementTypeId: number, isSubmitted: boolean) => {
    set.mutate(
      { requirementTypeId, isSubmitted },
      {
        onError: () =>
          toast.error("The requirement could not be updated. Try again."),
      },
    )
  }

  return (
    <AsyncBoundary
      query={query}
      loadingLabel="Loading the Admission requirements…"
    >
      {(checklist) => {
        const { summary } = checklist
        const percent =
          summary.required_count === 0
            ? 0
            : Math.round(
                (summary.submitted_count / summary.required_count) * 100,
              )

        return (
          <div className="grid gap-5">
            <div className="grid gap-2">
              <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                <p className="font-heading text-lg">
                  {summary.submitted_count} of {summary.required_count}{" "}
                  submitted
                </p>
                {summary.missing_count > 0 ? (
                  <p className="text-sm font-medium text-warning">
                    {summary.missing_count} missing
                  </p>
                ) : (
                  <p className="text-sm font-medium text-success">
                    Nothing missing
                  </p>
                )}
              </div>
              <div
                role="progressbar"
                aria-label="Requirements submitted"
                aria-valuemin={0}
                aria-valuemax={summary.required_count}
                aria-valuenow={summary.submitted_count}
                className="h-2 overflow-hidden rounded-full bg-muted"
              >
                <div
                  className={`h-full rounded-full ${summary.complete ? "bg-success" : "bg-primary"}`}
                  style={{ width: `${percent}%` }}
                />
              </div>
              {checklist.student.student_type_label === null &&
                (editable ? (
                  <Alert className="border-warning/40 bg-warning/5">
                    <AlertDescription className="text-warning">
                      No student type is recorded, so only the additional
                      requirements are listed. Set Freshman or Transferee above
                      to complete this checklist.
                    </AlertDescription>
                  </Alert>
                ) : (
                  <p className="text-xs text-muted-foreground">
                    No student type is recorded, so only the additional
                    requirements are listed.
                  </p>
                ))}
            </div>

            {checklist.categories.map((group) => {
              const done = group.items.filter(
                (item) => item.is_submitted,
              ).length

              return (
                <section
                  key={group.category}
                  aria-label={group.label}
                  className="grid gap-2"
                >
                  <h3 className="flex items-baseline justify-between gap-3 border-b pb-1.5 text-sm font-semibold">
                    <span>{group.label}</span>
                    <span className="text-xs font-normal text-muted-foreground">
                      {done} of {group.items.length}
                    </span>
                  </h3>
                  {group.items.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                      Nothing on this list yet.
                    </p>
                  ) : (
                    <ul className="grid gap-2 lg:grid-cols-2">
                      {group.items.map((item) => {
                        const inputId = `admission-requirement-${item.requirement_type_id}`

                        return (
                          <li
                            key={item.requirement_type_id}
                            className={`flex min-h-12 items-start gap-3 rounded-lg border px-3 py-2.5 ${
                              item.is_submitted ? "bg-muted/50" : "bg-card"
                            }`}
                          >
                            {editable ? (
                              <Checkbox
                                id={inputId}
                                className="mt-0.5"
                                checked={item.is_submitted}
                                disabled={set.isPending}
                                onCheckedChange={(checked) =>
                                  toggle(
                                    item.requirement_type_id,
                                    checked === true,
                                  )
                                }
                              />
                            ) : item.is_submitted ? (
                              <CheckCircle2
                                aria-hidden="true"
                                className="mt-0.5 size-4 shrink-0 text-success"
                              />
                            ) : (
                              <Circle
                                aria-hidden="true"
                                className="mt-0.5 size-4 shrink-0 text-warning"
                              />
                            )}
                            <div className="grid min-w-0 gap-0.5 text-sm">
                              {editable ? (
                                <FieldLabel
                                  htmlFor={inputId}
                                  className={
                                    item.is_submitted
                                      ? "font-normal"
                                      : "font-medium"
                                  }
                                >
                                  {item.name}
                                </FieldLabel>
                              ) : (
                                <span
                                  className={
                                    item.is_submitted ? "" : "font-medium"
                                  }
                                >
                                  {item.name}
                                </span>
                              )}
                              <span
                                className={`text-xs ${
                                  item.is_submitted
                                    ? "text-muted-foreground"
                                    : "text-warning"
                                }`}
                              >
                                {item.is_submitted && item.submitted_at
                                  ? `Submitted ${formatDate(item.submitted_at)}`
                                  : "Not yet submitted"}
                              </span>
                            </div>
                          </li>
                        )
                      })}
                    </ul>
                  )}
                </section>
              )
            })}

            {editable &&
              (adding ? (
                <AddRequirementForm onDone={() => setAdding(false)} />
              ) : (
                <div>
                  <Button
                    type="button"
                    variant="outline"
                    onClick={() => setAdding(true)}
                  >
                    Add a requirement
                  </Button>
                </div>
              ))}
          </div>
        )
      }}
    </AsyncBoundary>
  )
}
