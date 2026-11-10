"use client"

import { useMemo, useState } from "react"
import { useAuth } from "@/features/auth/use-auth"
import { AsyncBoundary } from "@/features/components/portal/async-boundary"
import { AttritionCohortChart } from "@/features/components/portal/attrition-cohort-chart"
import { SchoolYearRangeSlider } from "@/features/components/portal/school-year-range-slider"
import { StoppedStudentsTrendChart } from "@/features/components/portal/stopped-students-trend-chart"
import { WorkspacePage } from "@/features/components/portal/workspace-page"
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
} from "@/features/components/ui/card"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/features/components/ui/select"
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/features/components/ui/table"
import { useAttritionReportQuery } from "@/features/hooks/use-attrition-honors"
import { useProgramChairAnalyticsSummaryQuery } from "@/features/hooks/use-dashboard"
import {
  useAcademicTermsQuery,
  useProgramsQuery,
} from "@/features/hooks/use-reference-data"
import { formatYearLevel } from "@/features/lib/format-year-level"

const colleges = [
  ["ccs", "College of Computer Studies"],
  ["coe", "College of Education"],
  ["coa", "College of Accountancy"],
  ["cbae", "College of Business Administration and Entrepreneurship"],
] as const

export function AttritionAnalyticsWorkspace() {
  const { session } = useAuth()
  const authorized = session?.role === "registrar_head"
  const terms = useAcademicTermsQuery({ enabled: authorized })
  const programs = useProgramsQuery({ enabled: authorized })

  const allSchoolYears = useMemo(() => {
    if (!terms.data) return []
    return Array.from(new Set(terms.data.map((t) => t.school_year))).sort()
  }, [terms.data])

  const [range, setRange] = useState<{
    startSchoolYear: string | null
    endSchoolYear: string | null
  }>({ startSchoolYear: null, endSchoolYear: null })

  const startSchoolYear = range.startSchoolYear ?? allSchoolYears[0] ?? null
  const endSchoolYear =
    range.endSchoolYear ?? allSchoolYears[allSchoolYears.length - 1] ?? null

  const [college, setCollege] = useState<string>()
  const [programId, setProgramId] = useState<number>()
  const [yearLevel, setYearLevel] = useState<number>()

  const availableSchoolYears = useMemo(() => {
    if (!terms.data) return []
    const years = Array.from(new Set(terms.data.map((t) => t.school_year)))
    return years
      .filter((sy) => {
        // Filter within range if set
        if (startSchoolYear && sy < startSchoolYear) return false
        if (endSchoolYear && sy > endSchoolYear) return false

        const hasFirst = terms.data?.some(
          (t) =>
            t.school_year === sy &&
            (t.semester === "1st" || t.semester.toLowerCase().includes("1st")),
        )
        const hasSecond = terms.data?.some(
          (t) =>
            t.school_year === sy &&
            (t.semester === "2nd" || t.semester.toLowerCase().includes("2nd")),
        )
        return hasFirst && hasSecond
      })
      .sort()
      .reverse()
  }, [terms.data, startSchoolYear, endSchoolYear])

  const [selectedSchoolYear, setSelectedSchoolYear] = useState<string>()
  const activeSchoolYear =
    selectedSchoolYear && availableSchoolYears.includes(selectedSchoolYear)
      ? selectedSchoolYear
      : availableSchoolYears[0]

  const pair = useMemo(() => {
    if (!terms.data || !activeSchoolYear) {
      return { baseline: undefined, comparison: undefined }
    }
    const baseline = terms.data.find(
      (t) =>
        t.school_year === activeSchoolYear &&
        (t.semester === "1st" || t.semester.toLowerCase().includes("1st")),
    )?.id
    const comparison = terms.data.find(
      (t) =>
        t.school_year === activeSchoolYear &&
        (t.semester === "2nd" || t.semester.toLowerCase().includes("2nd")),
    )?.id
    return { baseline, comparison }
  }, [terms.data, activeSchoolYear])

  const activeTermId = pair.comparison ?? pair.baseline ?? terms.data?.[0]?.id

  // Trend query for multi-term enrollment over time across the selected range
  const trendSummary = useProgramChairAnalyticsSummaryQuery(
    activeTermId,
    yearLevel,
    startSchoolYear ?? undefined,
    endSchoolYear ?? undefined,
    undefined,
    college,
    authorized && terms.isSuccess && activeTermId !== undefined,
  )

  const report = useAttritionReportQuery({
    baselineAcademicTermId: pair.baseline,
    comparisonAcademicTermId: pair.comparison,
    college,
    programId,
    yearLevel,
  })

  return (
    <WorkspacePage
      title="Attrition analytics"
      description="Students who will not continue: those who withdrew, and those who finished the first semester but did not enroll in the second after its enrollment window closed. Students still enrolling and graduates are not counted. Results are anonymous cohort aggregates."
      unauthorized={!authorized}
      lastUpdated={report.dataUpdatedAt}
    >
      <div className="grid gap-5">
        {/* Filters Card with Range Slider */}
        <Card>
          <CardHeader>
            <CardTitle level={2}>Attrition &amp; retention filters</CardTitle>
          </CardHeader>
          <CardContent className="grid gap-6">
            {/* Filter Dropdowns Grid */}
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
              {availableSchoolYears.length > 0 && (
                <div className="grid gap-1.5">
                  <label className="text-xs font-medium text-muted-foreground">
                    Cohort School Year
                  </label>
                  <Select
                    value={activeSchoolYear}
                    onValueChange={(v) => setSelectedSchoolYear(v)}
                  >
                    <SelectTrigger
                      className="w-full"
                      aria-label="Cohort School Year"
                    >
                      <SelectValue placeholder="Select school year" />
                    </SelectTrigger>
                    <SelectContent>
                      {availableSchoolYears.map((sy) => (
                        <SelectItem key={sy} value={sy}>
                          S.Y. {sy}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
              )}
              <div className="grid gap-1.5">
                <label className="text-xs font-medium text-muted-foreground">
                  College / Department
                </label>
                <Select
                  onValueChange={(v) => setCollege(v === "all" ? undefined : v)}
                >
                  <SelectTrigger
                    className="w-full"
                    aria-label="College / Department"
                  >
                    <SelectValue placeholder="All colleges" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="all">All colleges</SelectItem>
                    {colleges.map(([v, l]) => (
                      <SelectItem key={v} value={v}>
                        {l}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <div className="grid gap-1.5">
                <label className="text-xs font-medium text-muted-foreground">
                  Degree Program
                </label>
                <Select
                  onValueChange={(v) =>
                    setProgramId(v === "all" ? undefined : Number(v))
                  }
                >
                  <SelectTrigger className="w-full" aria-label="Degree Program">
                    <SelectValue placeholder="All programs" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="all">All programs</SelectItem>
                    {programs.data?.map((p) => (
                      <SelectItem key={p.id} value={String(p.id)}>
                        {p.code}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <div className="grid gap-1.5">
                <label className="text-xs font-medium text-muted-foreground">
                  Year Level
                </label>
                <Select
                  onValueChange={(v) =>
                    setYearLevel(v === "all" ? undefined : Number(v))
                  }
                >
                  <SelectTrigger className="w-full" aria-label="Year Level">
                    <SelectValue placeholder="All years" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="all">All years</SelectItem>
                    {[1, 2, 3, 4].map((y) => (
                      <SelectItem key={y} value={String(y)}>
                        {formatYearLevel(y)}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
            </div>

            {/* School-Year Range Slider */}
            {allSchoolYears.length > 0 && (
              <SchoolYearRangeSlider
                schoolYears={allSchoolYears}
                startSchoolYear={startSchoolYear}
                endSchoolYear={endSchoolYear}
                onRangeChange={setRange}
              />
            )}
          </CardContent>
        </Card>

        {/* Multi-Term Stopped Students & Attrition Trend Line Chart */}
        {trendSummary.data?.year_over_year && (
          <StoppedStudentsTrendChart
            points={trendSummary.data.year_over_year}
          />
        )}

        {/* Attrition Cohort Analytics Section */}
        {terms.isLoading ? (
          <div className="flex h-48 items-center justify-center text-sm text-muted-foreground">
            Loading academic terms…
          </div>
        ) : availableSchoolYears.length === 0 ? (
          <div className="flex h-48 items-center justify-center text-sm text-muted-foreground">
            No complete school year pair (1st and 2nd semester) is available yet
            within this range.
          </div>
        ) : (
          <AsyncBoundary
            query={report}
            isEmpty={(data) => data.summary.baseline_count === 0}
            emptyMessage="No students from this cohort have finished the term and either withdrawn or missed the next enrollment window. Students still enrolling are not counted."
            loadingLabel="Loading attrition analytics…"
          >
            {(data) => (
              <div className="grid gap-4">
                <div className="grid gap-3 sm:grid-cols-4">
                  {[
                    ["Baseline", data.summary.baseline_count],
                    ["Retained", data.summary.retained_count],
                    ["Will not continue", data.summary.attrited_count],
                    ["Attrition", `${data.summary.attrition_rate}%`],
                  ].map(([label, value]) => (
                    <Card key={String(label)}>
                      <CardHeader>
                        <CardTitle level={2}>{label}</CardTitle>
                      </CardHeader>
                      <CardContent>
                        <p className="text-2xl font-semibold tabular-nums">
                          {value}
                        </p>
                      </CardContent>
                    </Card>
                  ))}
                </div>

                {(data.summary.undecided_count ?? 0) > 0 && (
                  <p className="rounded-md border bg-muted/40 p-3 text-sm text-muted-foreground">
                    {data.summary.undecided_count} student(s) from this cohort
                    are still deciding or enrolling, so they are not counted as
                    attrition yet.
                  </p>
                )}

                <AttritionCohortChart report={data} />

                <Card>
                  <CardHeader>
                    <CardTitle level={2}>
                      Program cohorts ({activeSchoolYear})
                    </CardTitle>
                  </CardHeader>
                  <CardContent>
                    <Table>
                      <TableHeader>
                        <TableRow>
                          <TableHead>Program</TableHead>
                          <TableHead>Baseline</TableHead>
                          <TableHead>Retained</TableHead>
                          <TableHead>Will not continue</TableHead>
                          <TableHead>Rate</TableHead>
                        </TableRow>
                      </TableHeader>
                      <TableBody>
                        {data.groups.programs.map((row) => (
                          <TableRow key={row.program_id}>
                            <TableCell>
                              {row.program_code} · {row.program_name}
                            </TableCell>
                            <TableCell>{row.baseline_count}</TableCell>
                            <TableCell>{row.retained_count}</TableCell>
                            <TableCell>{row.attrited_count}</TableCell>
                            <TableCell>{row.attrition_rate}%</TableCell>
                          </TableRow>
                        ))}
                      </TableBody>
                    </Table>
                  </CardContent>
                </Card>
              </div>
            )}
          </AsyncBoundary>
        )}
      </div>
    </WorkspacePage>
  )
}
