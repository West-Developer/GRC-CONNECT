"use client"

import {
  PrintButton,
  PrintDocument,
} from "@/features/components/portal/print-document"
import { Badge } from "@/features/components/ui/badge"
import {
  Table,
  TableBody,
  TableCaption,
  TableCell,
  TableFooter,
  TableHead,
  TableHeader,
  TableRow,
} from "@/features/components/ui/table"
import {
  markTone,
  markToneBadgeVariant,
} from "@/features/lib/grade-presentation"
import { formatYearLevel } from "@/features/lib/format-year-level"
import { groupPairedSubjects } from "@/features/lib/group-paired-subjects"
import type { GradeSlip } from "@/features/schemas/academic-record-schema"

/**
 * A single term's printable grade slip: CODE | SUBJECT DESCRIPTION | UNITS |
 * FINAL | REMARKS | SECTION | PROFESSOR | SIGNATURE, matching the
 * institution's reference form, with TOTAL ACADEMIC UNITS and GWA below.
 * Presentational — the caller (`AcademicRecordView`) owns fetching and
 * picking which term's slip this is, so the same component renders both
 * the student's own grade slip and the Registrar's transcript lookup.
 */
export function GradeSlipDocument({ slip }: { slip: GradeSlip }) {
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
      <div className="w-full min-w-0 overflow-x-auto rounded-lg border">
        <Table className="caption-top" data-stack-mobile>
          <TableCaption className="text-left px-3 py-2 text-xs text-muted-foreground w-full">
            Grade slip for {slip.term_label}
          </TableCaption>
          <TableHeader>
            <TableRow>
              <TableHead scope="col" className="whitespace-nowrap">Code</TableHead>
              <TableHead scope="col" className="min-w-[12rem]">Subject description</TableHead>
              <TableHead scope="col" className="whitespace-nowrap text-center">Units</TableHead>
              <TableHead scope="col" className="whitespace-nowrap">Final</TableHead>
              <TableHead scope="col" className="whitespace-nowrap">Remarks</TableHead>
              <TableHead scope="col" className="whitespace-nowrap">Section</TableHead>
              <TableHead scope="col" className="whitespace-nowrap">Professor</TableHead>
              <TableHead scope="col" className="whitespace-nowrap hidden print:table-cell">Signature</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {rows.map((row) => (
              <TableRow key={row.academic_grade_id}>
                <TableCell data-stack="full" className="font-mono whitespace-nowrap font-medium">{row.code}</TableCell>
                <TableCell data-stack="full" className="min-w-[12rem]">{row.title}</TableCell>
                <TableCell data-label="Units" className="text-center whitespace-nowrap">{row.units}</TableCell>
                <TableCell data-label="Final" className="whitespace-nowrap">
                  <Badge variant={markToneBadgeVariant(markTone(row.mark))}>
                    {row.mark ?? "—"}
                  </Badge>
                </TableCell>
                <TableCell data-label="Remarks" className="whitespace-nowrap">{row.mark_label ?? "—"}</TableCell>
                <TableCell data-label="Section" className="whitespace-nowrap">{row.section_code ?? "—"}</TableCell>
                <TableCell data-label="Professor" data-stack="full" className="whitespace-nowrap">{row.professor_name ?? "—"}</TableCell>
                <TableCell className="signature-cell whitespace-nowrap hidden print:table-cell" />
              </TableRow>
            ))}
          </TableBody>
          <TableFooter>
            <TableRow>
              <TableCell colSpan={2}>Total academic units</TableCell>
              <TableCell colSpan={6}>{slip.total_academic_units}</TableCell>
            </TableRow>
            <TableRow>
              <TableCell colSpan={2}>GWA</TableCell>
              <TableCell colSpan={6}>{slip.gpa ?? "—"}</TableCell>
            </TableRow>
          </TableFooter>
        </Table>
      </div>
      {slip.excluded_from_gpa_count > 0 && (
        <p className="mt-2 text-xs text-muted-foreground">
          {slip.excluded_from_gpa_count} subject
          {slip.excluded_from_gpa_count === 1 ? "" : "s"}{" "}
          {slip.excluded_from_gpa_count === 1 ? "is" : "are"} excluded from the
          GWA above because of a completion mark or the NSTP/PATHFIT/PE rule.
        </p>
      )}
    </PrintDocument>
  )
}
