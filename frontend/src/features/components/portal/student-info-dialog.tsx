"use client"

import { PrerequisiteWaiverSection } from "@/features/components/portal/prerequisite-waiver-section"
import { Alert, AlertDescription } from "@/features/components/ui/alert"
import { Badge } from "@/features/components/ui/badge"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/features/components/ui/dialog"
import { Skeleton } from "@/features/components/ui/skeleton"
import { useRegistrarStudentProfileQuery } from "@/features/hooks/use-student-records"
import { formatYearLevel } from "@/features/lib/format-year-level"

interface StudentInfoDialogProps {
  /** The student profile to show; `null` keeps the dialog closed. */
  studentId: number | null
  /**
   * The term of the enrollment under review. When given, the dialog also
   * shows the student's prerequisite waivers for that term (ADR 0031).
   */
  academicTermId?: number | null
  onOpenChange: (open: boolean) => void
}

/**
 * The Registrar Head's quick look at one student while reviewing an
 * enrollment (stakeholder Doc 14): who the student is and where they stand,
 * without leaving the approval queue. Read-only; the API only answers for the
 * Registrar Head.
 */
export function StudentInfoDialog({
  studentId,
  academicTermId = null,
  onOpenChange,
}: StudentInfoDialogProps) {
  const query = useRegistrarStudentProfileQuery(studentId)
  const student = query.data

  return (
    <Dialog open={studentId !== null} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[85dvh] w-[calc(100vw-2rem)] overflow-y-auto sm:max-w-xl">
        <DialogHeader>
          <DialogTitle>{student?.name ?? "Student information"}</DialogTitle>
          <DialogDescription>
            {student
              ? `Student No. ${student.student_number}`
              : "Profile details for the enrollment under review."}
          </DialogDescription>
        </DialogHeader>

        {query.isPending && studentId !== null ? (
          <div
            className="grid gap-3"
            role="status"
            aria-label="Loading student information"
          >
            <Skeleton className="h-5 w-2/3" />
            <Skeleton className="h-5 w-1/2" />
            <Skeleton className="h-5 w-3/5" />
          </div>
        ) : query.isError ? (
          <Alert variant="destructive">
            <AlertDescription>
              The student information could not be loaded. Close this window and
              try again.
            </AlertDescription>
          </Alert>
        ) : student ? (
          <dl className="grid gap-3 text-sm sm:grid-cols-[10rem_1fr]">
            <dt className="text-muted-foreground">Program</dt>
            <dd>
              {student.program_code} — {student.program_name}
            </dd>
            <dt className="text-muted-foreground">Curriculum</dt>
            <dd>
              {student.curriculum_name} (
              {student.curriculum_effective_school_year})
            </dd>
            <dt className="text-muted-foreground">Year level</dt>
            <dd>{formatYearLevel(student.year_level)}</dd>
            <dt className="text-muted-foreground">Student type</dt>
            <dd>{student.student_type_label ?? "—"}</dd>
            <dt className="text-muted-foreground">Enrollment category</dt>
            <dd className="capitalize">
              {student.enrollment_category ?? "Not classified"}
            </dd>
            <dt className="text-muted-foreground">Academic standing</dt>
            <dd>{student.academic_standing_label}</dd>
            <dt className="text-muted-foreground">Admission status</dt>
            <dd>{student.admission_status_label}</dd>
            <dt className="text-muted-foreground">Financial status</dt>
            <dd>
              {student.financial_status_label ? (
                <Badge variant="secondary">
                  {student.financial_status_label}
                </Badge>
              ) : (
                "—"
              )}
            </dd>
            <dt className="text-muted-foreground">Email</dt>
            <dd className="break-all">{student.email}</dd>
          </dl>
        ) : null}

        {student && studentId !== null && academicTermId !== null && (
          <PrerequisiteWaiverSection
            studentId={studentId}
            academicTermId={academicTermId}
          />
        )}
      </DialogContent>
    </Dialog>
  )
}
