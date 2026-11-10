"use client"

import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/features/components/ui/dialog"
import type { FacultyLoadMember } from "@/features/schemas/schedule-generation-schema"

export interface FacultyLoadAssignmentsTarget {
  professorName: string
  totalUnits: number
  assignments: FacultyLoadMember["assignments"]
}

/**
 * What one professor is teaching this term — opened by clicking their name on
 * the Dean's Faculty Load page, so "how many units" always has "which
 * subjects" one click away.
 */
export function FacultyLoadAssignmentsDialog({
  target,
  onOpenChange,
}: {
  target: FacultyLoadAssignmentsTarget | null
  onOpenChange: (open: boolean) => void
}) {
  return (
    <Dialog open={target !== null} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[100dvh] overflow-y-auto rounded-none sm:max-h-[90dvh] sm:max-w-lg sm:rounded-xl">
        {target && (
          <>
            <DialogHeader>
              <DialogTitle>{target.professorName}</DialogTitle>
              <DialogDescription>
                {target.assignments.length}{" "}
                {target.assignments.length === 1 ? "section" : "sections"} ·{" "}
                {target.totalUnits} units this term
              </DialogDescription>
            </DialogHeader>
            {target.assignments.length === 0 ? (
              <p className="rounded-lg border border-dashed p-5 text-sm text-muted-foreground">
                No sections are assigned to this professor this term.
              </p>
            ) : (
              <ul className="grid gap-2">
                {target.assignments.map((assignment) => (
                  <li
                    key={assignment.section_id}
                    className="rounded-md border p-3 text-sm"
                  >
                    <p className="font-medium">
                      {assignment.subject_code} · {assignment.subject_title}
                    </p>
                    <p className="text-muted-foreground">
                      Section {assignment.section_code} · {assignment.units}{" "}
                      units
                      {assignment.schedule_days
                        ? ` · ${assignment.schedule_days}`
                        : ""}
                      {assignment.starts_at_time && assignment.ends_at_time
                        ? ` · ${assignment.starts_at_time}–${assignment.ends_at_time}`
                        : ""}
                      {assignment.room ? ` · ${assignment.room}` : ""}
                    </p>
                  </li>
                ))}
              </ul>
            )}
          </>
        )}
      </DialogContent>
    </Dialog>
  )
}
