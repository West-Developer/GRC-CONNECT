"use client"

import { motion, useReducedMotion } from "motion/react"
import { BrainCircuit, ChartSpline, Gauge, UsersRound } from "lucide-react"
import Link from "next/link"
import type { ReactNode } from "react"

import { GroupedWarningsList } from "@/features/components/portal/grouped-warnings-list"
import { Badge } from "@/features/components/ui/badge"
import { Button } from "@/features/components/ui/button"
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from "@/features/components/ui/collapsible"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/features/components/ui/dialog"
import { buildSectionGenerationRationale } from "@/features/lib/section-generation-rationale"
import { formatYearLevel } from "@/features/lib/format-year-level"
import type { Section, Subject } from "@/features/schemas/reference-data-schema"
import type { ScheduleGenerationRun } from "@/features/schemas/schedule-generation-schema"
import type { SectionPlan } from "@/features/schemas/section-plan-schema"

export function DemandForecastDialog({
  open,
  onOpenChange,
  run,
  loading = false,
  error = false,
  onRetry,
  sections = [],
  plans = [],
  subjects = [],
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  run: ScheduleGenerationRun | null
  loading?: boolean
  error?: boolean
  onRetry?: () => void
  sections?: readonly Section[]
  plans?: readonly SectionPlan[]
  subjects?: readonly Subject[]
}) {
  const reducedMotion = useReducedMotion()
  const facultyLoad = run?.faculty_load
  const blockRecommendations = run?.section_block_recommendations ?? []
  const running = run?.status === "queued" || run?.status === "running"
  const rationaleGroups = buildSectionGenerationRationale(
    sections,
    plans,
    subjects,
    run,
  )

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        className="max-h-[92dvh] max-w-5xl overflow-y-auto p-0 sm:max-w-5xl"
        showCloseButton={false}
      >
        <motion.div
          layoutId="demand-forecast-surface"
          initial={reducedMotion ? false : { opacity: 0, scale: 0.86, y: -12 }}
          animate={{ opacity: 1, scale: 1, y: 0 }}
          exit={
            reducedMotion ? undefined : { opacity: 0, scale: 0.28, y: -120 }
          }
          transition={{ duration: 0.32, ease: [0.2, 0.75, 0.2, 1] }}
          className="grid gap-5 p-5 sm:p-7"
        >
          <DialogHeader>
            <div className="flex flex-wrap items-start justify-between gap-4">
              <div className="grid gap-2">
                <div className="flex items-center gap-2 text-primary">
                  <BrainCircuit className="size-4" aria-hidden="true" />
                  <span className="text-xs font-semibold tracking-[0.16em] uppercase">
                    Predictive planning
                  </span>
                </div>
                <DialogTitle>Demand Forecast</DialogTitle>
                <DialogDescription>
                  Forecasts are advisory. The Program Head can review and edit
                  every section, faculty, and room recommendation.
                </DialogDescription>
              </div>
              <div className="flex items-center gap-2">
                <Button type="button" variant="outline" size="sm" asChild>
                  <Link href="/portal/program-chair-analytics">
                    <ChartSpline data-icon="inline-start" aria-hidden="true" />
                    View in Analytics
                  </Link>
                </Button>
                <Badge
                  variant={
                    run?.status === "failed" ? "destructive" : "secondary"
                  }
                >
                  {loading
                    ? "Loading"
                    : running
                      ? "Generating"
                      : (run?.status ?? "No run")}
                </Badge>
              </div>
            </div>
          </DialogHeader>

          {running && (
            <p className="rounded-lg border border-primary/20 bg-primary/5 p-4 text-sm">
              Building the demand forecast, faculty-loading recommendation, and
              editable draft…
            </p>
          )}
          {loading && run === null && (
            <p className="rounded-lg border border-primary/20 bg-primary/5 p-4 text-sm">
              Loading the latest forecast…
            </p>
          )}
          {error && run === null && (
            <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-destructive/30 bg-destructive/5 p-4 text-sm text-destructive">
              <span>The latest forecast could not be loaded.</span>
              {onRetry && (
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  onClick={onRetry}
                >
                  Retry
                </Button>
              )}
            </div>
          )}
          {run?.status === "failed" && (
            <p className="rounded-lg border border-destructive/30 bg-destructive/5 p-4 text-sm text-destructive">
              {run.error_summary ?? "The forecast could not be generated."}
            </p>
          )}
          {run?.warnings.length ? (
            <GroupedWarningsList warnings={run.warnings} />
          ) : null}

          {run?.model && (
            <div className="grid gap-3 sm:grid-cols-3">
              <ForecastStat
                icon={<BrainCircuit />}
                label="Model strategy"
                value={
                  run.model.strategy === "random_forest"
                    ? "Random Forest"
                    : run.model.strategy === "historical_baseline"
                      ? "Historical baseline"
                      : run.model.strategy ===
                          "service_unavailable_historical_baseline"
                        ? "Historical fallback"
                        : "Waiting"
                }
                detail={run.model.model_version ?? ""}
              />
              <ForecastStat
                icon={<Gauge />}
                label="Historical observations"
                value={String(run.model.training_observation_count)}
                detail={
                  run.model.strategy === "historical_baseline"
                    ? "Sparse data fallback"
                    : run.model.strategy ===
                        "service_unavailable_historical_baseline"
                      ? "Service fallback"
                      : "Training input"
                }
              />
              <ForecastStat
                icon={<UsersRound />}
                label="Faculty loads"
                value={
                  facultyLoad?.equivalent_faculty_loads === null ||
                  facultyLoad?.equivalent_faculty_loads === undefined
                    ? "Set threshold"
                    : String(facultyLoad.equivalent_faculty_loads)
                }
                detail={
                  facultyLoad
                    ? `${facultyLoad.required_teaching_units} teaching units`
                    : "Loading"
                }
              />
            </div>
          )}

          <section
            className="grid gap-3"
            aria-label="Why these sections were generated"
          >
            {rationaleGroups.length ? (
              <Collapsible>
                <div className="flex flex-wrap items-end justify-between gap-3 rounded-lg border bg-muted/20 p-3">
                  <div>
                    <h3 className="font-heading text-lg">
                      Why these sections were generated
                    </h3>
                    <p className="text-sm text-muted-foreground">
                      {rationaleGroups.length} subject explanations are ready.
                    </p>
                  </div>
                  <CollapsibleTrigger asChild>
                    <Button type="button" variant="outline" size="sm">
                      View explanations
                    </Button>
                  </CollapsibleTrigger>
                </div>
                <CollapsibleContent className="pt-3">
                  <div className="grid max-h-96 gap-2 overflow-y-auto pr-1">
                    {rationaleGroups.map((group) => (
                      <div
                        key={`${group.subjectId}-${group.yearLevel}`}
                        className="rounded-lg border p-3"
                      >
                        <div className="flex flex-wrap items-center justify-between gap-2">
                          <div>
                            <p className="font-medium">
                              {group.subjectCode}
                              {group.subjectTitle
                                ? ` · ${group.subjectTitle}`
                                : ""}
                            </p>
                            <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                              <span>
                                {group.yearLevel
                                  ? formatYearLevel(group.yearLevel)
                                  : "Year not set"}
                              </span>
                              {group.curriculumVersion && (
                                <Badge variant="outline" className="px-1.5 py-0 text-[10px] font-mono">
                                  Curriculum {group.curriculumVersion}
                                </Badge>
                              )}
                            </div>
                          </div>
                          <Badge variant="secondary">
                            {group.sectionCount} section
                            {group.sectionCount === 1 ? "" : "s"}
                          </Badge>
                        </div>
                        <ul className="mt-2 grid gap-1">
                          {group.reasons.map((reason) => (
                            <li
                              key={reason}
                              className="text-sm text-muted-foreground"
                            >
                              {reason}
                            </li>
                          ))}
                        </ul>
                      </div>
                    ))}
                  </div>
                </CollapsibleContent>
              </Collapsible>
            ) : (
              <div>
                <h3 className="font-heading text-lg">
                  Why these sections were generated
                </h3>
                <p className="mt-3 rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                  {loading
                    ? "Loading the latest forecast…"
                    : running
                      ? "Forecasting demand…"
                      : "No generated sections yet for this term."}
                </p>
              </div>
            )}
          </section>

          <section
            className="grid gap-3"
            aria-label="Recommended block sections"
          >
            <div>
              <h3 className="font-heading text-lg">
                Recommended block sections
              </h3>
              <p className="text-sm text-muted-foreground">
                These program and year-level block counts are editable in the
                schedule workspace.
              </p>
            </div>
            <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
              {blockRecommendations.length ? (
                blockRecommendations.map((recommendation) => (
                  <div
                    key={`${recommendation.program_code}-${recommendation.year_level}`}
                    className="rounded-lg border bg-muted/20 p-3"
                  >
                    <div className="flex items-start justify-between gap-2">
                      <p className="font-medium">
                        {recommendation.program_code} ·{" "}
                        {formatYearLevel(recommendation.year_level)}
                      </p>
                      {recommendation.curriculum_effective_school_year && (
                        <Badge
                          variant={recommendation.curriculum_effective_school_year === "2024-2029" ? "secondary" : "outline"}
                          className="font-mono text-[11px]"
                        >
                          {recommendation.curriculum_effective_school_year}
                        </Badge>
                      )}
                    </div>
                    {recommendation.curriculum_name && (
                      <p className="text-xs text-muted-foreground mt-0.5">
                        {recommendation.curriculum_name}
                      </p>
                    )}
                    <p className="text-sm text-muted-foreground mt-1.5">
                      {recommendation.recommended_block_sections} blocks ·{" "}
                      {recommendation.students_per_block} seats each
                    </p>
                    {recommendation.is_overridden && (
                      <Badge className="mt-2" variant="outline">
                        Chair override
                      </Badge>
                    )}
                  </div>
                ))
              ) : (
                <p className="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                  Generate a forecast to see recommended blocks by program and
                  year level.
                </p>
              )}
            </div>
          </section>

          {facultyLoad && (
            <section
              className="grid gap-3"
              aria-label="Faculty loading support"
            >
              <div>
                <h3 className="font-heading text-lg">
                  Faculty Loading Support
                </h3>
                <p className="text-sm text-muted-foreground">
                  {facultyLoad.threshold_units === null
                    ? "Set a college-term threshold in Faculty Loading to enable overload flags."
                    : `Threshold: ${facultyLoad.threshold_units} units per professor.`}
                </p>
              </div>
              <div className="grid gap-3 sm:grid-cols-4">
                <ForecastStat
                  icon={<UsersRound />}
                  label="Teaching assignments"
                  value={String(facultyLoad.required_assignments)}
                  detail="Section-subject rows"
                />
                <ForecastStat
                  icon={<Gauge />}
                  label="Total units"
                  value={String(facultyLoad.required_teaching_units)}
                  detail="Predicted teaching load"
                />
                <ForecastStat
                  icon={<UsersRound />}
                  label="Unassigned"
                  value={String(facultyLoad.unassigned_count)}
                  detail="Needs Chair decision"
                />
                <ForecastStat
                  icon={<Gauge />}
                  label="Overload flags"
                  value={
                    facultyLoad.threshold_units === null
                      ? "—"
                      : String(facultyLoad.overloaded_count)
                  }
                  detail={
                    facultyLoad.threshold_units === null
                      ? "Threshold not set"
                      : "Above threshold"
                  }
                />
              </div>
            </section>
          )}

          <div className="flex justify-end">
            <Button
              type="button"
              variant="outline"
              onClick={() => onOpenChange(false)}
            >
              Close forecast
            </Button>
          </div>
        </motion.div>
      </DialogContent>
    </Dialog>
  )
}

function ForecastStat({
  icon,
  label,
  value,
  detail,
}: {
  icon: ReactNode
  label: string
  value: string
  detail: string
}) {
  return (
    <div className="grid gap-1 rounded-lg border bg-card p-4 shadow-sm">
      <div className="flex items-center gap-2 text-primary">
        <span className="size-4">{icon}</span>
        <span className="text-xs font-semibold tracking-wide uppercase">
          {label}
        </span>
      </div>
      <strong className="font-heading text-2xl">{value}</strong>
      <span className="text-xs text-muted-foreground">{detail}</span>
    </div>
  )
}
