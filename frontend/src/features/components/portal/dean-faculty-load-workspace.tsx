"use client"

import { useMemo, useState } from "react"

import { useAuth } from "@/features/auth/use-auth"
import { AcademicTermSelector } from "@/features/components/portal/academic-term-selector"
import { AsyncBoundary } from "@/features/components/portal/async-boundary"
import {
  FacultyLoadAssignmentsDialog,
  type FacultyLoadAssignmentsTarget,
} from "@/features/components/portal/faculty-load-assignments-dialog"
import {
  FacultyLoadOverrideDialog,
  type FacultyLoadOverrideTarget,
} from "@/features/components/portal/faculty-load-override-dialog"
import {
  SectionProfessorDialog,
  type SectionProfessorTarget,
} from "@/features/components/portal/section-professor-dialog"
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
import { useAcademicTermSelection } from "@/features/hooks/use-academic-term-selection"
import {
  useAssignSectionProfessorMutation,
  useClearFacultyLoadOverrideMutation,
  useFacultyLoadReportQuery,
  useSaveFacultyLoadOverrideMutation,
} from "@/features/hooks/use-faculty-load"
import {
  limitSourceLabel,
  loadStatus,
  loadStatusLabel,
  loadSummary,
  type LoadStatus,
} from "@/features/lib/faculty-load-presentation"
import type {
  FacultyLoadMember,
  IdleFacultyMember,
} from "@/features/schemas/schedule-generation-schema"

interface Row {
  professorId: number
  name: string
  employmentTypeLabel: string | null
  totalUnits: number
  maxUnits: number | null
  limitSource: FacultyLoadMember["limit_source"]
  override: FacultyLoadMember["override"]
  assignments: FacultyLoadMember["assignments"]
}

const FILTERS: readonly { value: "all" | LoadStatus; label: string }[] = [
  { value: "all", label: "All professors" },
  { value: "over", label: "Over limit" },
  { value: "at", label: "At limit" },
  { value: "within", label: "Within limit" },
  { value: "none", label: "No limit set" },
]

const STATUS_VARIANT: Record<
  LoadStatus,
  "destructive" | "warning" | "secondary" | "outline"
> = {
  over: "destructive",
  at: "warning",
  within: "secondary",
  none: "outline",
}

function fromLoaded(member: FacultyLoadMember): Row {
  return {
    professorId: member.professor_id,
    name: member.professor_name ?? `Faculty #${member.professor_id}`,
    employmentTypeLabel: member.employment_type_label,
    totalUnits: member.total_units,
    maxUnits: member.max_units,
    limitSource: member.limit_source,
    override: member.override,
    assignments: member.assignments,
  }
}

function fromIdle(member: IdleFacultyMember): Row {
  return {
    professorId: member.professor_id,
    name: member.professor_name,
    employmentTypeLabel: member.employment_type_label,
    totalUnits: 0,
    maxUnits: member.max_units,
    limitSource: member.limit_source,
    override: member.override,
    assignments: [],
  }
}

/**
 * The Dean's view of teaching load in their own college (stakeholder Doc 14,
 * ADR 0033): every professor with their load against the maximum that applies,
 * where it comes from, and the tools to raise or lower one professor's limit
 * or change who teaches a section. Read-only for a past term.
 */
export function DeanFacultyLoadWorkspace() {
  const { session } = useAuth()
  const authorized = session?.role === "dean"
  const termSelection = useAcademicTermSelection()
  const { term, termId, sortedTerms, isCurrentTerm, setSelectedTermId } =
    termSelection
  const reportQuery = useFacultyLoadReportQuery(authorized ? termId : 0)
  const saveOverride = useSaveFacultyLoadOverrideMutation(termId)
  const clearOverride = useClearFacultyLoadOverrideMutation(termId)
  const assignProfessor = useAssignSectionProfessorMutation()
  const [filter, setFilter] = useState<"all" | LoadStatus>("all")
  const [overrideTarget, setOverrideTarget] =
    useState<FacultyLoadOverrideTarget | null>(null)
  const [sectionTarget, setSectionTarget] =
    useState<SectionProfessorTarget | null>(null)
  const [assignmentsTarget, setAssignmentsTarget] =
    useState<FacultyLoadAssignmentsTarget | null>(null)

  const report = reportQuery.data
  const rows = useMemo<Row[]>(
    () =>
      [
        ...(report?.faculty ?? []).map(fromLoaded),
        ...(report?.idle_faculty ?? []).map(fromIdle),
      ].sort((a, b) => a.name.localeCompare(b.name)),
    [report],
  )
  const professorOptions = useMemo(
    () =>
      rows.map((row) => ({
        id: row.professorId,
        name: row.name,
        detail: row.employmentTypeLabel ?? undefined,
      })),
    [rows],
  )
  const counts = useMemo(() => {
    const byStatus: Record<LoadStatus, number> = {
      over: 0,
      at: 0,
      within: 0,
      none: 0,
    }
    for (const row of rows) byStatus[loadStatus(row.totalUnits, row.maxUnits)]++
    return byStatus
  }, [rows])
  const visibleRows = rows.filter(
    (row) =>
      filter === "all" || loadStatus(row.totalUnits, row.maxUnits) === filter,
  )

  const query = {
    isPending: termSelection.termsQuery.isPending || reportQuery.isPending,
    isError: termSelection.termsQuery.isError || reportQuery.isError,
    error: termSelection.termsQuery.error ?? reportQuery.error,
    data: true as const,
    refetch: () => {
      void termSelection.termsQuery.refetch()
      void reportQuery.refetch()
    },
  }

  return (
    <WorkspacePage
      title="Faculty load"
      description="See how many units each professor of your college carries against their maximum, raise or lower one professor's limit when you must, and change who teaches a section."
      unauthorized={!authorized}
      lastUpdated={reportQuery.dataUpdatedAt}
    >
      <AsyncBoundary query={query} loadingLabel="Loading faculty load…">
        {() => (
          <div className="grid gap-5">
            <AcademicTermSelector
              sortedTerms={sortedTerms}
              term={term}
              isCurrentTerm={isCurrentTerm}
              onSelectTerm={setSelectedTermId}
            />

            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
              <Metric label="Professors" value={rows.length} />
              <Metric label="Over limit" value={counts.over} />
              <Metric label="No limit set" value={counts.none} />
              <Metric
                label="Sections without a professor"
                value={report?.unassigned_count ?? 0}
              />
            </div>

            <Card>
              <CardHeader>
                <CardTitle level={2}>Professors</CardTitle>
                <CardDescription>
                  A limit comes from the professor&apos;s own max load first,
                  then their full-time or part-time limit, then the college
                  default. The Program Head sets the limits; you can set a
                  professor&apos;s own.
                </CardDescription>
              </CardHeader>
              <CardContent className="grid gap-4">
                <div
                  role="group"
                  aria-label="Filter by load status"
                  className="flex flex-wrap gap-2"
                >
                  {FILTERS.map((item) => (
                    <Button
                      key={item.value}
                      type="button"
                      size="sm"
                      variant={filter === item.value ? "default" : "outline"}
                      aria-pressed={filter === item.value}
                      onClick={() => setFilter(item.value)}
                    >
                      {item.label}
                      {item.value !== "all" && ` (${counts[item.value]})`}
                    </Button>
                  ))}
                </div>

                {visibleRows.length === 0 ? (
                  <p className="rounded-lg border border-dashed p-5 text-sm text-muted-foreground">
                    {rows.length === 0
                      ? "No professors are recorded for your college."
                      : "No professor matches this filter."}
                  </p>
                ) : (
                  <ul className="grid gap-3">
                    {visibleRows.map((row) => {
                      const status = loadStatus(row.totalUnits, row.maxUnits)

                      return (
                        <li
                          key={row.professorId}
                          className="rounded-lg border p-4"
                        >
                          <div className="flex flex-wrap items-center justify-between gap-2">
                            <div>
                              <button
                                type="button"
                                className="font-medium underline-offset-2 hover:underline"
                                onClick={() =>
                                  setAssignmentsTarget({
                                    professorName: row.name,
                                    totalUnits: row.totalUnits,
                                    assignments: row.assignments,
                                  })
                                }
                              >
                                {row.name}
                              </button>
                              <p className="text-sm text-muted-foreground">
                                {row.employmentTypeLabel ??
                                  "Employment type not recorded"}
                              </p>
                            </div>
                            <div className="flex flex-wrap items-center gap-2">
                              <Badge variant={STATUS_VARIANT[status]}>
                                {loadStatusLabel[status]}
                              </Badge>
                              <Badge variant="outline">
                                {loadSummary(row.totalUnits, row.maxUnits)}
                              </Badge>
                              <Badge variant="outline">
                                {limitSourceLabel(
                                  row.limitSource,
                                  row.employmentTypeLabel,
                                )}
                              </Badge>
                            </div>
                          </div>

                          {row.override && (
                            <p className="mt-2 text-sm text-muted-foreground">
                              <span className="font-medium text-foreground">
                                Own max load:
                              </span>{" "}
                              {row.override.reason}
                            </p>
                          )}

                          {row.assignments.length > 0 && (
                            <details className="mt-3">
                              <summary className="cursor-pointer text-sm text-muted-foreground">
                                {row.assignments.length}{" "}
                                {row.assignments.length === 1
                                  ? "section"
                                  : "sections"}
                              </summary>
                              <ul className="mt-2 grid gap-2">
                                {row.assignments.map((assignment) => (
                                  <li
                                    key={assignment.section_id}
                                    className="flex flex-wrap items-center justify-between gap-2 rounded-md bg-muted/40 p-2 text-sm"
                                  >
                                    <span>
                                      <span className="font-medium">
                                        {assignment.subject_code}
                                      </span>{" "}
                                      · Section {assignment.section_code} ·{" "}
                                      {assignment.units} units
                                      {assignment.schedule_days
                                        ? ` · ${assignment.schedule_days}`
                                        : ""}
                                    </span>
                                    {isCurrentTerm && (
                                      <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                          setSectionTarget({
                                            sectionId: assignment.section_id,
                                            sectionLabel: `${assignment.subject_code} · Section ${assignment.section_code}`,
                                            currentProfessorId: row.professorId,
                                            currentProfessorName: row.name,
                                          })
                                        }
                                      >
                                        Change professor
                                      </Button>
                                    )}
                                  </li>
                                ))}
                              </ul>
                            </details>
                          )}

                          {isCurrentTerm && (
                            <div className="mt-3 flex flex-wrap gap-2">
                              <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                  setOverrideTarget({
                                    professorId: row.professorId,
                                    professorName: row.name,
                                    currentMaxUnits:
                                      row.override?.max_units ?? row.maxUnits,
                                    currentReason: row.override?.reason ?? null,
                                  })
                                }
                              >
                                Set max load
                              </Button>
                              {row.override && (
                                <Button
                                  type="button"
                                  size="sm"
                                  variant="ghost"
                                  disabled={clearOverride.isPending}
                                  onClick={() =>
                                    clearOverride.mutate(row.professorId)
                                  }
                                >
                                  Remove own max load
                                </Button>
                              )}
                            </div>
                          )}
                        </li>
                      )
                    })}
                  </ul>
                )}
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle level={2}>Sections without a professor</CardTitle>
              </CardHeader>
              <CardContent>
                {(report?.unassigned.length ?? 0) === 0 ? (
                  <p className="rounded-lg border border-dashed p-5 text-sm text-muted-foreground">
                    Every section has a professor.
                  </p>
                ) : (
                  <ul className="grid gap-2">
                    {report?.unassigned.map((section) => (
                      <li
                        key={section.section_id}
                        className="flex flex-wrap items-center justify-between gap-2 rounded-md border p-3 text-sm"
                      >
                        <span>
                          <span className="font-medium">
                            {section.subject_code}
                          </span>{" "}
                          · Section {section.section_code} · {section.units}{" "}
                          units
                          {section.schedule_days
                            ? ` · ${section.schedule_days}`
                            : ""}
                        </span>
                        {isCurrentTerm && (
                          <Button
                            type="button"
                            size="sm"
                            onClick={() =>
                              setSectionTarget({
                                sectionId: section.section_id,
                                sectionLabel: `${section.subject_code} · Section ${section.section_code}`,
                                currentProfessorId: null,
                                currentProfessorName: null,
                              })
                            }
                          >
                            Assign professor
                          </Button>
                        )}
                      </li>
                    ))}
                  </ul>
                )}
              </CardContent>
            </Card>
          </div>
        )}
      </AsyncBoundary>

      <FacultyLoadAssignmentsDialog
        target={assignmentsTarget}
        onOpenChange={(open) => {
          if (!open) setAssignmentsTarget(null)
        }}
      />

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

      <SectionProfessorDialog
        target={sectionTarget}
        professors={professorOptions}
        pending={assignProfessor.isPending}
        error={assignProfessor.error}
        onOpenChange={(open) => {
          if (!open) {
            setSectionTarget(null)
            assignProfessor.reset()
          }
        }}
        onSubmit={({ professorId, reason }) => {
          if (!sectionTarget) return
          assignProfessor.mutate(
            {
              sectionId: sectionTarget.sectionId,
              professorId,
              reason: reason || undefined,
            },
            { onSuccess: () => setSectionTarget(null) },
          )
        }}
      />
    </WorkspacePage>
  )
}

function Metric({ label, value }: { label: string; value: number }) {
  return (
    <Card>
      <CardContent className="grid gap-1 p-4">
        <span className="text-xs font-semibold tracking-wide text-primary uppercase">
          {label}
        </span>
        <strong className="font-heading text-2xl">{value}</strong>
      </CardContent>
    </Card>
  )
}
