"use client"

import { useEffect, useId, useRef } from "react"

import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/features/components/ui/dialog"
import {
  chedStudentName,
  parseChedSubjects,
} from "@/features/lib/ched-report-display"
import type {
  ChedReportInfo,
  ChedReportRow,
} from "@/features/schemas/ched-report-schema"

/** Long values take two of the three columns; the subjects have a list of their own. */
const WIDE_FIELDS = new Set(["program", "course", "street_barangay", "email"])
const SUBJECTS_KEY = "subjects"

/**
 * One student's row of a CHED / NSTP report, in full: every field that goes into the file, under the
 * template's own labels. The dialog is wide and laid out in columns so that all of it is on screen at
 * once; the dialog's own scroll only comes into play on a screen too small to hold it.
 */
export function ChedStudentDetailsDialog({
  row,
  report,
  onClose,
}: {
  row: ChedReportRow | null
  report: ChedReportInfo
  onClose: () => void
}) {
  const subjectsHeadingId = useId()
  const triggerRef = useRef<HTMLElement | null>(null)

  useEffect(() => {
    if (row !== null && document.activeElement instanceof HTMLElement) {
      triggerRef.current = document.activeElement
    }
  }, [row])

  return (
    <Dialog
      open={row !== null}
      onOpenChange={(open) => {
        if (!open) onClose()
      }}
    >
      <DialogContent
        className="gap-3 sm:max-w-5xl"
        onCloseAutoFocus={(event) => {
          if (triggerRef.current) {
            event.preventDefault()
            triggerRef.current.focus()
          }
        }}
      >
        {row !== null && (
          <>
            <DialogHeader>
              <DialogTitle>{chedStudentName(row)}</DialogTitle>
              <DialogDescription>
                {`${report.title} · ${report.term_label}`}
              </DialogDescription>
            </DialogHeader>
            <dl className="grid gap-x-6 gap-y-3 sm:grid-cols-3 lg:grid-cols-4">
              {report.columns
                .filter((column) => column.key !== SUBJECTS_KEY)
                .map((column) => {
                  const value = row[column.key]
                  const text =
                    value === undefined || value === "" ? "—" : String(value)

                  return (
                    <div
                      key={column.key}
                      className={
                        WIDE_FIELDS.has(column.key)
                          ? "sm:col-span-2"
                          : undefined
                      }
                    >
                      <dt className="text-xs text-muted-foreground">
                        {column.label}
                      </dt>
                      <dd className="font-medium break-words">{text}</dd>
                    </div>
                  )
                })}
            </dl>
            <SubjectsList
              row={row}
              report={report}
              headingId={subjectsHeadingId}
            />
          </>
        )}
      </DialogContent>
    </Dialog>
  )
}

function SubjectsList({
  row,
  report,
  headingId,
}: {
  row: ChedReportRow
  report: ChedReportInfo
  headingId: string
}) {
  const column = report.columns.find(
    (candidate) => candidate.key === SUBJECTS_KEY,
  )
  if (column === undefined) return null

  const subjects = parseChedSubjects(String(row[SUBJECTS_KEY] ?? ""))

  return (
    <div className="grid gap-1.5">
      <h3 id={headingId} className="text-xs text-muted-foreground">
        {column.label}
      </h3>
      {subjects.length === 0 ? (
        <p className="font-medium">—</p>
      ) : (
        <ul
          aria-labelledby={headingId}
          className="grid gap-x-6 gap-y-1 sm:grid-cols-2"
        >
          {subjects.map((subject, index) => (
            <li
              key={`${index}-${subject.title}`}
              className="flex items-baseline justify-between gap-3 border-b py-0.5"
            >
              <span>{subject.title}</span>
              {subject.units !== "" && (
                <span className="tabular-nums text-muted-foreground">
                  {subject.units}
                </span>
              )}
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
