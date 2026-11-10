"use client"

import { useState } from "react"
import { LayoutGridIcon, ListIcon } from "lucide-react"

import { AsyncBoundary } from "@/features/components/portal/async-boundary"
import { GradeSlipDocument } from "@/features/components/portal/grade-slip-document"
import {
  PrintButton,
  PrintDocument,
} from "@/features/components/portal/print-document"
import { ProspectusDocument } from "@/features/components/portal/prospectus-document"
import { StudentCreditMappingDialog } from "@/features/components/portal/student-credit-mapping-dialog"
import { Badge } from "@/features/components/ui/badge"
import { Button } from "@/features/components/ui/button"
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/features/components/ui/card"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/features/components/ui/dialog"
import { Skeleton } from "@/features/components/ui/skeleton"
import {
  ToggleGroup,
  ToggleGroupItem,
} from "@/features/components/ui/toggle-group"
import { useAcademicRecordQuery } from "@/features/hooks/use-academic-record"
import { markTone, markToneBadgeVariant, markToneRowClass } from "@/features/lib/grade-presentation"
import { formatYearLevel } from "@/features/lib/format-year-level"
import { groupPairedSubjects } from "@/features/lib/group-paired-subjects"
import { cn } from "@/features/lib/utils"
import type {
  AcademicRecord,
  AcademicRecordTerm,
  GradeSlip,
} from "@/features/schemas/academic-record-schema"

/**
 * Reconstructs a full `GradeSlip` from one term of an `AcademicRecord` by
 * merging back in the student-identity fields the record hoists to its own
 * top level — so `GradeSlipDocument` renders either shape unmodified.
 * `generated_at` has no per-term source here; it isn't rendered by
 * `GradeSlipDocument`, so a fresh timestamp is a harmless placeholder.
 */
function toGradeSlip(
  record: AcademicRecord,
  term: AcademicRecordTerm,
): GradeSlip {
  return {
    type: "grade_slip",
    student_id: record.student_id,
    student_number: record.student_number,
    program_code: record.program_code,
    program_name: record.program_name,
    year_level: record.year_level,
    enrollment_category: record.enrollment_category,
    enrollment_category_label: record.enrollment_category_label,
    academic_term_id: term.academic_term_id,
    school_year: term.school_year,
    semester: term.semester,
    term_label: term.term_label,
    rows: term.rows,
    total_academic_units: term.total_academic_units,
    gpa_units: term.gpa_units,
    gpa: term.gpa,
    excluded_from_gpa_count: term.excluded_from_gpa_count,
    generated_at: new Date().toISOString(),
  }
}

function semesterOrdinal(semester: string): number {
  return semester === "2nd" ? 2 : 1
}

/**
 * School years newest first (the order terms already arrive in); each
 * year's own semesters are re-sorted 1st-then-2nd for display, since a
 * transcript reads left-to-right in the order the semesters happened, not
 * in the order they were most recently completed.
 */
function groupBySchoolYear(
  terms: readonly AcademicRecordTerm[],
): [string, AcademicRecordTerm[]][] {
  const groups = new Map<string, AcademicRecordTerm[]>()

  for (const term of terms) {
    const existing = groups.get(term.school_year)
    if (existing) existing.push(term)
    else groups.set(term.school_year, [term])
  }

  return [...groups.entries()].map(
    ([schoolYear, yearTerms]) =>
      [
        schoolYear,
        [...yearTerms].sort(
          (a, b) => semesterOrdinal(a.semester) - semesterOrdinal(b.semester),
        ),
      ] as [string, AcademicRecordTerm[]],
  )
}

/**
 * The student Grades screen: school-year/semester navigation (latest term
 * first, only terms with a recorded grade) driving one term's grade slip,
 * a Table/Tiles view toggle, and a button opening the full prospectus in a
 * dialog. `studentId` omitted means "my own" — shared by the student's own
 * Grades module and the Registrar's Academic Transcripts lookup.
 */
export function AcademicRecordView({ studentId }: { studentId?: number }) {
  const query = useAcademicRecordQuery(studentId)

  return (
    <AsyncBoundary
      query={query}
      loadingLabel="Loading grades…"
      loadingFallback={<Skeleton className="h-64" />}
    >
      {(record) => <AcademicRecordBody record={record} studentId={studentId} />}
    </AsyncBoundary>
  )
}

function AcademicRecordBody({
  record,
  studentId,
}: {
  record: AcademicRecord
  studentId?: number
}) {
  const [selectedTermId, setSelectedTermId] = useState<number | null>(null)
  const [view, setView] = useState<"table" | "tiles">("table")
  const [prospectusOpen, setProspectusOpen] = useState(false)

  const selectedTerm = record.terms.find(
    (term) => term.academic_term_id === selectedTermId,
  )
  const gradeSlipModalOpen = selectedTermId !== null
  const schoolYears = groupBySchoolYear(record.terms)

  function openTerm(termId: number) {
    setSelectedTermId(termId)
  }

  function closeGradeSlip() {
    setSelectedTermId(null)
  }

  return (
    <div className="grid min-w-0 gap-4">
      {/* Top bar: Prospectus + view toggle */}
      <div className="flex flex-wrap items-center justify-between gap-3 print:hidden">
        <p className="text-sm text-muted-foreground">
          Select a semester below to view its grade slip.
        </p>
        <div className="flex flex-wrap items-center gap-2">
          <Button
            type="button"
            variant="outline"
            onClick={() => setProspectusOpen(true)}
          >
            Prospectus
          </Button>
          {studentId === undefined && <StudentCreditMappingDialog />}
          <ToggleGroup
            type="single"
            value={view}
            onValueChange={(value) => {
              if (value === "table" || value === "tiles") setView(value)
            }}
            variant="outline"
            size="sm"
            aria-label="Grade slip layout"
          >
            <ToggleGroupItem value="table" aria-label="Table view">
              <ListIcon data-icon="inline-start" />
              Table
            </ToggleGroupItem>
            <ToggleGroupItem value="tiles" aria-label="Tile view">
              <LayoutGridIcon data-icon="inline-start" />
              Tiles
            </ToggleGroupItem>
          </ToggleGroup>
        </div>
      </div>

      {/* School years / semester selector */}
      <Card className="print:hidden">
        <CardHeader className="py-3">
          <CardTitle>School years</CardTitle>
          <CardDescription>Latest school year first — click a semester to view grades</CardDescription>
        </CardHeader>
        <CardContent className="grid gap-3 pb-3">
          {schoolYears.length === 0 && (
            <p className="text-sm text-muted-foreground">
              No grades have been recorded yet. Open the Prospectus above to
              see the subjects you still need to take.
            </p>
          )}
          {schoolYears.map(([schoolYear, terms]) => {
            const isActiveYear = terms.some(
              (term) => term.academic_term_id === selectedTermId,
            )

            return (
              <div
                key={schoolYear}
                className="grid gap-1.5 border-b border-border/60 pb-3 last:border-b-0 last:pb-0"
              >
                <p
                  className={
                    isActiveYear
                      ? "text-sm font-semibold text-foreground"
                      : "text-sm font-medium text-muted-foreground"
                  }
                >
                  {schoolYear}
                </p>
                <div className="grid grid-cols-2 gap-1.5">
                  {terms.map((term) => (
                    <Button
                      key={term.academic_term_id}
                      type="button"
                      size="sm"
                      variant={
                        term.academic_term_id === selectedTermId
                          ? "default"
                          : "outline"
                      }
                      aria-pressed={term.academic_term_id === selectedTermId}
                      onClick={() => openTerm(term.academic_term_id)}
                    >
                      {term.semester}
                    </Button>
                  ))}
                </div>
              </div>
            )
          })}
        </CardContent>
      </Card>

      {/* Grade Slip Modal */}
      <Dialog open={gradeSlipModalOpen} onOpenChange={(open) => { if (!open) closeGradeSlip() }}>
        <DialogContent className="grid max-h-[92dvh] grid-rows-[auto_minmax(0,1fr)] gap-0 overflow-hidden p-0 sm:max-w-5xl">
          <DialogHeader className="px-4 pt-4 pr-12 sm:px-6 sm:pt-6 sm:pr-14">
            <DialogTitle>
              Grade Slip {selectedTerm ? `— ${selectedTerm.term_label}` : ""}
            </DialogTitle>
            <DialogDescription>
              {record.student_number} · {record.program_name} ({record.program_code})
            </DialogDescription>
          </DialogHeader>
          <div className="min-h-0 overflow-y-auto px-4 py-4 sm:px-6">
            {selectedTerm && (
              view === "table" ? (
                <GradeSlipDocument slip={toGradeSlip(record, selectedTerm)} />
              ) : (
                <GradeTiles slip={toGradeSlip(record, selectedTerm)} />
              )
            )}
          </div>
        </DialogContent>
      </Dialog>

      {/* Prospectus Modal */}
      <Dialog open={prospectusOpen} onOpenChange={setProspectusOpen}>
        <DialogContent className="grid max-h-[90dvh] grid-rows-[auto_minmax(0,1fr)] gap-0 overflow-hidden p-0 sm:max-w-5xl">
          <DialogHeader className="px-4 pt-4 pr-12 sm:px-6 sm:pt-6 sm:pr-14">
            <DialogTitle>Prospectus</DialogTitle>
            <DialogDescription>
              {record.student_number} · {record.program_name}
            </DialogDescription>
          </DialogHeader>
          <div className="min-h-0 overflow-y-auto px-4 py-4 sm:px-6">
            <ProspectusDocument studentId={studentId} />
          </div>
        </DialogContent>
      </Dialog>
    </div>
  )
}

/** The Tiles alternative to `GradeSlipDocument`'s table — same data, same print region. */
function GradeTiles({ slip }: { slip: GradeSlip }) {
  const rows = groupPairedSubjects(slip.rows)

  return (
    <PrintDocument
      title={`Grade slip — ${slip.term_label}`}
      actions={<PrintButton label="Print Grade" />}
    >
      <div className="mb-3 grid gap-1 text-sm">
        <p>
          <strong>{slip.student_number}</strong> · {slip.program_name} (
          {slip.program_code})
        </p>
        <p>
          {formatYearLevel(slip.year_level)}
          {slip.enrollment_category_label
            ? ` · ${slip.enrollment_category_label}`
            : ""}{" "}
          · {slip.term_label}
        </p>
      </div>
      <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
        {rows.map((row) => {
          const tone = markTone(row.mark)

          return (
            <Card
              key={row.academic_grade_id}
              className={cn("print:break-inside-avoid", markToneRowClass(tone))}
            >
              <CardHeader>
                <CardTitle level={3}>{row.code}</CardTitle>
                <CardDescription>
                  {row.title} · {row.units} units
                </CardDescription>
              </CardHeader>
              <CardContent className="grid gap-2">
                <div className="flex items-center gap-2">
                  <Badge variant={markToneBadgeVariant(tone)}>
                    {row.mark ?? "—"}
                  </Badge>
                  <span className="text-sm text-muted-foreground">
                    {row.mark_label ?? "—"}
                  </span>
                </div>
                <p className="text-sm text-muted-foreground">
                  {row.section_code ?? "—"} · {row.professor_name ?? "—"}
                </p>
              </CardContent>
            </Card>
          )
        })}
      </div>
      <div className="mt-3 flex flex-wrap items-center justify-between gap-2 text-sm font-medium">
        <span>Total academic units: {slip.total_academic_units}</span>
        <span>GWA: {slip.gpa ?? "—"}</span>
      </div>
    </PrintDocument>
  )
}
