"use client"

import { zodResolver } from "@hookform/resolvers/zod"
import { useMutation, useQueryClient } from "@tanstack/react-query"
import { useState } from "react"
import { Controller, useForm, useWatch } from "react-hook-form"

import { useAuth } from "@/features/auth/use-auth"
import { AsyncBoundary } from "@/features/components/portal/async-boundary"
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
  useSectionsQuery,
  useSubjectsQuery,
  sectionsQueryKey,
} from "@/features/hooks/use-reference-data"
import { applyApiFieldErrors } from "@/features/lib/api-form-errors"
import {
  sectionEditorSchema,
  type SectionEditorValues,
  type SectionInput,
} from "@/features/schemas/scheduling-schema"
import {
  createSection,
  replaceSection,
} from "@/features/services/scheduling-service"
import {
  formatAcademicTerm,
  getActiveAcademicTerm,
} from "@/features/services/reference-data-service"

const fresh = (termId = 0): SectionEditorValues => ({
  academic_term_id: termId,
  subject_id: 0,
  section_code: "",
  professor_id: null,
  schedule_days: "",
  starts_at_time: "",
  ends_at_time: "",
  room: "",
  modality: "f2f",
  capacity: 1,
  viability_threshold: null,
  status: "planned",
})

export function SectionsWorkspace() {
  const { session } = useAuth()
  const queryClient = useQueryClient()
  const termsQuery = useAcademicTermsQuery()
  const subjectsQuery = useSubjectsQuery()
  const sectionsQuery = useSectionsQuery()
  const activeTerm = getActiveAcademicTerm(termsQuery.data)
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [requestError, setRequestError] = useState("")
  // `academic_term_id` is left out of the initial `defaultValues` below —
  // this form only ever mounts once AsyncBoundary shows its children (data
  // already loaded), so the `academic_term_id` Controller further down owns
  // its one true default via its own `defaultValue` prop + a `key` tied to
  // the active term, instead of a form-level default that would take
  // precedence over it. See ADR 0015 and the analogous fix in
  // schedule-proposals-workspace.tsx. Every other field has no such async
  // dependency and keeps `fresh()`'s static defaults.
  const form = useForm<SectionEditorValues, unknown, SectionInput>({
    resolver: zodResolver(sectionEditorSchema),
    defaultValues: {
      subject_id: 0,
      section_code: "",
      professor_id: null,
      schedule_days: "",
      starts_at_time: "",
      ends_at_time: "",
      room: "",
      modality: "f2f",
      capacity: 1,
      viability_threshold: null,
      status: "planned",
    },
  })
  const termId = useWatch({ control: form.control, name: "academic_term_id" })
  const viabilityThreshold = useWatch({
    control: form.control,
    name: "viability_threshold",
  })
  const visibleSections = (sectionsQuery.data ?? []).filter(
    (section) => section.academic_term_id === termId,
  )
  const selected =
    visibleSections.find((section) => section.id === selectedId) ?? null
  const userId = session?.userId ?? null
  const mutation = useMutation({
    mutationFn: (values: SectionInput) =>
      selected ? replaceSection(selected.id, values) : createSection(values),
    onSuccess: () =>
      queryClient.invalidateQueries({
        queryKey: sectionsQueryKey(userId),
        exact: true,
      }),
  })

  const edit = (id: number) => {
    const next = (sectionsQuery.data ?? []).find((section) => section.id === id)
    if (!next) return
    setSelectedId(id)
    form.reset({
      academic_term_id: next.academic_term_id,
      subject_id: next.subject_id,
      section_code: next.section_code,
      professor_id: next.professor_id,
      schedule_days: next.schedule_days ?? "",
      starts_at_time: next.starts_at_time ?? "",
      ends_at_time: next.ends_at_time ?? "",
      room: next.room ?? "",
      modality: next.modality ?? "f2f",
      capacity: next.capacity,
      viability_threshold: next.viability_threshold,
      status: next.status,
    })
  }
  const startNew = (nextTermId = termId) => {
    setSelectedId(null)
    form.reset(fresh(nextTermId === 0 ? (activeTerm?.id ?? 0) : nextTermId))
  }
  const retryReferences = () => {
    void Promise.all([
      termsQuery.refetch(),
      subjectsQuery.refetch(),
      sectionsQuery.refetch(),
    ])
  }
  const save = async (values: SectionInput) => {
    setRequestError("")
    // The resolver supplies the transformed, contract-ready values here.
    try {
      await mutation.mutateAsync(values)
      startNew()
    } catch (error) {
      if (!applyApiFieldErrors(error, form.setError))
        setRequestError(
          "The section could not be saved. Check the connection and try again.",
        )
    }
  }
  const referenceDataQuery = {
    isPending:
      termsQuery.isPending ||
      subjectsQuery.isPending ||
      sectionsQuery.isPending,
    isError:
      termsQuery.isError || subjectsQuery.isError || sectionsQuery.isError,
    error: termsQuery.error ?? subjectsQuery.error ?? sectionsQuery.error,
    data: true as const,
    refetch: retryReferences,
  }
  return (
    <WorkspacePage
      title="Sections and schedules"
      description="Plan each term's sections, capacity, room, and meeting time."
      lastUpdated={sectionsQuery.dataUpdatedAt}
    >
      {requestError && (
        <Alert variant="destructive">
          <AlertDescription>{requestError}</AlertDescription>
        </Alert>
      )}
      <AsyncBoundary
        query={referenceDataQuery}
        loadingLabel="Loading section planning data…"
      >
        {() => (
          <Card>
            <CardHeader>
              <CardTitle level={2}>
                {selected
                  ? `Edit section ${selected.section_code}`
                  : "New planned section"}
              </CardTitle>
              <CardDescription>
                Updates replace every writable section field. Meeting details
                are optional, but any entered time needs valid days and an end
                time.
              </CardDescription>
            </CardHeader>
            <CardContent>
              <div className="mb-4 grid gap-3 md:grid-cols-2">
                <Field>
                  <FieldLabel htmlFor="section-existing">
                    Existing section
                  </FieldLabel>
                  <Select
                    value={selectedId !== null ? String(selectedId) : "0"}
                    onValueChange={(value) =>
                      Number(value) ? edit(Number(value)) : startNew()
                    }
                  >
                    <SelectTrigger id="section-existing" className="w-full">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="0">New section</SelectItem>
                      {visibleSections.map((section) => (
                        <SelectItem key={section.id} value={String(section.id)}>
                          {section.section_code}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </Field>
                <div className="flex items-end">
                  <Button
                    type="button"
                    variant="outline"
                    onClick={() => startNew()}
                  >
                    New section
                  </Button>
                </div>
              </div>
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
                    <FieldLabel htmlFor="section-term">
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
                          onValueChange={(value) => startNew(Number(value))}
                        >
                          <SelectTrigger id="section-term" className="w-full">
                            <SelectValue placeholder="Select an academic term" />
                          </SelectTrigger>
                          <SelectContent>
                            {(termsQuery.data ?? []).map((term) => (
                              <SelectItem key={term.id} value={String(term.id)}>
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
                  <Field
                    data-invalid={Boolean(form.formState.errors.subject_id)}
                  >
                    <FieldLabel htmlFor="section-subject">Subject</FieldLabel>
                    <Controller
                      control={form.control}
                      name="subject_id"
                      render={({ field }) => (
                        <Select
                          value={field.value ? String(field.value) : ""}
                          onValueChange={(value) =>
                            field.onChange(Number(value))
                          }
                        >
                          <SelectTrigger
                            id="section-subject"
                            className="w-full"
                          >
                            <SelectValue placeholder="Select a subject" />
                          </SelectTrigger>
                          <SelectContent>
                            {(subjectsQuery.data ?? [])
                              .filter((subject) => subject.status === "active")
                              .map((subject) => (
                                <SelectItem
                                  key={subject.id}
                                  value={String(subject.id)}
                                >
                                  {subject.code} — {subject.title}
                                </SelectItem>
                              ))}
                          </SelectContent>
                        </Select>
                      )}
                    />
                    <FieldError>
                      {form.formState.errors.subject_id?.message}
                    </FieldError>
                  </Field>
                  <Field
                    data-invalid={Boolean(form.formState.errors.section_code)}
                  >
                    <FieldLabel htmlFor="section-code">Section code</FieldLabel>
                    <Input
                      id="section-code"
                      {...form.register("section_code")}
                    />
                    <FieldError>
                      {form.formState.errors.section_code?.message}
                    </FieldError>
                  </Field>
                  <Field
                    data-invalid={Boolean(form.formState.errors.schedule_days)}
                  >
                    <FieldLabel htmlFor="section-days">
                      Schedule days
                    </FieldLabel>
                    <Input
                      id="section-days"
                      placeholder="MWF or TTh"
                      {...form.register("schedule_days")}
                    />
                    <FieldError>
                      {form.formState.errors.schedule_days?.message}
                    </FieldError>
                  </Field>
                  <Field
                    data-invalid={Boolean(form.formState.errors.starts_at_time)}
                  >
                    <FieldLabel htmlFor="section-start">Start time</FieldLabel>
                    <Input
                      id="section-start"
                      placeholder="08:00:00"
                      {...form.register("starts_at_time")}
                    />
                    <FieldError>
                      {form.formState.errors.starts_at_time?.message}
                    </FieldError>
                  </Field>
                  <Field
                    data-invalid={Boolean(form.formState.errors.ends_at_time)}
                  >
                    <FieldLabel htmlFor="section-end">End time</FieldLabel>
                    <Input
                      id="section-end"
                      placeholder="09:00:00"
                      {...form.register("ends_at_time")}
                    />
                    <FieldError>
                      {form.formState.errors.ends_at_time?.message}
                    </FieldError>
                  </Field>
                  <Field>
                    <FieldLabel htmlFor="section-room">Room</FieldLabel>
                    <Input id="section-room" {...form.register("room")} />
                  </Field>
                  <Field>
                    <FieldLabel htmlFor="section-modality">Modality</FieldLabel>
                    <Controller
                      control={form.control}
                      name="modality"
                      render={({ field }) => (
                        <Select value={field.value ?? ""} onValueChange={field.onChange}>
                          <SelectTrigger id="section-modality" className="w-full"><SelectValue placeholder="Select modality" /></SelectTrigger>
                          <SelectContent>
                            <SelectItem value="hyflex_a">Hyflex A</SelectItem>
                            <SelectItem value="hyflex_b">Hyflex B</SelectItem>
                            <SelectItem value="f2f">F2F</SelectItem>
                          </SelectContent>
                        </Select>
                      )}
                    />
                  </Field>
                  <Field data-invalid={Boolean(form.formState.errors.capacity)}>
                    <FieldLabel htmlFor="section-capacity">Capacity</FieldLabel>
                    <Input
                      id="section-capacity"
                      type="number"
                      min={1}
                      {...form.register("capacity", { valueAsNumber: true })}
                    />
                    <FieldError>
                      {form.formState.errors.capacity?.message}
                    </FieldError>
                  </Field>
                  <Field>
                    <FieldLabel htmlFor="section-threshold">
                      Viability threshold
                    </FieldLabel>
                    <Input
                      id="section-threshold"
                      type="number"
                      min={1}
                      value={viabilityThreshold ?? ""}
                      onChange={(event) =>
                        form.setValue(
                          "viability_threshold",
                          event.target.value
                            ? Number(event.target.value)
                            : null,
                        )
                      }
                    />
                  </Field>
                  <Field>
                    <FieldLabel htmlFor="section-status">Status</FieldLabel>
                    <Controller
                      control={form.control}
                      name="status"
                      render={({ field }) => (
                        <Select
                          value={field.value}
                          onValueChange={field.onChange}
                        >
                          <SelectTrigger id="section-status" className="w-full">
                            <SelectValue />
                          </SelectTrigger>
                          <SelectContent>
                            <SelectItem value="planned">Planned</SelectItem>
                            <SelectItem value="published">Published</SelectItem>
                            <SelectItem value="closed">Closed</SelectItem>
                            <SelectItem value="cancelled">Cancelled</SelectItem>
                          </SelectContent>
                        </Select>
                      )}
                    />
                  </Field>
                  <Button type="submit" disabled={mutation.isPending}>
                    {mutation.isPending ? "Saving section" : "Save section"}
                  </Button>
                </FieldGroup>
              </form>
            </CardContent>
          </Card>
        )}
      </AsyncBoundary>
    </WorkspacePage>
  )
}
