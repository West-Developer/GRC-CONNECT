"use client"

import { zodResolver } from "@hookform/resolvers/zod"
import { useMutation, useQueryClient } from "@tanstack/react-query"
import { useCallback, useEffect, useRef, useState } from "react"
import { useForm, useWatch } from "react-hook-form"

import { useAuth } from "@/features/auth/use-auth"
import { AsyncBoundary } from "@/features/components/portal/async-boundary"
import { WorkspacePage } from "@/features/components/portal/workspace-page"
import { Alert, AlertDescription } from "@/features/components/ui/alert"
import {
  AlertDialog,
  AlertDialogAction,
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
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/features/components/ui/select"
import {
  Tabs,
  TabsContent,
  TabsList,
  TabsTrigger,
} from "@/features/components/ui/tabs"
import {
  useCurriculaQuery,
  curriculaQueryKey,
} from "@/features/hooks/use-curricula"
import {
  useProgramsQuery,
  useSubjectsQuery,
} from "@/features/hooks/use-reference-data"
import {
  useAddCurriculumSubjectPlacementMutation,
  useCurrentCurriculumSubjectsQuery,
} from "@/features/hooks/use-curriculum-authoring"
import { applyApiFieldErrors } from "@/features/lib/api-form-errors"
import {
  curriculumReplacementSchema,
  storeCurriculumInputSchema,
  type CurriculumSubjectInput,
  type StoreCurriculumInput,
} from "@/features/schemas/curriculum-schema"
import type { Curriculum } from "@/features/schemas/reference-data-schema"
import {
  createCurriculum,
  replaceCurriculum,
  toCurriculumReplacement,
  transitionCurriculum,
  updateCurriculumMaxUnits,
} from "@/features/services/curriculum-service"
import { CurriculumView } from "@/features/components/portal/curriculum-view"
import { CurriculumCreationWizard } from "@/features/components/portal/curriculum-creation-wizard"
import { CurriculumSubjectSpreadsheet } from "@/features/components/portal/curriculum-subject-spreadsheet"
import { CurriculumSubjectRowDialog } from "@/features/components/portal/curriculum-subject-row-dialog"
import { CurriculumMigrationPanel } from "@/features/components/portal/curriculum-migration-panel"

type CurriculumWorkspaceValues = StoreCurriculumInput

const fresh: CurriculumWorkspaceValues = {
  program_id: 0,
  equivalency_source_curriculum_id: null,
  name: "",
  subjects: [],
}

function valuesFromCurriculum(
  curriculum: Curriculum,
): CurriculumWorkspaceValues {
  return {
    program_id: curriculum.program_id,
    equivalency_source_curriculum_id:
      curriculum.equivalency_source_curriculum_id ?? null,
    name: curriculum.name,
    subjects: curriculum.subjects.map(
      ({ subject_id, year_level, semester, is_required, prerequisites }) => ({
        subject_id,
        year_level,
        semester,
        is_required,
        prerequisites: prerequisites.map(
          ({ prerequisite_subject_id, minimum_grade }) => ({
            prerequisite_subject_id,
            minimum_grade,
          }),
        ),
      }),
    ),
  }
}

const years = [1, 2, 3, 4] as const
/**
 * Quiet period before a placement edit is pushed to the server. Long enough
 * that a chair walking a row's cells produces one write instead of four.
 */
const autosaveDelayMs = 800

function yearLabel(year: number) {
  return `${year}${year === 1 ? "st" : year === 2 ? "nd" : year === 3 ? "rd" : "th"} Year`
}

function isInProgress(curriculum: Curriculum) {
  return (
    curriculum.status === "draft" ||
    curriculum.status === "pending_dean_review" ||
    curriculum.status === "pending_executive_review"
  )
}

function latestInProgressCurriculum(curricula: readonly Curriculum[]) {
  return curricula
    .filter(isInProgress)
    .slice()
    .sort(
      (left, right) =>
        right.effective_school_year.localeCompare(left.effective_school_year) ||
        right.id - left.id,
    )
    .slice(0, 1)
}

export function CurriculumWorkspace() {
  const { session } = useAuth()
  const queryClient = useQueryClient()
  const programsQuery = useProgramsQuery()
  const subjectsQuery = useSubjectsQuery()
  const curriculaQuery = useCurriculaQuery()
  const [selectedId, setSelectedIdState] = useState(0)
  const [isEditing, setIsEditing] = useState(false)
  const [rowDialogOpen, setRowDialogOpen] = useState(false)
  // Replacement payloads deliberately contain placement IDs only. Keep the
  // details returned by the authoritative curriculum response beside the form
  // values so a newly-created subject can render immediately while an
  // invalidate-triggered list refresh is still in flight.
  const [placementDetails, setPlacementDetails] = useState<
    Curriculum["subjects"]
  >([])
  // Manage owns one current workflow only. Historical and final-approved
  // curricula stay in View; keeping just the latest Draft/pending item here
  // also prevents accidental edits to an older saved copy.
  const inProgressCurricula = latestInProgressCurriculum(
    curriculaQuery.data ?? [],
  )
  const selectedCurriculum = inProgressCurricula.find(
    (item) => item.id === selectedId,
  )
  const candidateSubjectsQuery = useCurrentCurriculumSubjectsQuery(
    selectedCurriculum?.program_id ?? null,
  )
  const placementMutation = useAddCurriculumSubjectPlacementMutation()
  // Mirrored in a ref so an in-flight save can tell, on resolution, whether the
  // chair has since switched to a different curriculum.
  const selectedIdRef = useRef(0)
  const setSelectedId = useCallback((id: number) => {
    selectedIdRef.current = id
    setSelectedIdState(id)
    setRowDialogOpen(false)
  }, [])
  const [activeYear, setActiveYear] = useState("1")
  const [curriculumPickerOpen, setCurriculumPickerOpen] = useState(false)
  const [discardTarget, setDiscardTarget] = useState<number | null>(null)
  const [requestError, setRequestError] = useState("")
  const [submitDialogOpen, setSubmitDialogOpen] = useState(false)
  const form = useForm<CurriculumWorkspaceValues>({
    resolver: zodResolver(storeCurriculumInputSchema),
    defaultValues: fresh,
  })
  const formSubjects = useWatch({ control: form.control, name: "subjects" })
  const watchedValues = useWatch({ control: form.control })
  const userId = session?.userId ?? null
  const invalidate = () =>
    queryClient.invalidateQueries({
      queryKey: curriculaQueryKey(userId),
      exact: true,
    })
  const mutation = useMutation({
    mutationFn: (input: CurriculumWorkspaceValues) =>
      replaceCurriculum(selectedId, toCurriculumReplacement(input)),
    onSuccess: invalidate,
  })
  const createMutation = useMutation({ mutationFn: createCurriculum })
  const { mutateAsync } = mutation
  const isPending =
    mutation.isPending ||
    createMutation.isPending ||
    placementMutation.isPending
  // The placement graph as last loaded or last written. The autosave effect
  // compares against it so that reloading a curriculum — or editing only the
  // header fields the discard dialog still guards — never triggers a write.
  const [persistedPlacementSignature, setPersistedPlacementSignature] =
    useState(JSON.stringify(fresh.subjects))
  const applyValues = useCallback(
    (values: CurriculumWorkspaceValues, details?: Curriculum["subjects"]) => {
      setPersistedPlacementSignature(JSON.stringify(values.subjects))
      if (details) setPlacementDetails(details)
      form.reset(values)
    },
    [form],
  )
  const addSubjectRow = async (
    input: Parameters<typeof placementMutation.mutateAsync>[0]["input"],
  ) => {
    if (!selectedCurriculum) return

    const updated = await placementMutation.mutateAsync({
      curriculumId: selectedCurriculum.id,
      input,
    })
    queryClient.setQueryData<Curriculum[]>(curriculaQueryKey(userId), (old) =>
      (old ?? []).map((item) => (item.id === updated.id ? updated : item)),
    )
    applyValues(valuesFromCurriculum(updated), updated.subjects)
  }
  const transitionMutation = useMutation({
    mutationFn: (action: "submit") =>
      transitionCurriculum(selectedId, { action }),
    onSuccess: (updated) => {
      // The transition response already carries the authoritative post-write
      // status, so write it straight into the cache instead of invalidating —
      // an invalidate-triggered refetch could otherwise race this update and
      // briefly (or, against a slow read replica, not-so-briefly) show the
      // pre-submit status again.
      queryClient.setQueryData<Curriculum[]>(curriculaQueryKey(userId), (old) =>
        (old ?? []).map((item) => (item.id === updated.id ? updated : item)),
      )
      applyValues(valuesFromCurriculum(updated), updated.subjects)
      setIsEditing(false)
      setSubmitDialogOpen(false)
    },
    onError: () => {
      setRequestError(
        "Curriculum could not be submitted for Dean review. Save the latest changes and try again.",
      )
    },
  })
  const [customMaxUnits, setCustomMaxUnits] = useState<string>("")
  const [maxUnitsFeedback, setMaxUnitsFeedback] = useState<string>("")

  const selectedCurriculumId = selectedCurriculum?.id
  const maxUnitsValue = selectedCurriculum?.max_units

  // Sync the max-units input to the selected curriculum — adjusted during
  // render (React's "adjusting state when a prop changes"), not in an effect.
  const maxUnitsSource = `${selectedCurriculumId ?? ""}|${maxUnitsValue ?? ""}`
  const [syncedMaxUnitsSource, setSyncedMaxUnitsSource] = useState<
    string | null
  >(null)
  if (syncedMaxUnitsSource !== maxUnitsSource) {
    setSyncedMaxUnitsSource(maxUnitsSource)
    if (selectedCurriculumId) {
      setCustomMaxUnits(maxUnitsValue != null ? String(maxUnitsValue) : "")
      setMaxUnitsFeedback("")
    }
  }

  const maxUnitsMutation = useMutation({
    mutationFn: (val: number | null) =>
      updateCurriculumMaxUnits(selectedId, val),
    onSuccess: (updated) => {
      invalidate()
      queryClient.setQueryData<Curriculum[]>(curriculaQueryKey(userId), (old) =>
        (old ?? []).map((item) => (item.id === updated.id ? updated : item)),
      )
      setMaxUnitsFeedback("Maximum unit limit saved successfully.")
    },
    onError: (err: unknown) => {
      setMaxUnitsFeedback(
        err instanceof Error ? err.message : "Failed to update maximum units.",
      )
    },
  })
  const save = useCallback(
    async (input: CurriculumWorkspaceValues) => {
      setRequestError("")
      // Both captured before the request so the response can be discarded if
      // the chair moved on while it was in flight. Resetting the form to this
      // stale snapshot would silently overwrite whatever they typed meanwhile
      // *and* clear `isDirty`, so even the discard dialog would stop warning.
      const requestedFor = selectedIdRef.current
      const valuesAtRequest = JSON.stringify(form.getValues())
      try {
        const saved = await mutateAsync(input)
        if (selectedIdRef.current !== requestedFor) return
        setSelectedId(saved.id)
        // Live edits made during the request stay put; the next autosave cycle
        // carries them, since they also moved the placement signature.
        if (JSON.stringify(form.getValues()) === valuesAtRequest)
          applyValues(input)
      } catch (error) {
        if (selectedIdRef.current !== requestedFor) return
        if (!applyApiFieldErrors(error, form.setError))
          setRequestError(
            "Curriculum could not be saved. Check the connection or conflicting changes, then retry.",
          )
      }
    },
    [applyValues, form, mutateAsync, setSelectedId],
  )
  const edit = (id: number) => {
    const curriculum = (curriculaQuery.data ?? []).find(
      (item) => item.id === id,
    )
    setSelectedId(id)
    setIsEditing(false)
    if (curriculum)
      applyValues(valuesFromCurriculum(curriculum), curriculum.subjects)
  }
  const isLocked = !isEditing || selectedCurriculum?.status !== "draft"
  const requestEdit = (id: number) => {
    if (id === selectedId) return setCurriculumPickerOpen(false)
    setCurriculumPickerOpen(false)
    if (form.formState.isDirty && isEditing) return setDiscardTarget(id)
    if (id === 0) {
      setSelectedId(0)
      setIsEditing(false)
      applyValues(fresh, [])
      return
    }
    edit(id)
  }
  const createFromWizard = async (input: StoreCurriculumInput) => {
    setRequestError("")
    try {
      const created = await createMutation.mutateAsync(input)
      queryClient.setQueryData<Curriculum[]>(
        curriculaQueryKey(userId),
        (old) => [
          ...(old ?? []).filter((item) => item.id !== created.id),
          created,
        ],
      )
      setSelectedId(created.id)
      setIsEditing(false)
      applyValues(valuesFromCurriculum(created), created.subjects)
    } catch (error) {
      setRequestError(
        "Curriculum could not be created. Check the connection or conflicting changes, then retry.",
      )
      throw error
    }
  }
  const setPlacements = (placements: CurriculumSubjectInput[]) =>
    form.setValue("subjects", placements, {
      shouldDirty: true,
      shouldValidate: true,
    })
  const placementSignature = JSON.stringify(formSubjects)
  const isDirty = form.formState.isDirty
  const hasUnsavedPlacementChanges =
    persistedPlacementSignature !== placementSignature
  // A brand-new curriculum has nothing to PATCH and no complete payload to
  // POST until its header fields are filled in, so autosave stays quiet until
  // the whole form is a valid create/replace request.
  const autosaveReady =
    selectedId > 0
      ? curriculumReplacementSchema.safeParse({
          name: watchedValues.name,
          subjects: watchedValues.subjects,
        }).success
      : storeCurriculumInputSchema.safeParse(watchedValues).success
  useEffect(() => {
    if (
      !selectedCurriculum ||
      (!isDirty && !hasUnsavedPlacementChanges) ||
      isPending ||
      isLocked
    )
      return
    if (autosaveReady && !hasUnsavedPlacementChanges) return
    const timer = setTimeout(() => {
      // Nothing can be written until the whole form is a valid request. Run
      // validation instead of writing, so the fields blocking the autosave are
      // actually highlighted rather than silently swallowing every edit — this
      // screen has no submit button to run it for us.
      if (!autosaveReady) return void form.trigger()
      setPersistedPlacementSignature(placementSignature)
      void save(form.getValues())
    }, autosaveDelayMs)
    return () => clearTimeout(timer)
  }, [
    autosaveReady,
    form,
    isDirty,
    isLocked,
    isPending,
    hasUnsavedPlacementChanges,
    placementSignature,
    save,
    selectedCurriculum,
  ])

  const catalog = [...(subjectsQuery.data ?? [])]
  for (const subject of placementDetails) {
    if (
      subject.units !== undefined &&
      !catalog.some((candidate) => candidate.id === subject.subject_id)
    )
      catalog.push({
        type: "subject",
        id: subject.subject_id,
        code: subject.code,
        title: subject.title,
        units: subject.units,
        status: "active",
        status_label: "Active",
        is_completion_only: false,
      })
  }
  const saveState = !isEditing
    ? "Read-only preview"
    : isPending
      ? "Saving…"
      : isDirty && !autosaveReady
        ? "Not saved — fix the highlighted fields"
        : mutation.isSuccess
          ? "Saved"
          : mutation.isError
            ? "Save failed — edit again to retry"
            : "Edits save automatically"
  const referenceDataQuery = {
    isPending:
      programsQuery.isPending ||
      subjectsQuery.isPending ||
      curriculaQuery.isPending,
    isError:
      programsQuery.isError || subjectsQuery.isError || curriculaQuery.isError,
    error: programsQuery.error ?? subjectsQuery.error ?? curriculaQuery.error,
    data: true as const,
    refetch: () => {
      void programsQuery.refetch()
      void subjectsQuery.refetch()
      void curriculaQuery.refetch()
    },
  }
  return (
    <WorkspacePage
      title="Curriculum editor"
      description="Maintain complete program curriculum mappings and prerequisite rules."
      lastUpdated={curriculaQuery.dataUpdatedAt}
    >
      {requestError && (
        <Alert variant="destructive">
          <AlertDescription>{requestError}</AlertDescription>
        </Alert>
      )}
      <AsyncBoundary
        query={referenceDataQuery}
        loadingLabel="Loading curriculum data…"
      >
        {() => (
          <Tabs defaultValue="manage" className="grid gap-4">
            <TabsList aria-label="Curriculum editor mode">
              <TabsTrigger value="manage">Manage</TabsTrigger>
              <TabsTrigger value="view">View</TabsTrigger>
            </TabsList>
            <TabsContent value="manage" className="grid gap-4">
              {selectedCurriculum ? (
                <>
                  <div className="flex flex-wrap items-start justify-between gap-3 rounded-xl border bg-muted/30 p-4">
                    <div className="grid gap-1">
                      <p className="text-sm text-muted-foreground">
                        {(programsQuery.data ?? []).find(
                          (program) =>
                            program.id === selectedCurriculum.program_id,
                        )?.code ?? "Program"}
                      </p>
                      <div className="flex flex-wrap items-center gap-2">
                        <h2 className="font-heading text-lg font-medium">
                          {selectedCurriculum.name}
                        </h2>
                        <Badge
                          variant={
                            selectedCurriculum.status === "draft"
                              ? "outline"
                              : "secondary"
                          }
                        >
                          {selectedCurriculum.status_label}
                        </Badge>
                      </div>
                    </div>
                    <div className="flex items-center gap-2">
                      <p className="text-sm text-muted-foreground">
                        {isEditing ? "Editing Draft" : "Read-only preview"}
                      </p>
                      <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => setCurriculumPickerOpen(true)}
                      >
                        Change curriculum
                      </Button>
                    </div>
                  </div>
                  <FieldGroup>
                    <Field data-invalid={Boolean(form.formState.errors.name)}>
                      <FieldLabel htmlFor="curriculum-name">
                        Curriculum name
                      </FieldLabel>
                      <Input
                        id="curriculum-name"
                        aria-invalid={Boolean(form.formState.errors.name)}
                        disabled={isLocked}
                        {...form.register("name")}
                      />
                      <FieldError>
                        {form.formState.errors.name?.message}
                      </FieldError>
                    </Field>
                  </FieldGroup>
                  <div className="rounded-xl border bg-card p-4 shadow-sm">
                    <div className="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between border-b pb-3">
                      <div>
                        <h3 className="text-sm font-semibold text-foreground">
                          Maximum Student Unit Limit
                        </h3>
                        <p className="text-xs text-muted-foreground">
                          Configures the maximum units for students enrolled under this curriculum.
                          Defaults to the heaviest semester load prescribed across 1st to 4th year ({selectedCurriculum.default_max_units ?? 30.0} units).
                        </p>
                      </div>
                      <Badge variant="secondary" className="w-fit font-mono text-xs">
                        Effective: {selectedCurriculum.effective_max_units ?? selectedCurriculum.default_max_units ?? 30.0} units
                      </Badge>
                    </div>

                    {selectedCurriculum.year_level_max_units && (
                      <div className="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4">
                        {([1, 2, 3, 4] as const).map((yr) => {
                          const yrRecord = selectedCurriculum.year_level_max_units as Record<string, number> | undefined
                          const peak = yrRecord?.[String(yr)] ?? 0
                          return (
                            <div
                              key={yr}
                              className="rounded-lg border bg-muted/30 px-3 py-2 text-xs"
                            >
                              <span className="text-muted-foreground">
                                {yearLabel(yr)} Peak:
                              </span>{" "}
                              <span className="font-semibold text-foreground">
                                {Number(peak).toFixed(1)} units
                              </span>
                            </div>
                          )
                        })}
                      </div>
                    )}

                    <div className="mt-3 flex flex-wrap items-center gap-3">
                      <div className="flex items-center gap-2">
                        <label
                          htmlFor="curriculum-max-units"
                          className="text-xs font-medium text-muted-foreground"
                        >
                          Custom Maximum:
                        </label>
                        <Input
                          id="curriculum-max-units"
                          type="number"
                          step="0.5"
                          min="1"
                          max="60"
                          className="h-8 w-28 text-sm"
                          placeholder={String(selectedCurriculum.default_max_units ?? 30.0)}
                          value={customMaxUnits}
                          onChange={(e) => setCustomMaxUnits(e.target.value)}
                        />
                      </div>
                      <Button
                        type="button"
                        size="sm"
                        disabled={maxUnitsMutation.isPending}
                        onClick={async () => {
                          const parsed =
                            customMaxUnits.trim() === ""
                              ? null
                              : Number(customMaxUnits)
                          await maxUnitsMutation.mutateAsync(parsed)
                        }}
                      >
                        {maxUnitsMutation.isPending ? "Saving…" : "Save Limit"}
                      </Button>
                      {selectedCurriculum.max_units != null && (
                        <Button
                          type="button"
                          variant="outline"
                          size="sm"
                          disabled={maxUnitsMutation.isPending}
                          onClick={async () => {
                            setCustomMaxUnits("")
                            await maxUnitsMutation.mutateAsync(null)
                          }}
                        >
                          Reset to Default ({selectedCurriculum.default_max_units ?? 30.0})
                        </Button>
                      )}
                      {maxUnitsFeedback && (
                        <span className="text-xs text-muted-foreground">
                          {maxUnitsFeedback}
                        </span>
                      )}
                    </div>
                  </div>
                  {selectedCurriculum?.last_decision_reason &&
                    selectedCurriculum.status === "draft" && (
                      <Alert variant="destructive">
                        <AlertDescription>
                          Returned: {selectedCurriculum.last_decision_reason}
                        </AlertDescription>
                      </Alert>
                    )}
                  <section
                    aria-label="Curriculum subject placements"
                    className="grid gap-3"
                  >
                    <Tabs value={activeYear} onValueChange={setActiveYear}>
                      <div className="flex flex-wrap items-center justify-between gap-3">
                        <TabsList
                          aria-label="Curriculum year level"
                          className="w-full sm:w-fit"
                        >
                          {years.map((year) => (
                            <TabsTrigger key={year} value={String(year)}>
                              {yearLabel(year)}
                            </TabsTrigger>
                          ))}
                        </TabsList>
                        <div className="flex flex-wrap items-center gap-2">
                          {isEditing && (
                            <Button
                              type="button"
                              variant="outline"
                              size="sm"
                              disabled={isPending || !autosaveReady}
                              onClick={() => void save(form.getValues())}
                            >
                              Save changes
                            </Button>
                          )}
                          <span
                            aria-live="polite"
                            className="text-sm text-muted-foreground"
                          >
                            {saveState}
                          </span>
                        </div>
                      </div>
                      {years.map((year) => (
                        <TabsContent
                          key={year}
                          value={String(year)}
                          className="grid gap-3"
                        >
                          <CurriculumSubjectSpreadsheet
                            yearLevel={year}
                            subjects={formSubjects}
                            subjectCatalog={catalog}
                            prerequisiteSubjects={
                              candidateSubjectsQuery.data ?? []
                            }
                            isLocked={isLocked}
                            onChange={setPlacements}
                            onAddRow={() => setRowDialogOpen(true)}
                          />
                        </TabsContent>
                      ))}
                    </Tabs>
                    <FieldError>
                      {form.formState.errors.subjects?.message}
                    </FieldError>
                  </section>
                </>
              ) : session?.role === "program_chair" ? (
                <div className="grid gap-3">
                  <CurriculumCreationWizard
                    programs={programsQuery.data ?? []}
                    curricula={curriculaQuery.data ?? []}
                    college={session.college}
                    disabled={createMutation.isPending}
                    onProceed={createFromWizard}
                  />
                  {inProgressCurricula.length > 0 && (
                    <div className="flex justify-center">
                      <Button
                        type="button"
                        variant="outline"
                        onClick={() => setCurriculumPickerOpen(true)}
                      >
                        Open saved curriculum
                      </Button>
                    </div>
                  )}
                </div>
              ) : null}
              {selectedCurriculum?.status === "draft" && !isEditing && (
                <div className="flex justify-end">
                  <Button type="button" onClick={() => setIsEditing(true)}>
                    Edit curriculum
                  </Button>
                </div>
              )}
              {selectedCurriculum?.status === "draft" &&
                formSubjects.length > 0 && (
                  <div className="flex justify-end">
                    <Button
                      type="button"
                      onClick={() => setSubmitDialogOpen(true)}
                    >
                      Submit for Dean Review
                    </Button>
                  </div>
                )}
              <Dialog
                open={submitDialogOpen}
                onOpenChange={setSubmitDialogOpen}
              >
                <DialogContent className="max-h-[90vh] overflow-y-auto">
                  <DialogHeader>
                    <DialogTitle>Review before submitting</DialogTitle>
                    <DialogDescription>
                      Every subject below will be sent to the Dean for review.
                      You will not be able to edit this curriculum again until
                      it is approved or returned.
                    </DialogDescription>
                  </DialogHeader>
                  {programsQuery.data && (
                    <CurriculumView
                      preview
                      programs={programsQuery.data.filter(
                        (program) => program.id === watchedValues.program_id,
                      )}
                      // CurriculumView only ever renders curricula whose status
                      // is "active" — this preview stands in a draft about to
                      // be submitted, so its status is overridden purely for
                      // this read-only render; nothing here is persisted.
                      curricula={
                        selectedCurriculum
                          ? [
                              {
                                ...selectedCurriculum,
                                status: "active" as const,
                              },
                            ]
                          : []
                      }
                    />
                  )}
                  <DialogFooter>
                    <Button
                      type="button"
                      variant="outline"
                      onClick={() => setSubmitDialogOpen(false)}
                    >
                      Cancel
                    </Button>
                    <Button
                      type="button"
                      disabled={transitionMutation.isPending}
                      onClick={() => transitionMutation.mutate("submit")}
                    >
                      Confirm & Submit
                    </Button>
                  </DialogFooter>
                </DialogContent>
              </Dialog>
            </TabsContent>
            <TabsContent value="view">
              <CurriculumView
                programs={programsQuery.data ?? []}
                curricula={curriculaQuery.data ?? []}
              />
              {session?.role === "program_chair" && (
                <div className="mt-4">
                  <CurriculumMigrationPanel
                    curricula={curriculaQuery.data ?? []}
                  />
                </div>
              )}
            </TabsContent>
          </Tabs>
        )}
      </AsyncBoundary>
      <CurriculumSubjectRowDialog
        open={rowDialogOpen && !isLocked}
        onOpenChange={setRowDialogOpen}
        yearLevel={Number(activeYear)}
        candidateQuery={{
          data: candidateSubjectsQuery.data?.filter(
            (candidate) =>
              !formSubjects.some(
                (placement) => placement.subject_id === candidate.id,
              ),
          ),
          isPending: candidateSubjectsQuery.isPending,
          isError: candidateSubjectsQuery.isError,
          error: candidateSubjectsQuery.error,
          refetch: candidateSubjectsQuery.refetch,
        }}
        equivalencyCandidates={(() => {
          const source = (curriculaQuery.data ?? []).find(
            (curriculum) =>
              curriculum.id ===
              selectedCurriculum?.equivalency_source_curriculum_id,
          )
          return (source?.subjects ?? []).map((subject) => ({
            type: "subject" as const,
            id: subject.subject_id,
            code: subject.code,
            title: subject.title,
            units: subject.units ?? 0,
            status: "active" as const,
            status_label: "Active",
            is_completion_only: false,
          }))
        })()}
        isSubmitting={placementMutation.isPending}
        onSubmit={addSubjectRow}
      />
      <Dialog
        open={curriculumPickerOpen}
        onOpenChange={setCurriculumPickerOpen}
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Open saved curriculum</DialogTitle>
            <DialogDescription>
              Select a curriculum to review or edit its draft.
            </DialogDescription>
          </DialogHeader>
          <Field>
            <FieldLabel htmlFor="curriculum-select">Curriculum</FieldLabel>
            <Select
              value={String(selectedId)}
              onValueChange={(value) => requestEdit(Number(value))}
            >
              <SelectTrigger id="curriculum-select" className="w-full">
                <SelectValue placeholder="Select a curriculum" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="0">Select a curriculum</SelectItem>
                {inProgressCurricula.map((curriculum) => (
                  <SelectItem key={curriculum.id} value={String(curriculum.id)}>
                    {curriculum.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </Field>
        </DialogContent>
      </Dialog>
      <AlertDialog
        open={discardTarget !== null}
        onOpenChange={(open) => !open && setDiscardTarget(null)}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>
              Discard unsaved curriculum changes
            </AlertDialogTitle>
            <AlertDialogDescription>
              Continue without saving your current edits?
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Keep editing</AlertDialogCancel>
            <AlertDialogAction
              onClick={() => {
                if (discardTarget === 0) {
                  setSelectedId(0)
                  setIsEditing(false)
                  applyValues(fresh, [])
                } else if (typeof discardTarget === "number") {
                  edit(discardTarget)
                }
                setDiscardTarget(null)
              }}
            >
              Discard changes
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </WorkspacePage>
  )
}
