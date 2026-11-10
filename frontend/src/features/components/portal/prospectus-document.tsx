"use client"

import { useEffect, useId, useMemo, useState } from "react"
import { ChevronRight } from "lucide-react"

import { AsyncBoundary } from "@/features/components/portal/async-boundary"
import {
  PrintButton,
  PrintDocument,
} from "@/features/components/portal/print-document"
import { Badge } from "@/features/components/ui/badge"
import { Skeleton } from "@/features/components/ui/skeleton"
import {
  Table,
  TableBody,
  TableCaption,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/features/components/ui/table"
import { useProspectusQuery } from "@/features/hooks/use-academic-record"
import { useIsPhone } from "@/features/hooks/use-media-query"
import { formatYearLevel } from "@/features/lib/format-year-level"
import {
  markTone,
  markToneBadgeVariant,
  markToneRowClass,
} from "@/features/lib/grade-presentation"
import { groupPairedSubjects } from "@/features/lib/group-paired-subjects"
import { cn } from "@/features/lib/utils"
import type {
  Prospectus,
  ProspectusSemester,
} from "@/features/schemas/academic-record-schema"

/**
 * A student's full curriculum, year 1 semester 1 through year 4 semester 2:
 * one table per semester that actually has a placement, blank rows for
 * subjects not yet taken, and an "Additional / credited subjects" table for
 * grades outside the curriculum (transferee credit, a shifted-from
 * program's leftover grade) when any exist.
 */
export function ProspectusDocument({ studentId }: { studentId?: number }) {
  const query = useProspectusQuery(studentId)
  const isPhone = useIsPhone()

  return (
    <AsyncBoundary
      query={query}
      loadingLabel="Loading prospectus…"
      loadingFallback={<Skeleton className="h-96" />}
    >
      {(prospectus) => (
        <PrintDocument
          title="Prospectus"
          actions={<PrintButton label="Print / download prospectus" />}
        >
          <div className="mb-3 grid gap-1 text-sm">
            <p>
              <strong>{prospectus.student_number}</strong> ·{" "}
              {prospectus.program_name} ({prospectus.program_code})
            </p>
            <p>
              {prospectus.curriculum_name} · {prospectus.effective_school_year}
              {prospectus.enrollment_category_label
                ? ` · ${prospectus.enrollment_category_label}`
                : ""}
            </p>
          </div>

          {prospectus.curriculum_transition && (
            <div className="mb-4">
              <Table>
                <TableCaption>
                  Curriculum transition ·{" "}
                  {prospectus.curriculum_transition.source_curriculum_name} →{" "}
                  {prospectus.curriculum_transition.target_curriculum_name}
                </TableCaption>
                <TableHeader>
                  <TableRow>
                    <TableHead scope="col">Old subject</TableHead>
                    <TableHead scope="col">New credited subject</TableHead>
                    <TableHead scope="col">Status</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {prospectus.curriculum_transition.credits.map((credit) => (
                    <TableRow
                      key={`${credit.source_code}-${credit.target_code}`}
                    >
                      <TableCell>
                        {credit.source_code} — {credit.source_title}
                      </TableCell>
                      <TableCell>
                        {credit.target_code} — {credit.target_title}
                      </TableCell>
                      <TableCell>
                        <Badge>Credited</Badge>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
          )}

          {prospectus.transferee_credits.length > 0 && (
            <div className="mb-4">
              <Table>
                <TableCaption>Credited from a previous school</TableCaption>
                <TableHeader>
                  <TableRow>
                    <TableHead scope="col">Previous subject</TableHead>
                    <TableHead scope="col">Credited as</TableHead>
                    <TableHead scope="col">Status</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {prospectus.transferee_credits.map((credit, index) => (
                    <TableRow key={`${credit.source_subject_title}-${index}`}>
                      <TableCell>
                        {credit.source_subject_code
                          ? `${credit.source_subject_code} — `
                          : ""}
                        {credit.source_subject_title}
                        <span className="block text-xs text-muted-foreground">
                          {credit.source_institution}
                          {credit.source_school_year
                            ? ` · ${credit.source_school_year}`
                            : ""}
                          {credit.source_semester
                            ? ` (${credit.source_semester})`
                            : ""}
                        </span>
                        {credit.source_grade && (
                          <span className="block text-xs text-muted-foreground">
                            Grade from previous school: {credit.source_grade}
                          </span>
                        )}
                      </TableCell>
                      <TableCell>
                        {credit.target_code} — {credit.target_title}
                      </TableCell>
                      <TableCell>
                        <Badge
                          variant={
                            credit.awaiting_registrar_head_approval
                              ? "secondary"
                              : "default"
                          }
                        >
                          {credit.status_label}
                        </Badge>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
              <p className="mt-2 text-xs text-muted-foreground">
                Grades are shown as the previous school gave them, for
                information only. They are not converted and carry no GRC grade.
              </p>
              {prospectus.transferee_credits.some(
                (credit) => credit.awaiting_registrar_head_approval,
              ) && (
                <p className="mt-1 text-xs text-muted-foreground">
                  Credits marked &ldquo;Awaiting Registrar Head approval&rdquo;
                  were passed on by your Program Chair. They count toward your
                  enrollment now and become final once the Registrar Head
                  approves them.
                </p>
              )}
            </div>
          )}

          <ProspectusYears prospectus={prospectus} isPhone={isPhone} />

          {prospectus.unplaced_entries.length > 0 && (
            <div className="mt-4">
              <Table>
                <TableCaption>Additional / credited subjects</TableCaption>
                <TableHeader>
                  <TableRow>
                    <TableHead scope="col">Code</TableHead>
                    <TableHead scope="col">Subject description</TableHead>
                    <TableHead scope="col">Units</TableHead>
                    <TableHead scope="col">Grade</TableHead>
                    <TableHead scope="col">Term</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {prospectus.unplaced_entries.map((entry) => (
                    <TableRow key={entry.subject_id}>
                      <TableCell>{entry.code}</TableCell>
                      <TableCell>{entry.title}</TableCell>
                      <TableCell>{entry.units}</TableCell>
                      <TableCell>{entry.mark_label ?? "—"}</TableCell>
                      <TableCell>{entry.term_label}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
          )}
        </PrintDocument>
      )}
    </AsyncBoundary>
  )
}

/** How long the collapsed overview stays on screen before the student's own year opens. */
const OPEN_CURRENT_YEAR_DELAY_MS = 450

function groupSemestersByYear(
  semesters: readonly ProspectusSemester[],
): [number, ProspectusSemester[]][] {
  const byYear = new Map<number, ProspectusSemester[]>()
  for (const semester of semesters) {
    const list = byYear.get(semester.year_level) ?? []
    list.push(semester)
    byYear.set(semester.year_level, list)
  }
  return [...byYear.entries()].sort(([a], [b]) => a - b)
}

/**
 * The year to open for the student: the year level they are in now when the curriculum has it,
 * otherwise the first year with a subject not yet passed, otherwise the last year.
 */
function currentYearOf(
  years: readonly [number, ProspectusSemester[]][],
  studentYearLevel: number,
): number | undefined {
  if (years.some(([yearLevel]) => yearLevel === studentYearLevel)) {
    return studentYearLevel
  }
  return (
    years.find(([, semesters]) =>
      semesters.some((semester) =>
        semester.entries.some((entry) => markTone(entry.mark) !== "passed"),
      ),
    )?.[0] ?? years.at(-1)?.[0]
  )
}

/**
 * One collapsible card per year. Everything starts collapsed, so the student first sees the
 * whole curriculum at a glance, then the year they are in opens with a short animation. Every
 * year stays one click away. The panels are always in the DOM (collapsed ones are `inert`), so
 * printing, which expands them all, prints the full prospectus.
 */
function ProspectusYears({
  prospectus,
  isPhone,
}: {
  prospectus: Prospectus
  isPhone: boolean
}) {
  const idPrefix = useId()
  const years = useMemo(
    () => groupSemestersByYear(prospectus.semesters),
    [prospectus.semesters],
  )
  const currentYear = currentYearOf(years, prospectus.year_level)
  const [openYears, setOpenYears] = useState<ReadonlySet<number>>(new Set())

  useEffect(() => {
    if (currentYear === undefined) return
    const reduceMotion =
      typeof window.matchMedia === "function" &&
      window.matchMedia("(prefers-reduced-motion: reduce)").matches
    const timer = window.setTimeout(
      () => setOpenYears((current) => new Set(current).add(currentYear)),
      reduceMotion ? 0 : OPEN_CURRENT_YEAR_DELAY_MS,
    )
    return () => window.clearTimeout(timer)
  }, [currentYear])

  const toggle = (yearLevel: number) =>
    setOpenYears((current) => {
      const next = new Set(current)
      if (next.has(yearLevel)) {
        next.delete(yearLevel)
      } else {
        next.add(yearLevel)
      }
      return next
    })

  return years.map(([yearLevel, semesters]) => {
    const yearUnits = semesters.reduce(
      (sum, s) => sum + s.entries.reduce((eSum, e) => eSum + (e.units ?? 0), 0),
      0,
    )
    const completedEntries = semesters.reduce(
      (sum, s) =>
        sum +
        s.entries.filter(
          (e) =>
            (e.mark !== null && e.mark !== "W") ||
            e.credit?.status === "approved",
        ).length,
      0,
    )
    const totalEntries = semesters.reduce((sum, s) => sum + s.entries.length, 0)
    const isOpen = openYears.has(yearLevel)
    const buttonId = `${idPrefix}-year-${yearLevel}-button`
    const panelId = `${idPrefix}-year-${yearLevel}-panel`

    return (
      <section
        key={yearLevel}
        className="mb-4 overflow-hidden rounded-xl border bg-card print:mb-2 print:border-none print:shadow-none"
      >
        <button
          type="button"
          id={buttonId}
          aria-expanded={isOpen}
          aria-controls={panelId}
          onClick={() => toggle(yearLevel)}
          className="flex w-full cursor-pointer select-none items-center justify-between gap-2 border-b bg-muted/25 p-3.5 text-left transition-colors hover:bg-muted/40 print:hidden"
        >
          <span className="flex items-center gap-2 text-sm font-semibold">
            <ChevronRight
              aria-hidden="true"
              className={cn(
                "size-4 shrink-0 text-muted-foreground transition-transform duration-500 motion-reduce:transition-none",
                isOpen && "rotate-90",
              )}
            />
            {formatYearLevel(yearLevel)}
          </span>
          <span className="flex items-center gap-2">
            <Badge variant="secondary" className="text-xs">
              {completedEntries} / {totalEntries} completed
            </Badge>
            <Badge variant="outline" className="text-xs">
              {yearUnits} units
            </Badge>
          </span>
        </button>
        <div
          id={panelId}
          role="region"
          aria-labelledby={buttonId}
          inert={!isOpen}
          className={cn(
            "grid transition-[grid-template-rows] duration-500 ease-out motion-reduce:transition-none print:grid-rows-[1fr]",
            isOpen ? "grid-rows-[1fr]" : "grid-rows-[0fr]",
          )}
        >
          <div className="min-h-0 overflow-hidden print:overflow-visible">
            <div className="p-3">
              {semesters.map((semester) => (
                <SemesterTable
                  key={`${semester.year_level}-${semester.semester}`}
                  semester={semester}
                  isPhone={isPhone}
                />
              ))}
            </div>
          </div>
        </div>
      </section>
    )
  })
}

function SemesterTable({
  semester,
  isPhone,
}: {
  semester: ProspectusSemester
  isPhone: boolean
}) {
  // On a phone, a subject's Pre-requisite and Status stay collapsed until
  // tapped — Code, Title, Units, and Grade are the ones a student actually
  // scans for at a glance (stakeholder Doc 16). The cells still exist in the
  // DOM, just empty, so `td:empty { display: none }` in globals.css hides
  // them without any extra mobile-only CSS.
  const [expanded, setExpanded] = useState<ReadonlySet<number>>(new Set())
  const entries = groupPairedSubjects(semester.entries)

  return (
    <div className="mb-4">
      <Table className="caption-top" data-stack-mobile>
        <TableCaption className="mt-0 mb-2 text-left font-medium text-foreground">
          {formatYearLevel(semester.year_level)} · {semester.semester_label}
        </TableCaption>
        <TableHeader>
          <TableRow>
            <TableHead scope="col">Code</TableHead>
            <TableHead scope="col">Subject description</TableHead>
            <TableHead scope="col">Pre-requisite</TableHead>
            <TableHead scope="col">Units</TableHead>
            <TableHead scope="col">Grade</TableHead>
            <TableHead scope="col">Status</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {entries.map((entry) => {
            const tone = markTone(entry.mark)
            const detailsShown = !isPhone || expanded.has(entry.subject_id)

            return (
              <TableRow
                key={entry.subject_id}
                className={cn("print:bg-transparent", markToneRowClass(tone))}
              >
                <TableCell data-stack="full">
                  {isPhone ? (
                    <button
                      type="button"
                      className="flex w-full items-center gap-1.5 text-left"
                      aria-expanded={detailsShown}
                      onClick={() =>
                        setExpanded((current) => {
                          const next = new Set(current)
                          if (next.has(entry.subject_id)) {
                            next.delete(entry.subject_id)
                          } else {
                            next.add(entry.subject_id)
                          }
                          return next
                        })
                      }
                    >
                      <ChevronRight
                        aria-hidden="true"
                        className={cn(
                          "size-3.5 shrink-0 text-muted-foreground transition-transform",
                          detailsShown && "rotate-90",
                        )}
                      />
                      {entry.code}
                    </button>
                  ) : (
                    entry.code
                  )}
                  {entry.offered_either_semester && (
                    <Badge variant="outline" className="ml-2 print:hidden">
                      1st/2nd Sem
                    </Badge>
                  )}
                </TableCell>
                <TableCell data-stack="full">{entry.title}</TableCell>
                <TableCell data-label="Pre-requisite">
                  {detailsShown &&
                    (entry.prerequisites.length > 0
                      ? entry.prerequisites.map((p) => p.code).join(", ")
                      : "—")}
                </TableCell>
                <TableCell data-label="Units">{entry.units}</TableCell>
                <TableCell data-label="Grade">
                  {entry.mark ?? entry.credit?.source_grade ?? "—"}
                  {entry.mark === null && entry.credit?.source_grade && (
                    <span className="block text-xs text-muted-foreground">
                      previous school
                    </span>
                  )}
                </TableCell>
                <TableCell data-label="Status">
                  {detailsShown &&
                    (entry.mark === null && entry.credit ? (
                      <Badge
                        variant={
                          entry.credit.awaiting_registrar_head_approval
                            ? "secondary"
                            : "default"
                        }
                      >
                        {entry.credit.status_label}
                      </Badge>
                    ) : (
                      <Badge variant={markToneBadgeVariant(tone)}>
                        {entry.status_label ?? "Not taken"}
                      </Badge>
                    ))}
                </TableCell>
              </TableRow>
            )
          })}
        </TableBody>
      </Table>
    </div>
  )
}
