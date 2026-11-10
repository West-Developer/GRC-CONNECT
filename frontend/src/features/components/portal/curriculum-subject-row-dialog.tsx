"use client"

import { zodResolver } from "@hookform/resolvers/zod"
import { useState } from "react"
import { useForm, useWatch } from "react-hook-form"
import { z } from "zod"

import { Alert, AlertDescription } from "@/features/components/ui/alert"
import { Button } from "@/features/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/features/components/ui/dialog"
import {
  Field,
  FieldError,
  FieldGroup,
  FieldLabel,
} from "@/features/components/ui/field"
import { Input } from "@/features/components/ui/input"
import { SearchableCombobox } from "@/features/components/ui/searchable-combobox"
import {
  Select,
  SelectContent,
  SelectGroup,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/features/components/ui/select"
import type { CurriculumSubjectPlacementInput } from "@/features/schemas/curriculum-schema"
import type { Subject } from "@/features/schemas/reference-data-schema"
import { isApiClientError } from "@/features/services/api-client"

export interface CurriculumSubjectCandidateQueryState {
  data: readonly Subject[] | undefined
  isPending: boolean
  isError: boolean
  error: unknown
  refetch: () => unknown
}

export interface CurriculumSubjectRowDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  yearLevel: number
  candidateQuery: CurriculumSubjectCandidateQueryState
  equivalencyCandidates?: readonly Subject[]
  isSubmitting: boolean
  onSubmit: (input: CurriculumSubjectPlacementInput) => Promise<void>
}

const newSubjectSchema = z.object({
  code: z.string().trim().min(1, "Enter a subject code."),
  title: z.string().trim().min(1, "Enter a subject description."),
  units: z.number().positive("Enter the subject units."),
  semester: z.enum(["1st", "2nd"]),
})

type NewSubjectValues = z.infer<typeof newSubjectSchema>
type SourceMode = "existing" | "new" | null

const newSubjectDefaults: NewSubjectValues = {
  code: "",
  title: "",
  units: 0,
  semester: "1st",
}

function requestMessage(error: unknown) {
  if (!isApiClientError(error))
    return "The subject row could not be added. Try again."

  return Object.values(error.fieldErrors ?? {}).flat()[0] ?? error.message
}

export function CurriculumSubjectRowDialog({
  open,
  onOpenChange,
  yearLevel,
  candidateQuery,
  equivalencyCandidates = [],
  isSubmitting,
  onSubmit,
}: CurriculumSubjectRowDialogProps) {
  const [mode, setMode] = useState<SourceMode>(null)
  const [selectedId, setSelectedId] = useState("")
  const [existingSemester, setExistingSemester] = useState<"1st" | "2nd">("1st")
  const [equivalentSourceSubjectId, setEquivalentSourceSubjectId] = useState("")
  const [requestError, setRequestError] = useState("")
  const [isAwaiting, setIsAwaiting] = useState(false)
  const form = useForm<NewSubjectValues>({
    resolver: zodResolver(newSubjectSchema),
    defaultValues: newSubjectDefaults,
  })
  const selectedSemester = useWatch({
    control: form.control,
    name: "semester",
  })
  const selected = (candidateQuery.data ?? []).find(
    (subject) => String(subject.id) === selectedId,
  )
  const busy = isSubmitting || isAwaiting

  const reset = () => {
    setMode(null)
    setSelectedId("")
    setExistingSemester("1st")
    setEquivalentSourceSubjectId("")
    setRequestError("")
    setIsAwaiting(false)
    form.reset(newSubjectDefaults)
  }
  const changeOpen = (nextOpen: boolean) => {
    if (!nextOpen) reset()
    onOpenChange(nextOpen)
  }

  const submitInput = async (input: CurriculumSubjectPlacementInput) => {
    setRequestError("")
    setIsAwaiting(true)
    try {
      await onSubmit(input)
      changeOpen(false)
    } catch (error) {
      setRequestError(requestMessage(error))
      setIsAwaiting(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={changeOpen}>
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>Add subject row</DialogTitle>
          <DialogDescription>
            Add a subject to the {yearLevel}
            {yearLevel === 1
              ? "st"
              : yearLevel === 2
                ? "nd"
                : yearLevel === 3
                  ? "rd"
                  : "th"}{" "}
            Year curriculum table.
          </DialogDescription>
        </DialogHeader>

        {requestError && (
          <Alert variant="destructive">
            <AlertDescription>{requestError}</AlertDescription>
          </Alert>
        )}

        {mode === null && (
          <div className="grid gap-3 sm:grid-cols-2">
            <Button
              type="button"
              variant="outline"
              aria-label="Use existing subject"
              className="h-auto min-h-24 flex-col items-start whitespace-normal p-4 text-left"
              onClick={() => setMode("existing")}
            >
              <span>Use existing subject</span>
              <span className="text-xs font-normal text-muted-foreground">
                Search the current curriculum for this program.
              </span>
            </Button>
            <Button
              type="button"
              variant="outline"
              aria-label="Create new subject"
              className="h-auto min-h-24 flex-col items-start whitespace-normal p-4 text-left"
              onClick={() => setMode("new")}
            >
              <span>Create new subject</span>
              <span className="text-xs font-normal text-muted-foreground">
                Create and place a new subject in one step.
              </span>
            </Button>
          </div>
        )}

        {mode === "existing" && (
          <div className="flex flex-col gap-4">
            {candidateQuery.isPending ? (
              <p className="text-sm text-muted-foreground">
                Loading current curriculum subjects…
              </p>
            ) : candidateQuery.isError ? (
              <Alert variant="destructive">
                <AlertDescription className="flex flex-wrap items-center justify-between gap-2">
                  Current curriculum subjects could not be loaded.
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => candidateQuery.refetch()}
                  >
                    Retry
                  </Button>
                </AlertDescription>
              </Alert>
            ) : (candidateQuery.data ?? []).length === 0 ? (
              <p className="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                No subjects are available from the current curriculum.
              </p>
            ) : (
              <FieldGroup>
                <Field>
                  <FieldLabel htmlFor="existing-subject-search">
                    Current curriculum subject
                  </FieldLabel>
                  <SearchableCombobox
                    id="existing-subject-search"
                    label="Current curriculum subject"
                    options={(candidateQuery.data ?? []).map((subject) => ({
                      value: String(subject.id),
                      label: `${subject.code} — ${subject.title}`,
                    }))}
                    value={selectedId}
                    onValueChange={setSelectedId}
                    placeholder="Search subjects"
                    emptyMessage="No matching current curriculum subject."
                    disabled={busy}
                  />
                </Field>
                {selected && (
                  <div className="grid gap-3 rounded-lg border bg-muted/30 p-3 sm:grid-cols-[1fr_2fr_0.75fr]">
                    <Field>
                      <FieldLabel htmlFor="existing-code">Code</FieldLabel>
                      <Input
                        id="existing-code"
                        aria-label="Selected subject code"
                        value={selected.code}
                        readOnly
                      />
                    </Field>
                    <Field>
                      <FieldLabel htmlFor="existing-description">
                        Description
                      </FieldLabel>
                      <Input
                        id="existing-description"
                        aria-label="Selected subject description"
                        value={selected.title}
                        readOnly
                      />
                    </Field>
                    <Field>
                      <FieldLabel htmlFor="existing-units">Units</FieldLabel>
                      <Input
                        id="existing-units"
                        aria-label="Selected subject units"
                        value={selected.units}
                        readOnly
                      />
                    </Field>
                  </div>
                )}
                <Field>
                  <FieldLabel htmlFor="existing-semester">Semester</FieldLabel>
                  <Select
                    value={existingSemester}
                    onValueChange={(value) => {
                      if (value === "1st" || value === "2nd")
                        setExistingSemester(value)
                    }}
                    disabled={busy}
                  >
                    <SelectTrigger id="existing-semester" aria-label="Semester">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectGroup>
                        <SelectItem value="1st">1st Semester</SelectItem>
                        <SelectItem value="2nd">2nd Semester</SelectItem>
                      </SelectGroup>
                    </SelectContent>
                  </Select>
                </Field>
              </FieldGroup>
            )}
          </div>
        )}

        {mode === "new" && (
          <form
            id="new-curriculum-subject-form"
            className="flex flex-col gap-4"
            onSubmit={(event) =>
              void form.handleSubmit((values) =>
                submitInput({
                  source: "new",
                  code: values.code.trim(),
                  title: values.title.trim(),
                  units: values.units,
                  year_level: yearLevel,
                  semester: values.semester,
                  ...(equivalentSourceSubjectId
                    ? {
                        equivalent_source_subject_id: Number(
                          equivalentSourceSubjectId,
                        ),
                      }
                    : {}),
                }),
              )(event)
            }
          >
            <FieldGroup>
              <Field data-invalid={Boolean(form.formState.errors.code)}>
                <FieldLabel htmlFor="new-subject-code">Subject code</FieldLabel>
                <Input
                  id="new-subject-code"
                  aria-invalid={Boolean(form.formState.errors.code)}
                  disabled={busy}
                  {...form.register("code")}
                />
                <FieldError>{form.formState.errors.code?.message}</FieldError>
              </Field>
              <Field data-invalid={Boolean(form.formState.errors.title)}>
                <FieldLabel htmlFor="new-subject-title">Description</FieldLabel>
                <Input
                  id="new-subject-title"
                  aria-invalid={Boolean(form.formState.errors.title)}
                  disabled={busy}
                  {...form.register("title")}
                />
                <FieldError>{form.formState.errors.title?.message}</FieldError>
              </Field>
              <div className="grid gap-3 sm:grid-cols-2">
                <Field data-invalid={Boolean(form.formState.errors.units)}>
                  <FieldLabel htmlFor="new-subject-units">Units</FieldLabel>
                  <Input
                    id="new-subject-units"
                    type="number"
                    min="0.5"
                    step="0.5"
                    aria-invalid={Boolean(form.formState.errors.units)}
                    disabled={busy}
                    {...form.register("units", { valueAsNumber: true })}
                  />
                  <FieldError>
                    {form.formState.errors.units?.message}
                  </FieldError>
                </Field>
                <Field>
                  <FieldLabel htmlFor="new-subject-semester">
                    Semester
                  </FieldLabel>
                  <Select
                    value={selectedSemester}
                    onValueChange={(value) => {
                      if (value === "1st" || value === "2nd")
                        form.setValue("semester", value)
                    }}
                    disabled={busy}
                  >
                    <SelectTrigger
                      id="new-subject-semester"
                      aria-label="Semester"
                    >
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectGroup>
                        <SelectItem value="1st">1st Semester</SelectItem>
                        <SelectItem value="2nd">2nd Semester</SelectItem>
                      </SelectGroup>
                    </SelectContent>
                  </Select>
                </Field>
              </div>
              {equivalencyCandidates.length > 0 && (
                <Field>
                  <FieldLabel htmlFor="equivalent-source-subject">
                    Equivalent old-curriculum subject
                  </FieldLabel>
                  <SearchableCombobox
                    id="equivalent-source-subject"
                    label="Equivalent old-curriculum subject"
                    options={equivalencyCandidates.map((subject) => ({
                      value: String(subject.id),
                      label: `${subject.code} — ${subject.title}`,
                    }))}
                    value={equivalentSourceSubjectId}
                    onValueChange={setEquivalentSourceSubjectId}
                    placeholder="Optional: search old curriculum subjects"
                    emptyMessage="No matching old-curriculum subject."
                    disabled={busy}
                  />
                </Field>
              )}
            </FieldGroup>
          </form>
        )}

        {mode !== null && (
          <DialogFooter>
            <Button
              type="button"
              variant="outline"
              disabled={busy}
              onClick={() => {
                setMode(null)
                setRequestError("")
              }}
            >
              Back
            </Button>
            <Button
              type={mode === "new" ? "submit" : "button"}
              form={mode === "new" ? "new-curriculum-subject-form" : undefined}
              disabled={
                busy ||
                (mode === "existing" &&
                  (selected === undefined || candidateQuery.isError))
              }
              onClick={
                mode === "existing" && selected
                  ? () =>
                      void submitInput({
                        source: "existing",
                        subject_id: selected.id,
                        year_level: yearLevel,
                        semester: existingSemester,
                      })
                  : undefined
              }
            >
              Add subject
            </Button>
          </DialogFooter>
        )}
      </DialogContent>
    </Dialog>
  )
}
