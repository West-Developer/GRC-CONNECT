"use client"

import { useMemo, useState } from "react"

import { useAuth } from "@/features/auth/use-auth"
import { AsyncBoundary } from "@/features/components/portal/async-boundary"
import { EnrollmentMovementsPanel } from "@/features/components/portal/enrollment-movements-panel"
import { EnrollmentYearOverYearChart } from "@/features/components/portal/enrollment-year-over-year-chart"
import { SchoolYearRangeSlider } from "@/features/components/portal/school-year-range-slider"
import { WorkspacePage } from "@/features/components/portal/workspace-page"
import { Badge } from "@/features/components/ui/badge"
import { Button } from "@/features/components/ui/button"
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
} from "@/features/components/ui/card"
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/features/components/ui/table"
import {
  Tabs,
  TabsContent,
  TabsList,
  TabsTrigger,
} from "@/features/components/ui/tabs"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/features/components/ui/select"
import { formatYearLevel } from "@/features/lib/format-year-level"
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from "@/features/components/ui/collapsible"
import { useProgramChairAnalyticsSummaryQuery } from "@/features/hooks/use-dashboard"
import { useAcademicTermsQuery } from "@/features/hooks/use-reference-data"
import { useLatestScheduleGenerationRunQuery } from "@/features/hooks/use-schedule-generation"
import { buildPrescriptiveRecommendations } from "@/features/lib/prescriptive-recommendations"
import { getActiveAcademicTerm } from "@/features/services/reference-data-service"
import type { ProgramChairAnalyticsSummary } from "@/features/schemas/dashboard-schema"
import type { ScheduleGenerationRun } from "@/features/schemas/schedule-generation-schema"

/** Snake-case status keys -> readable labels, no lookup table to keep in sync. */
function humanize(key: string): string {
  return key
    .split("_")
    .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
    .join(" ")
}

const departments = [
  { value: "ccs", label: "College of Computer Studies" },
  { value: "coe", label: "College of Education" },
  { value: "coa", label: "College of Accountancy" },
  {
    value: "cbae",
    label: "College of Business Administration and Entrepreneurship",
  },
] as const

function DescriptiveTab({
  summary,
}: {
  summary: ProgramChairAnalyticsSummary
}) {
  const scopeLabel =
    summary.college === "all"
      ? "all departments"
      : summary.college.toUpperCase()

  return (
    <div className="grid gap-4">
      <Card>
        <CardHeader>
          <CardTitle level={2}>Filtered enrollment</CardTitle>
          <p className="text-sm text-muted-foreground">
            Counts for {scopeLabel} within the selected school-year range. The
            trend uses only official Enrolled records; the badges retain
            workflow context.
          </p>
        </CardHeader>
        <CardContent className="grid gap-4">
          <div className="rounded-lg border bg-muted/30 p-4">
            <p className="text-sm text-muted-foreground">Officially enrolled</p>
            <p className="font-serif text-3xl font-semibold tabular-nums">
              {summary.official_enrolled_count}
            </p>
          </div>
          <div className="flex flex-wrap gap-2">
            {Object.entries(summary.enrollment_status_counts).map(
              ([status, count]) => (
                <Badge key={status} variant="outline">
                  {humanize(status)}: {count}
                </Badge>
              ),
            )}
          </div>
        </CardContent>
      </Card>
      <EnrollmentYearOverYearChart points={summary.year_over_year} />
    </div>
  )
}

function PredictiveTab({
  run,
  loading,
}: {
  run: ScheduleGenerationRun | null
  loading: boolean
}) {
  const forecasts = run?.forecasts ?? []
  const [open, setOpen] = useState(false)
  const totalSuggestedSections = forecasts.reduce(
    (total, forecast) => total + forecast.suggested_section_count,
    0,
  )

  return (
    <Card>
      <CardHeader>
        <CardTitle level={2}>Section demand forecast</CardTitle>
        <p className="text-sm text-muted-foreground">
          Compact overview of the selected forecast term. Expand rows only when
          you need subject-level detail.
        </p>
      </CardHeader>
      <CardContent>
        {forecasts.length ? (
          <Collapsible open={open} onOpenChange={setOpen}>
            <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border bg-muted/20 p-3">
              <div className="flex flex-wrap gap-4 text-sm">
                <span>
                  <strong className="tabular-nums">{forecasts.length}</strong>{" "}
                  subject forecasts
                </span>
                <span>
                  <strong className="tabular-nums">
                    {totalSuggestedSections}
                  </strong>{" "}
                  suggested sections
                </span>
              </div>
              <CollapsibleTrigger asChild>
                <Button type="button" variant="outline" size="sm">
                  {open
                    ? "Hide forecast rows"
                    : `View ${forecasts.length} forecast rows`}
                </Button>
              </CollapsibleTrigger>
            </div>
            <CollapsibleContent className="pt-3">
              <div className="max-h-96 overflow-auto rounded-lg border">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>Subject</TableHead>
                      <TableHead>Year</TableHead>
                      <TableHead>Curriculum</TableHead>
                      <TableHead>Predicted students</TableHead>
                      <TableHead>Sections needed</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {forecasts.map((forecast) => (
                      <TableRow
                        key={`${forecast.curriculum_id ?? "legacy"}-${forecast.subject_id}-${forecast.year_level}`}
                      >
                        <TableCell>
                          <p className="font-medium">{forecast.subject_code}</p>
                          <p className="text-xs text-muted-foreground">
                            {forecast.subject_title}
                          </p>
                        </TableCell>
                        <TableCell>
                          {formatYearLevel(forecast.year_level)}
                        </TableCell>
                        <TableCell>
                          {forecast.curriculum_name ? (
                            <>
                              <p className="font-medium">
                                {forecast.curriculum_name}
                              </p>
                              <p className="text-xs text-muted-foreground">
                                {forecast.curriculum_effective_school_year}
                              </p>
                            </>
                          ) : (
                            "—"
                          )}
                        </TableCell>
                        <TableCell>
                          {forecast.predicted_demand.toFixed(0)}
                        </TableCell>
                        <TableCell>
                          <Badge variant="secondary">
                            {forecast.suggested_section_count}
                          </Badge>
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </div>
            </CollapsibleContent>
          </Collapsible>
        ) : (
          <p className="rounded-lg border border-dashed py-9 text-center text-sm text-muted-foreground">
            {loading
              ? "Loading the latest forecast…"
              : "No forecast rows are available yet. Generate a Demand Forecast from Enrollment."}
          </p>
        )}
      </CardContent>
    </Card>
  )
}

function PrescriptiveTab({ run }: { run: ScheduleGenerationRun | null }) {
  const recommendations = run ? buildPrescriptiveRecommendations(run) : []
  const [open, setOpen] = useState(false)
  const facultyLoad = run?.faculty_load
  const forecastCount = run?.forecasts?.length ?? 0

  return (
    <Card>
      <CardHeader>
        <div className="flex flex-wrap items-center gap-2">
          <CardTitle level={2}>Recommended actions</CardTitle>
          <Badge variant="outline">Rule-based, not a new model</Badge>
        </div>
        <p className="text-sm text-muted-foreground">
          A compact rule-based action summary for the selected forecast term.
        </p>
      </CardHeader>
      <CardContent>
        {recommendations.length === 0 ? (
          <p className="py-6 text-center text-sm text-muted-foreground">
            Generate a Demand Forecast to see recommended actions.
          </p>
        ) : (
          <Collapsible open={open} onOpenChange={setOpen}>
            <div className="grid gap-3 sm:grid-cols-3">
              <CompactMetric label="Forecast actions" value={forecastCount} />
              <CompactMetric
                label="Unassigned faculty"
                value={facultyLoad?.unassigned_count ?? 0}
              />
              <CompactMetric
                label="Overload flags"
                value={facultyLoad?.overloaded_count ?? 0}
              />
            </div>
            <div className="mt-3 flex justify-end">
              <CollapsibleTrigger asChild>
                <Button type="button" variant="outline" size="sm">
                  {open
                    ? "Hide action details"
                    : `View ${recommendations.length} action details`}
                </Button>
              </CollapsibleTrigger>
            </div>
            <CollapsibleContent className="pt-3">
              <ul className="grid max-h-80 gap-2 overflow-y-auto pr-1">
                {recommendations.map((recommendation) => (
                  <li
                    key={recommendation}
                    className="rounded-lg border bg-muted/20 p-3 text-sm"
                  >
                    {recommendation}
                  </li>
                ))}
              </ul>
            </CollapsibleContent>
          </Collapsible>
        )}
      </CardContent>
    </Card>
  )
}

function CompactMetric({ label, value }: { label: string; value: number }) {
  return (
    <div className="rounded-lg border bg-muted/20 p-3">
      <p className="text-xs text-muted-foreground">{label}</p>
      <p className="font-heading text-2xl tabular-nums">{value}</p>
    </div>
  )
}

export function AnalyticsDashboardWorkspace() {
  const { session } = useAuth()
  const isProgramChair = session?.role === "program_chair"
  const isRegistrarHead = session?.role === "registrar_head"
  const authorized = isProgramChair || isRegistrarHead
  const termsQuery = useAcademicTermsQuery({ enabled: authorized })
  const currentTerm = getActiveAcademicTerm(termsQuery.data)
  const [selectedTermId, setSelectedTermId] = useState<number | null>(null)
  const [selectedYearLevel, setSelectedYearLevel] = useState<number | null>(
    null,
  )
  const [trendSchoolYearRange, setTrendSchoolYearRange] = useState<{
    startSchoolYear: string | null
    endSchoolYear: string | null
  }>({ startSchoolYear: null, endSchoolYear: null })
  const [trendSemester, setTrendSemester] = useState<string | null>(null)
  const [selectedDepartment, setSelectedDepartment] = useState<string | null>(
    null,
  )
  const selectedTerm = useMemo(() => {
    const terms = termsQuery.data ?? []

    return (
      terms.find((term) => term.id === selectedTermId) ?? currentTerm ?? null
    )
  }, [currentTerm, selectedTermId, termsQuery.data])
  const termId = selectedTerm?.id ?? 0
  const schoolYears = useMemo(
    () =>
      [
        ...new Set((termsQuery.data ?? []).map((term) => term.school_year)),
      ].sort(),
    [termsQuery.data],
  )
  const rangeStartSchoolYear =
    trendSchoolYearRange.startSchoolYear ?? schoolYears[0] ?? undefined
  const rangeEndSchoolYear =
    trendSchoolYearRange.endSchoolYear ?? schoolYears.at(-1) ?? undefined
  const summaryQuery = useProgramChairAnalyticsSummaryQuery(
    selectedTerm?.id,
    selectedYearLevel ?? undefined,
    rangeStartSchoolYear,
    rangeEndSchoolYear,
    trendSemester ?? undefined,
    selectedDepartment ?? undefined,
    authorized && termsQuery.isSuccess && selectedTerm !== null,
  )
  const runQuery = useLatestScheduleGenerationRunQuery(termId, isProgramChair)
  return (
    <WorkspacePage
      title="Enrollment Analytics"
      description={
        isRegistrarHead
          ? "Official enrollment analytics across all departments, with an optional department filter."
          : "Descriptive, predictive, and prescriptive views built from your college's existing enrollment and forecast data."
      }
      unauthorized={!authorized}
      lastUpdated={summaryQuery.dataUpdatedAt}
    >
      <AsyncBoundary query={termsQuery} loadingLabel="Loading analytics…">
        {() => (
          <div className="grid gap-5">
            <Card>
              <CardHeader>
                <CardTitle level={2}>Analytics filters</CardTitle>
              </CardHeader>
              <CardContent className="grid gap-4">
                {/* Dropdowns — inline, wrap on small screens */}
                <div className="flex flex-wrap gap-3">
                  {isRegistrarHead ? (
                    <label className="grid min-w-[11rem] flex-1 gap-1 text-sm font-medium">
                      Department
                      <Select
                        value={selectedDepartment ?? "all"}
                        onValueChange={(value) =>
                          setSelectedDepartment(value === "all" ? null : value)
                        }
                      >
                        <SelectTrigger aria-label="Department">
                          <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                          <SelectItem value="all">All departments</SelectItem>
                          {departments.map((department) => (
                            <SelectItem
                              key={department.value}
                              value={department.value}
                            >
                              {department.label}
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                    </label>
                  ) : null}
                  <label className="grid min-w-[9rem] flex-1 gap-1 text-sm font-medium">
                    School year
                    <Select
                      value={selectedTerm?.school_year ?? ""}
                      onValueChange={(schoolYear) => {
                        const next =
                          (termsQuery.data ?? []).find(
                            (term) =>
                              term.school_year === schoolYear &&
                              term.semester === selectedTerm?.semester,
                          ) ??
                          (termsQuery.data ?? []).find(
                            (term) => term.school_year === schoolYear,
                          )
                        setSelectedTermId(next?.id ?? null)
                      }}
                    >
                      <SelectTrigger aria-label="School year">
                        <SelectValue placeholder="Select school year" />
                      </SelectTrigger>
                      <SelectContent>
                        {[
                          ...new Set(
                            (termsQuery.data ?? []).map(
                              (term) => term.school_year,
                            ),
                          ),
                        ].map((schoolYear) => (
                          <SelectItem key={schoolYear} value={schoolYear}>
                            {schoolYear}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </label>
                  <label className="grid min-w-[9rem] flex-1 gap-1 text-sm font-medium">
                    Semester
                    <Select
                      value={selectedTerm?.semester ?? ""}
                      onValueChange={(semester) => {
                        const next = (termsQuery.data ?? []).find(
                          (term) =>
                            term.school_year === selectedTerm?.school_year &&
                            term.semester === semester,
                        )
                        setSelectedTermId(next?.id ?? null)
                      }}
                    >
                      <SelectTrigger aria-label="Semester">
                        <SelectValue placeholder="Select semester" />
                      </SelectTrigger>
                      <SelectContent>
                        {[
                          ...new Set(
                            (termsQuery.data ?? []).map(
                              (term) => term.semester,
                            ),
                          ),
                        ].map((semester) => (
                          <SelectItem key={semester} value={semester}>
                            {semester}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </label>
                  <label className="grid min-w-[9rem] flex-1 gap-1 text-sm font-medium">
                    Student year level
                    <Select
                      value={
                        selectedYearLevel === null
                          ? "all"
                          : String(selectedYearLevel)
                      }
                      onValueChange={(value) =>
                        setSelectedYearLevel(
                          value === "all" ? null : Number(value),
                        )
                      }
                    >
                      <SelectTrigger aria-label="Student year level">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="all">All year levels</SelectItem>
                        {[1, 2, 3, 4].map((yearLevel) => (
                          <SelectItem key={yearLevel} value={String(yearLevel)}>
                            {formatYearLevel(yearLevel)}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </label>
                  <label className="grid min-w-[9rem] flex-1 gap-1 text-sm font-medium">
                    Trend semester
                    <Select
                      value={trendSemester ?? "all"}
                      onValueChange={(value) =>
                        setTrendSemester(value === "all" ? null : value)
                      }
                    >
                      <SelectTrigger aria-label="Trend semester">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="all">All semesters</SelectItem>
                        {[
                          ...new Set(
                            (termsQuery.data ?? []).map(
                              (term) => term.semester,
                            ),
                          ),
                        ].map((semester) => (
                          <SelectItem key={semester} value={semester}>
                            {semester}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </label>
                </div>
                {/* Range slider — full width below the dropdowns */}
                <SchoolYearRangeSlider
                  schoolYears={schoolYears}
                  startSchoolYear={rangeStartSchoolYear ?? null}
                  endSchoolYear={rangeEndSchoolYear ?? null}
                  onRangeChange={setTrendSchoolYearRange}
                />
              </CardContent>
            </Card>
            <Tabs defaultValue="descriptive">
              <TabsList>
                <TabsTrigger value="descriptive">Descriptive</TabsTrigger>
                <TabsTrigger value="drops">Drops</TabsTrigger>
                <TabsTrigger value="withdrawals">Withdrawals</TabsTrigger>
                <TabsTrigger value="shifts">Course shifts</TabsTrigger>
                {isProgramChair ? (
                  <TabsTrigger value="predictive">Predictive</TabsTrigger>
                ) : null}
                {isProgramChair ? (
                  <TabsTrigger value="prescriptive">Prescriptive</TabsTrigger>
                ) : null}
              </TabsList>
              <TabsContent value="descriptive">
                <AsyncBoundary
                  query={summaryQuery}
                  loadingLabel="Loading analytics summary…"
                >
                  {(summary) => <DescriptiveTab summary={summary} />}
                </AsyncBoundary>
              </TabsContent>
              {(["drops", "withdrawals", "shifts"] as const).map((movement) => (
                <TabsContent key={movement} value={movement}>
                  <EnrollmentMovementsPanel
                    type={movement}
                    termId={termId}
                    college={isRegistrarHead ? selectedDepartment : null}
                    canRecord={isRegistrarHead}
                  />
                </TabsContent>
              ))}
              {isProgramChair ? (
                <TabsContent value="predictive">
                  <PredictiveTab
                    run={runQuery.data ?? null}
                    loading={runQuery.isPending}
                  />
                </TabsContent>
              ) : null}
              {isProgramChair ? (
                <TabsContent value="prescriptive">
                  <PrescriptiveTab run={runQuery.data ?? null} />
                </TabsContent>
              ) : null}
            </Tabs>
          </div>
        )}
      </AsyncBoundary>
    </WorkspacePage>
  )
}
