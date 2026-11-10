"use client"

import { useMemo, useState } from "react"
import { CalendarDays } from "lucide-react"

import { SectionScheduleCalendarDialog } from "@/features/components/portal/section-schedule-calendar-dialog"
import { StatusRegion } from "@/features/components/portal/status-region"
import { Badge } from "@/features/components/ui/badge"
import { Button } from "@/features/components/ui/button"
import { Card } from "@/features/components/ui/card"
import { Field, FieldLabel } from "@/features/components/ui/field"
import { Input } from "@/features/components/ui/input"
import type {
  Curriculum,
  Program,
  Section,
  Subject,
} from "@/features/schemas/reference-data-schema"
import type { SectionPlan } from "@/features/schemas/section-plan-schema"

const ALL_VALUE = "all"

const COLLEGE_LABELS: Record<SectionPlan["college"], string> = {
  ccs: "CCS",
  coe: "COE",
  coa: "COA",
  cbae: "CBAE",
}

const YEAR_LABELS: Record<SectionPlan["year_level"], string> = {
  1: "1st Year",
  2: "2nd Year",
  3: "3rd Year",
  4: "4th Year",
}

// Colleges with a single program (CCS: BSIT, COA: BSA) gain nothing from a
// Major filter — every section already reads as that one program. CBAE and
// COE each carry several programs (Financial Management, Marketing
// Management, HRM, Entrepreneurship for CBAE; several BSED majors plus TCP
// for COE), so those are the only colleges where "which Major" is a
// meaningful question.
const COLLEGES_WITH_MAJORS: readonly SectionPlan["college"][] = ["cbae", "coe"]

const collator = new Intl.Collator(undefined, {
  numeric: true,
  sensitivity: "base",
})

interface PublishedSectionRow {
  section: Section
  subject: Subject | undefined
  plan: SectionPlan | undefined
  program: Program | undefined
}

/** "BS Business Administration major in Financial Management" → "Financial Management". Falls back to the full program name when it doesn't follow that pattern (e.g. "BS Entrepreneurship"). */
function majorLabel(programName: string) {
  const marker = " major in "
  const index = programName.indexOf(marker)
  return index === -1 ? programName : programName.slice(index + marker.length)
}

function matchesSearch(row: PublishedSectionRow, search: string) {
  const needle = search.trim().toLocaleLowerCase()
  if (needle === "") return true

  return (
    row.section.section_code.toLocaleLowerCase().includes(needle) ||
    (row.subject?.code.toLocaleLowerCase().includes(needle) ?? false) ||
    (row.subject?.title.toLocaleLowerCase().includes(needle) ?? false)
  )
}


/**
 * The Executive Director's published-sections view spans every college
 * (unlike the Dean's single-college schedule review), so a flat list of
 * hundreds of individual subject rows stops being readable. This groups
 * rows back into their block sections (one small table per HR201, EN301,
 * etc.) and filters by College, Year, and — for CBAE/COE, the only colleges
 * with more than one program — Major.
 *
 * College and Year come from the `AcademicTermSectionPlan` a section was
 * generated from (joined by `section_plan_id`); Major is resolved one hop
 * further via that plan's `curriculum_id` → `Curriculum.program_id` →
 * `Program`. None of this is guessable from the section code itself.
 */
export function PublishedSectionsPanel({
  sections,
  subjects,
  sectionPlans,
  curricula,
  programs,
}: {
  sections: readonly Section[]
  subjects: readonly Subject[]
  sectionPlans: readonly SectionPlan[]
  curricula: readonly Curriculum[]
  programs: readonly Program[]
}) {
  const [search, setSearch] = useState("")
  const [college, setCollege] = useState<string>(ALL_VALUE)
  const [yearLevel, setYearLevel] = useState<string>(ALL_VALUE)
  const [major, setMajor] = useState<string>(ALL_VALUE)
  const [calendarSection, setCalendarSection] = useState<{
    sectionCode: string
    rows: PublishedSectionRow[]
    majorName?: string
  } | null>(null)

  const rows = useMemo(() => {
    const planById = new Map(sectionPlans.map((plan) => [plan.id, plan]))
    const curriculumById = new Map(curricula.map((item) => [item.id, item]))
    const programById = new Map(programs.map((item) => [item.id, item]))

    return sections.map((section) => {
      const plan =
        section.section_plan_id != null
          ? planById.get(section.section_plan_id)
          : undefined
      const curriculum = plan ? curriculumById.get(plan.curriculum_id) : undefined
      const program = curriculum ? programById.get(curriculum.program_id) : undefined

      return {
        section,
        subject: subjects.find((item) => item.id === section.subject_id),
        plan,
        program,
      }
    })
  }, [sections, subjects, sectionPlans, curricula, programs])

  const collegeOptions = useMemo(
    () =>
      [
        ...new Set(
          rows
            .map((row) => row.plan?.college)
            .filter((value): value is SectionPlan["college"] => value !== undefined),
        ),
      ].sort((left, right) => left.localeCompare(right)),
    [rows],
  )

  const showMajorFilter = COLLEGES_WITH_MAJORS.includes(
    college as SectionPlan["college"],
  )

  const majorOptions = useMemo(() => {
    if (!showMajorFilter) return []

    const byId = new Map<number, string>()
    for (const row of rows) {
      if (row.plan?.college === college && row.program)
        byId.set(row.program.id, majorLabel(row.program.name))
    }
    return [...byId.entries()].sort(([, left], [, right]) =>
      collator.compare(left, right),
    )
  }, [rows, showMajorFilter, college])

  function selectCollege(value: string) {
    setCollege(value)
    setMajor(ALL_VALUE)
  }

  const visible = rows.filter(
    (row) =>
      matchesSearch(row, search) &&
      (college === ALL_VALUE || row.plan?.college === college) &&
      (yearLevel === ALL_VALUE || String(row.plan?.year_level) === yearLevel) &&
      (major === ALL_VALUE || row.program?.id === Number(major)),
  )

  const groups = useMemo(() => {
    const bySection = new Map<string, PublishedSectionRow[]>()
    for (const row of visible)
      bySection.set(row.section.section_code, [
        ...(bySection.get(row.section.section_code) ?? []),
        row,
      ])

    return [...bySection.entries()]
      .sort(([left], [right]) => collator.compare(left, right))
      .map(([sectionCode, groupRows]) => {
        const groupCollege = groupRows[0]?.plan?.college
        return {
          sectionCode,
          rows: groupRows,
          major:
            groupCollege && COLLEGES_WITH_MAJORS.includes(groupCollege)
              ? groupRows[0]?.program
              : undefined,
        }
      })
  }, [visible])

  return (
    <div className="grid gap-4">
      <Field>
        <FieldLabel htmlFor="published-sections-search">Search</FieldLabel>
        <Input
          id="published-sections-search"
          value={search}
          onChange={(event) => setSearch(event.target.value)}
          placeholder="Search by subject or section code"
        />
      </Field>

      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <div className="grid gap-2" role="group" aria-label="Filter by college">
          <span className="text-sm font-medium">College</span>
          <div className="flex flex-wrap gap-2">
            <Button
              type="button"
              size="sm"
              variant={college === ALL_VALUE ? "default" : "outline"}
              onClick={() => selectCollege(ALL_VALUE)}
            >
              All
            </Button>
            {collegeOptions.map((option) => (
              <Button
                key={option}
                type="button"
                size="sm"
                variant={college === option ? "default" : "outline"}
                onClick={() => selectCollege(option)}
              >
                {COLLEGE_LABELS[option]}
              </Button>
            ))}
          </div>
        </div>

        <div className="grid gap-2" role="group" aria-label="Filter by year level">
          <span className="text-sm font-medium">Year</span>
          <div className="flex flex-wrap gap-2">
            <Button
              type="button"
              size="sm"
              variant={yearLevel === ALL_VALUE ? "default" : "outline"}
              onClick={() => setYearLevel(ALL_VALUE)}
            >
              All
            </Button>
            {([1, 2, 3, 4] as const).map((year) => (
              <Button
                key={year}
                type="button"
                size="sm"
                variant={yearLevel === String(year) ? "default" : "outline"}
                onClick={() => setYearLevel(String(year))}
              >
                {YEAR_LABELS[year]}
              </Button>
            ))}
          </div>
        </div>

        {showMajorFilter && (
          <Field>
            <FieldLabel htmlFor="published-sections-major">Major</FieldLabel>
            <select
              id="published-sections-major"
              className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
              value={major}
              onChange={(event) => setMajor(event.target.value)}
            >
              <option value={ALL_VALUE}>All majors</option>
              {majorOptions.map(([id, label]) => (
                <option key={id} value={id}>
                  {label}
                </option>
              ))}
            </select>
          </Field>
        )}
      </div>

      <StatusRegion
        message={
          sections.length > 0
            ? `${visible.length} of ${sections.length} published section${sections.length === 1 ? "" : "s"} shown.`
            : null
        }
      />

      {groups.length === 0 && sections.length > 0 ? (
        <p className="text-sm text-muted-foreground">
          No published sections match the current filters.
        </p>
      ) : (
        <div className="grid gap-4 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
          {groups.map(({ sectionCode, rows: groupRows, major: groupMajor }) => {
            const totalUnits = groupRows.reduce(
              (sum, r) => sum + (r.subject?.units ?? 0),
              0,
            )
            const yearLevel = groupRows[0]?.plan?.year_level
            const capacity = groupRows[0]?.section.capacity

            return (
              <Card
                key={sectionCode}
                role="article"
                aria-label={`${sectionCode} section`}
                className="group flex flex-col justify-between rounded-xl border p-4 text-center transition-all duration-200 hover:border-primary/80 hover:shadow-md hover:bg-muted/10 cursor-pointer active:scale-[0.99]"
                onClick={() =>
                  setCalendarSection({
                    sectionCode,
                    rows: groupRows,
                    majorName: groupMajor?.name,
                  })
                }
              >
                <div className="flex flex-col items-center gap-2">
                  <div className="text-2xl font-bold tracking-tight text-foreground group-hover:text-primary transition-colors">
                    {sectionCode}
                  </div>
                  <div className="flex flex-wrap items-center justify-center gap-1.5">
                    {capacity != null && (
                      <Badge variant="secondary">{capacity} seats</Badge>
                    )}
                    {totalUnits > 0 && (
                      <Badge variant="outline">{totalUnits} units</Badge>
                    )}
                    {yearLevel != null && (
                      <Badge variant="outline">{YEAR_LABELS[yearLevel]}</Badge>
                    )}
                    {groupMajor && (
                      <Badge variant="outline" className="text-xs">
                        {majorLabel(groupMajor.name)}
                      </Badge>
                    )}
                  </div>
                  <span className="text-xs text-muted-foreground">
                    {groupRows.length} subject{groupRows.length === 1 ? "" : "s"}
                  </span>
                </div>

                <div className="mt-4 border-t pt-3">
                  <Button
                    type="button"
                    variant="default"
                    className="w-full gap-1.5"
                    onClick={(e) => {
                      e.stopPropagation()
                      setCalendarSection({
                        sectionCode,
                        rows: groupRows,
                        majorName: groupMajor?.name,
                      })
                    }}
                  >
                    <CalendarDays data-icon="inline-start" aria-hidden="true" />
                    View schedule
                  </Button>
                </div>
              </Card>
            )
          })}
        </div>
      )}

      <SectionScheduleCalendarDialog
        open={calendarSection !== null}
        onOpenChange={(open) => {
          if (!open) setCalendarSection(null)
        }}
        title={`${calendarSection?.sectionCode ?? ""} Schedule`}
        subtitle={
          calendarSection?.majorName
            ? `${majorLabel(calendarSection.majorName)} · Published Block Section`
            : "Published Block Section"
        }
        items={(calendarSection?.rows ?? []).map((row) => ({
          id: row.section.id,
          subject_code: row.subject?.code ?? `Section #${row.section.id}`,
          subject_title: row.subject?.title ?? null,
          units: row.subject?.units ?? null,
          section_code: row.section.section_code,
          room: row.section.room ?? null,
          professor_name: null,
          schedule_days: row.section.schedule_days,
          starts_at_time: row.section.starts_at_time,
          ends_at_time: row.section.ends_at_time,
          modality: row.section.modality ?? null,
        }))}
        disabled={true}
      />
    </div>
  )
}
