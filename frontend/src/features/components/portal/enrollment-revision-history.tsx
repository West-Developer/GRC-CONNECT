"use client"

import { Badge } from "@/features/components/ui/badge"
import type { Enrollment } from "@/features/schemas/enrollment-schema"

type Revision = Enrollment["revisions"][number]
type RevisionSubject = Revision["added_subjects"][number]

const changedOn = new Intl.DateTimeFormat("en-PH", {
  dateStyle: "medium",
  timeStyle: "short",
  timeZone: "Asia/Manila",
})

function statusLabel(revision: Revision, audience: "student" | "staff") {
  if (revision.proposed_by_role === null) {
    return `${revision.status === "pending" ? "Pending" : revision.status === "accepted" ? "Accepted" : revision.status === "declined" ? "Not accepted" : "Superseded"} — author unavailable`
  }
  const studentProposal = revision.proposed_by_role === "student"
  if (revision.status === "superseded") return "Revised by the Program Chair"
  if (revision.status === "accepted")
    return studentProposal
      ? "Accepted by the Program Chair"
      : "Accepted by the student"
  if (revision.status === "declined")
    return studentProposal
      ? "Not accepted by the Program Chair"
      : "Not accepted by the student"
  if (studentProposal) return "Waiting for the Program Chair"
  return audience === "student"
    ? "Waiting for your answer"
    : "Waiting for the student"
}

function statusVariant(status: Revision["status"]) {
  if (status === "accepted") return "secondary" as const
  if (status === "declined") return "destructive" as const
  return "outline" as const
}

function SubjectList({
  title,
  subjects,
}: {
  title: string
  subjects: readonly RevisionSubject[]
}) {
  if (subjects.length === 0) return null

  return (
    <div className="grid gap-1">
      <p className="text-xs font-medium text-muted-foreground">{title}</p>
      <ul className="grid gap-1 text-sm">
        {subjects.map((subject) => (
          <li key={subject.section_id}>
            <span className="font-medium">{subject.subject_code}</span> —{" "}
            {subject.subject_title}
            {subject.section_code
              ? ` (Section ${subject.section_code})`
              : ""} · {subject.units} units
          </li>
        ))}
      </ul>
    </div>
  )
}

/**
 * The Program Chair's changes to a student's subjects, newest first: what was
 * added and removed, why, and what the student answered (with their reason
 * when they did not accept). Shown to the student, who answers it, and to the
 * Program Chair and Registrar, who need to see why the schedule differs from
 * what the student first picked (ADR 0040).
 */
export function EnrollmentRevisionHistory({
  revisions,
  audience,
}: {
  revisions: readonly Revision[]
  audience: "student" | "staff"
}) {
  if (revisions.length === 0) return null

  const newestFirst = [...revisions].reverse()

  return (
    <section aria-label="Changes to the subjects" className="grid gap-3">
      <h3 className="text-sm font-semibold">
        {audience === "student"
          ? "Subject change history"
          : "Changes made to the student's subjects"}
      </h3>
      <ol className="grid gap-3">
        {newestFirst.map((revision) => (
          <li
            key={revision.id}
            className="grid gap-3 rounded-lg border bg-muted/20 p-3"
          >
            <div className="flex flex-wrap items-center justify-between gap-2">
              <span className="text-xs text-muted-foreground">
                {revision.proposed_at
                  ? changedOn.format(new Date(revision.proposed_at))
                  : ""}
                {` · ${revision.units_before} → ${revision.units_after} units`}
              </span>
              <Badge variant={statusVariant(revision.status)}>
                {statusLabel(revision, audience)}
              </Badge>
            </div>
            <div className="grid gap-1">
              <p className="text-xs font-medium text-muted-foreground">
                {revision.proposed_by_role === "student"
                  ? audience === "student"
                    ? "Your reason"
                    : "Student's reason"
                  : revision.proposed_by_role === null
                    ? "Reason"
                    : audience === "student"
                      ? "Why your Program Chair changed it"
                      : "Program Chair's reason"}
              </p>
              <p className="text-sm">{revision.note}</p>
            </div>
            <SubjectList title="Added" subjects={revision.added_subjects} />
            <SubjectList title="Removed" subjects={revision.removed_subjects} />
            {revision.status === "declined" && revision.student_reason && (
              <div className="grid gap-1 rounded-md border border-destructive/30 bg-destructive/5 p-2">
                <p className="text-xs font-medium text-destructive">
                  {audience === "student"
                    ? "Why you did not accept"
                    : "Why the student did not accept"}
                </p>
                <p className="text-sm">{revision.student_reason}</p>
              </div>
            )}
          </li>
        ))}
      </ol>
    </section>
  )
}
