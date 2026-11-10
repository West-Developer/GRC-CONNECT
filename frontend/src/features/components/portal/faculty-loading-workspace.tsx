"use client"

import { SlidersHorizontal } from "lucide-react"
import { useMemo, useState } from "react"

import { AcademicTermSelector } from "@/features/components/portal/academic-term-selector"
import { AsyncBoundary } from "@/features/components/portal/async-boundary"
import {
  FacultyLoadOverrideDialog,
  type FacultyLoadOverrideTarget,
} from "@/features/components/portal/faculty-load-override-dialog"
import { WorkspacePage } from "@/features/components/portal/workspace-page"
import { Badge } from "@/features/components/ui/badge"
import { Button } from "@/features/components/ui/button"
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/features/components/ui/card"
import { Field, FieldGroup, FieldLabel } from "@/features/components/ui/field"
import { Input } from "@/features/components/ui/input"
import { SearchableCombobox } from "@/features/components/ui/searchable-combobox"
import { useFacultyDirectoryQuery } from "@/features/hooks/use-faculty-directory"
import { useAcademicTermSelection } from "@/features/hooks/use-academic-term-selection"
import {
  useClearFacultyLoadOverrideMutation,
  useFacultyLoadReportQuery,
  useSaveFacultyLoadLimitMutation,
  useSaveFacultyLoadOverrideMutation,
  useSaveFacultyLoadThresholdMutation,
} from "@/features/hooks/use-faculty-load"
import {
  limitSourceLabel,
  loadSummary,
} from "@/features/lib/faculty-load-presentation"
import type { FacultyEmploymentType } from "@/features/schemas/schedule-generation-schema"

export function FacultyLoadingWorkspace() {
  const termSelection = useAcademicTermSelection()
  const { term, termId, sortedTerms, isCurrentTerm, setSelectedTermId } =
    termSelection
  const facultyQuery = useFacultyDirectoryQuery()
  const reportQuery = useFacultyLoadReportQuery(termId)
  // One draft per maximum: a value typed but not saved yet, keyed by which
  // limit it is for. An untouched field shows what the server has.
  const [limitDrafts, setLimitDrafts] = useState<Record<string, string>>({})
  const [overrideTarget, setOverrideTarget] =
    useState<FacultyLoadOverrideTarget | null>(null)
  const [filter, setFilter] = useState({ subjectId: "", professorId: "" })
  const subjectOptions = useMemo(() => {
    const subjectsById = new Map<number, { code: string; title: string }>()
    for (const member of reportQuery.data?.faculty ?? []) {
      for (const assignment of member.assignments) {
        if (!subjectsById.has(assignment.subject_id)) {
          subjectsById.set(assignment.subject_id, {
            code: assignment.subject_code,
            title: assignment.subject_title,
          })
        }
      }
    }
    return [
      { value: "", label: "All subjects" },
      ...Array.from(subjectsById, ([id, subject]) => ({
        value: String(id),
        label: `${subject.code} — ${subject.title}`,
      })).sort((a, b) => a.label.localeCompare(b.label)),
    ]
  }, [reportQuery.data?.faculty])
  const professorOptions = useMemo(
    () => [
      { value: "", label: "All professors" },
      ...(facultyQuery.data ?? []).map((member) => ({
        value: String(member.id),
        label: member.name,
      })),
    ],
    [facultyQuery.data],
  )
  const visibleFaculty = useMemo(
    () =>
      (reportQuery.data?.faculty ?? []).filter(
        (member) =>
          (filter.professorId === "" ||
            String(member.professor_id) === filter.professorId) &&
          (filter.subjectId === "" ||
            member.assignments.some(
              (assignment) =>
                String(assignment.subject_id) === filter.subjectId,
            )),
      ),
    [reportQuery.data?.faculty, filter],
  )
  const saveThreshold = useSaveFacultyLoadThresholdMutation(termId)
  const saveLimit = useSaveFacultyLoadLimitMutation(termId)
  const saveOverride = useSaveFacultyLoadOverrideMutation(termId)
  const clearOverride = useClearFacultyLoadOverrideMutation(termId)
  const setDraft = (key: string, value: string) =>
    setLimitDrafts((drafts) => ({ ...drafts, [key]: value }))
  const clearDraft = (key: string) =>
    setLimitDrafts((drafts) =>
      Object.fromEntries(
        Object.entries(drafts).filter(([draftKey]) => draftKey !== key),
      ),
    )
  const limitFor = (type: FacultyEmploymentType) =>
    reportQuery.data?.limits?.find((limit) => limit.employment_type === type)
      ?.max_units ?? null
  const query = {
    isPending:
      termSelection.termsQuery.isPending ||
      facultyQuery.isPending ||
      reportQuery.isPending,
    isError:
      termSelection.termsQuery.isError ||
      facultyQuery.isError ||
      reportQuery.isError,
    error:
      termSelection.termsQuery.error ?? facultyQuery.error ?? reportQuery.error,
    data: true as const,
    refetch: () => {
      void termSelection.termsQuery.refetch()
      void facultyQuery.refetch()
      void reportQuery.refetch()
    },
  }

  return (
    <WorkspacePage
      title="Faculty Loading"
      description="Set the most units a professor may carry, review the load report, and raise or lower one professor's limit when you must."
      lastUpdated={reportQuery.dataUpdatedAt}
    >
      <AsyncBoundary query={query} loadingLabel="Loading faculty load data…">
        {() => (
          <div className="grid gap-5">
            <Card className="overflow-hidden border-primary/20 bg-gradient-to-br from-primary/10 via-card to-card">
              <CardHeader className="border-b bg-background/60">
                <div className="flex flex-wrap items-start justify-between gap-4">
                  <div className="grid gap-1">
                    <div className="flex items-center gap-2 text-primary">
                      <SlidersHorizontal className="size-4" />
                      <span className="text-xs font-semibold tracking-[0.15em] uppercase">
                        Planning control room
                      </span>
                    </div>
                    <CardTitle level={2}>Maximum teaching load</CardTitle>
                    <p className="text-sm text-muted-foreground">
                      Set the most units a professor may carry, separately for
                      full-time and part-time. Until a limit is set, nobody of
                      that type is flagged as overloaded. Assignment
                      recommendations remain editable.
                    </p>
                  </div>
                  <Badge variant="secondary">
                    {term
                      ? `${term.school_year} · ${term.semester}`
                      : "No term"}
                  </Badge>
                </div>
              </CardHeader>
              <CardContent className="grid gap-4 pt-5 sm:grid-cols-3">
                {(
                  [
                    ["full_time", "Full-time maximum units"],
                    ["part_time", "Part-time maximum units"],
                  ] as const
                ).map(([type, label]) => (
                  <div key={type} className="grid content-start gap-2">
                    <label
                      className="grid gap-2 text-sm font-medium"
                      htmlFor={`faculty-load-limit-${type}`}
                    >
                      {label}
                      <Input
                        id={`faculty-load-limit-${type}`}
                        type="number"
                        min="1"
                        value={limitDrafts[type] ?? limitFor(type) ?? ""}
                        onChange={(event) => setDraft(type, event.target.value)}
                        placeholder="Not set"
                      />
                    </label>
                    <Button
                      type="button"
                      size="sm"
                      aria-label={`Save ${label.toLowerCase()}`}
                      onClick={() =>
                        saveLimit.mutate(
                          {
                            employmentType: type,
                            maxUnits: Number(limitDrafts[type]),
                          },
                          { onSuccess: () => clearDraft(type) },
                        )
                      }
                      disabled={
                        !limitDrafts[type] ||
                        saveLimit.isPending ||
                        !isCurrentTerm
                      }
                    >
                      {saveLimit.isPending ? "Saving…" : "Save"}
                    </Button>
                  </div>
                ))}
                <div className="grid content-start gap-2">
                  <label
                    className="grid gap-2 text-sm font-medium"
                    htmlFor="faculty-load-threshold"
                  >
                    Everyone else (default)
                    <Input
                      id="faculty-load-threshold"
                      type="number"
                      min="1"
                      value={
                        limitDrafts.default ??
                        reportQuery.data?.threshold_units ??
                        ""
                      }
                      onChange={(event) =>
                        setDraft("default", event.target.value)
                      }
                      placeholder="Not set"
                    />
                  </label>
                  <Button
                    type="button"
                    size="sm"
                    aria-label="Save default maximum units"
                    onClick={() =>
                      saveThreshold.mutate(Number(limitDrafts.default), {
                        onSuccess: () => clearDraft("default"),
                      })
                    }
                    disabled={
                      !limitDrafts.default ||
                      saveThreshold.isPending ||
                      !isCurrentTerm
                    }
                  >
                    {saveThreshold.isPending ? "Saving…" : "Save"}
                  </Button>
                </div>
                <p className="text-sm text-muted-foreground sm:col-span-3">
                  A professor&apos;s own max load, set from the report below,
                  beats their type limit; the default applies only when neither
                  exists.
                </p>
                {(saveLimit.error instanceof Error ||
                  saveThreshold.error instanceof Error) && (
                  <p className="text-sm text-destructive sm:col-span-3">
                    {(saveLimit.error ?? saveThreshold.error)?.message}
                  </p>
                )}
              </CardContent>
            </Card>
            <AcademicTermSelector
              sortedTerms={sortedTerms}
              term={term}
              isCurrentTerm={isCurrentTerm}
              onSelectTerm={setSelectedTermId}
            />

            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
              <Metric
                label="Teaching assignments"
                value={reportQuery.data?.required_assignments ?? 0}
                detail="Generated section-subject rows"
              />
              <Metric
                label="Required units"
                value={reportQuery.data?.required_teaching_units ?? 0}
                detail="Across current draft"
              />
              <Metric
                label="Equivalent faculty loads"
                value={reportQuery.data?.equivalent_faculty_loads ?? "—"}
                detail="Uses the college default"
              />
              <Metric
                label="Flags to review"
                value={
                  (reportQuery.data?.unassigned_count ?? 0) +
                  (reportQuery.data?.overloaded_count ?? 0)
                }
                detail="Unassigned or overload"
              />
            </div>

            <Card>
              <CardHeader>
                <CardTitle level={2}>Filters</CardTitle>
                <CardDescription>
                  Narrows the Faculty Load Report below to a subject, a
                  professor, or both.
                </CardDescription>
              </CardHeader>
              <CardContent>
                <FieldGroup className="grid gap-3 md:grid-cols-2">
                  <Field>
                    <FieldLabel htmlFor="faculty-loading-subject-filter">
                      Subject
                    </FieldLabel>
                    <SearchableCombobox
                      id="faculty-loading-subject-filter"
                      label="Subject"
                      options={subjectOptions}
                      value={filter.subjectId}
                      onValueChange={(value) =>
                        setFilter({ ...filter, subjectId: value })
                      }
                      placeholder="Search code or title"
                      emptyMessage="No matching subject."
                    />
                  </Field>
                  <Field>
                    <FieldLabel htmlFor="faculty-loading-professor-filter">
                      Professor
                    </FieldLabel>
                    <SearchableCombobox
                      id="faculty-loading-professor-filter"
                      label="Professor"
                      options={professorOptions}
                      value={filter.professorId}
                      onValueChange={(value) =>
                        setFilter({ ...filter, professorId: value })
                      }
                      placeholder="Search name"
                      emptyMessage="No matching professor."
                    />
                  </Field>
                </FieldGroup>
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <div className="flex flex-wrap items-center justify-between gap-3">
                  <div>
                    <CardTitle level={2}>Faculty Load Report</CardTitle>
                    <p className="mt-1 text-sm text-muted-foreground">
                      Assignment rationale is retained even when the Program
                      Chair makes an override.
                    </p>
                  </div>
                  <div className="flex items-center gap-2">
                    <Badge variant="outline">
                      {visibleFaculty.length} professors
                    </Badge>
                  </div>
                </div>
              </CardHeader>
              <CardContent className="grid gap-3">
                {visibleFaculty.length ? (
                  visibleFaculty.map((member) => (
                    <div
                      key={member.professor_id}
                      className="rounded-lg border p-4"
                    >
                      <div className="flex flex-wrap items-center justify-between gap-2">
                        <div>
                          <p className="font-medium">
                            {member.professor_name ??
                              `Faculty #${member.professor_id}`}
                          </p>
                          <p className="text-sm text-muted-foreground">
                            Assigned subjects:{" "}
                            {member.assignments
                              .map((assignment) => assignment.subject_code)
                              .join(", ")}
                          </p>
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                          <Badge
                            variant={
                              member.overloaded ? "destructive" : "secondary"
                            }
                          >
                            {loadSummary(member.total_units, member.max_units)}
                          </Badge>
                          <Badge variant="outline">
                            {limitSourceLabel(
                              member.limit_source,
                              member.employment_type_label,
                            )}
                          </Badge>
                          <Badge variant="outline">
                            {member.assignments.length} assignments
                          </Badge>
                        </div>
                      </div>
                      {member.override && (
                        <p className="mt-2 text-sm text-muted-foreground">
                          <span className="font-medium text-foreground">
                            Own max load:
                          </span>{" "}
                          {member.override.reason}
                        </p>
                      )}
                      {isCurrentTerm && (
                        <div className="mt-3 flex flex-wrap gap-2">
                          <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={() =>
                              setOverrideTarget({
                                professorId: member.professor_id,
                                professorName:
                                  member.professor_name ??
                                  `Faculty #${member.professor_id}`,
                                currentMaxUnits:
                                  member.override?.max_units ??
                                  member.max_units,
                                currentReason: member.override?.reason ?? null,
                              })
                            }
                          >
                            Set max load
                          </Button>
                          {member.override && (
                            <Button
                              type="button"
                              size="sm"
                              variant="ghost"
                              disabled={clearOverride.isPending}
                              onClick={() =>
                                clearOverride.mutate(member.professor_id)
                              }
                            >
                              Remove own max load
                            </Button>
                          )}
                        </div>
                      )}
                      <div className="mt-3 flex flex-wrap gap-2">
                        {member.assignments
                          .flatMap((assignment) => assignment.rationale)
                          .filter(
                            (value, index, values) =>
                              values.indexOf(value) === index,
                          )
                          .map((reason) => (
                            <Badge key={reason} variant="outline">
                              {reason.replaceAll("_", " ")}
                            </Badge>
                          ))}
                      </div>
                    </div>
                  ))
                ) : reportQuery.data?.faculty.length ? (
                  <p className="rounded-lg border border-dashed p-5 text-sm text-muted-foreground">
                    No professor matches the current filters.
                  </p>
                ) : (
                  <p className="rounded-lg border border-dashed p-5 text-sm text-muted-foreground">
                    Generate a schedule to see the load report.
                  </p>
                )}
              </CardContent>
            </Card>
          </div>
        )}
      </AsyncBoundary>

      <FacultyLoadOverrideDialog
        target={overrideTarget}
        pending={saveOverride.isPending}
        error={saveOverride.error}
        onOpenChange={(open) => {
          if (!open) {
            setOverrideTarget(null)
            saveOverride.reset()
          }
        }}
        onSubmit={({ maxUnits, reason }) => {
          if (!overrideTarget) return
          saveOverride.mutate(
            { professorId: overrideTarget.professorId, maxUnits, reason },
            { onSuccess: () => setOverrideTarget(null) },
          )
        }}
      />
    </WorkspacePage>
  )
}

function Metric({
  label,
  value,
  detail,
}: {
  label: string
  value: string | number
  detail: string
}) {
  return (
    <Card>
      <CardContent className="grid gap-1 p-4">
        <span className="text-xs font-semibold tracking-wide text-primary uppercase">
          {label}
        </span>
        <strong className="font-heading text-2xl">{value}</strong>
        <span className="text-xs text-muted-foreground">{detail}</span>
      </CardContent>
    </Card>
  )
}
