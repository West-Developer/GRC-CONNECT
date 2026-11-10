"use client"

import { ArrowLeft, BookOpenText, ChevronRight, UserCheck } from "lucide-react"
import { useState } from "react"

import { AcademicRecordView } from "@/features/components/portal/academic-record-view"
import { AsyncBoundary } from "@/features/components/portal/async-boundary"
import { DataTable } from "@/features/components/portal/data-table"
import { Paginator } from "@/features/components/portal/paginator"
import { Badge } from "@/features/components/ui/badge"
import { Button } from "@/features/components/ui/button"
import {
  useAcademicGradesQuery,
  useGradeApprovalSectionsQuery,
} from "@/features/hooks/use-academic-grades"
import { gradeBadgeVariant } from "@/features/lib/grade-presentation"
import type {
  AcademicGrade,
  GradeApprovalProfessor,
  GradeApprovalSection,
} from "@/features/schemas/academic-grade-schema"

/** The text, or null when it is missing or empty (an empty name is no name). */
function filled(value: string | null | undefined): string | null {
  return value !== undefined && value !== null && value !== "" ? value : null
}

function sectionKey(section: GradeApprovalSection): string {
  return `${section.subject_id}_${section.section_id ?? "none"}`
}

interface StudentTarget {
  studentId: number
  name: string
  number: string
}

/**
 * The Registrar Head's grade approvals as a drill-down (stakeholder Doc 14):
 * professors, then the subjects/sections of one professor, then the students
 * of one section with the Lock action, then one student's grades.
 *
 * Each level asks the server for just what it shows. The professors come
 * already grouped, with their real totals and their own paging, so a professor
 * never splits across pages and the page count is a count of professors (it was
 * once built from one page of the flat grade list). The department filter stays
 * with the page, which passes it down and remounts this component when it changes.
 * Locking a grade refreshes every level, and a level that empties steps back.
 */
export function GradeApprovalsDrilldown({
  professors,
  page,
  lastPage,
  onPageChange,
  department,
  lockingGradeId,
  onLock,
}: {
  professors: readonly GradeApprovalProfessor[]
  page: number
  lastPage: number
  onPageChange: (page: number) => void
  /** "all" or a college id; limits the lower levels the same way as the professors. */
  department: string
  lockingGradeId: number | null
  onLock: (grade: AcademicGrade) => void
}) {
  const [professor, setProfessor] = useState<GradeApprovalProfessor | null>(
    null,
  )

  if (professor) {
    return (
      <ProfessorDrilldown
        professor={professor}
        college={department === "all" ? undefined : department}
        lockingGradeId={lockingGradeId}
        onLock={onLock}
        onBack={() => setProfessor(null)}
      />
    )
  }

  return (
    <div className="grid gap-4">
      {professors.length === 0 && (
        <p className="text-sm text-muted-foreground">
          No more professors on this page — every grade here has been locked.
        </p>
      )}
      <ul className="grid gap-2 sm:grid-cols-2">
        {professors.map((item) => (
          <li key={item.professor_id}>
            <DrillRow
              onClick={() => setProfessor(item)}
              title={filled(item.professor_name) ?? "Unassigned Faculty"}
              detail={`${item.college ? item.college.toUpperCase() : "Faculty"} · ${item.subject_count} subject(s) submitted`}
              count={`${item.grade_count} grade(s) awaiting lock`}
            />
          </li>
        ))}
      </ul>
      <Paginator
        currentPage={page}
        lastPage={lastPage}
        onPageChange={onPageChange}
      />
    </div>
  )
}

function ProfessorDrilldown({
  professor,
  college,
  lockingGradeId,
  onLock,
  onBack,
}: {
  professor: GradeApprovalProfessor
  college: string | undefined
  lockingGradeId: number | null
  onLock: (grade: AcademicGrade) => void
  onBack: () => void
}) {
  const [subjectKey, setSubjectKey] = useState<string | null>(null)
  const [student, setStudent] = useState<StudentTarget | null>(null)
  const professorName = filled(professor.professor_name) ?? "Unassigned Faculty"

  const sectionsQuery = useGradeApprovalSectionsQuery({
    professor_id: professor.professor_id,
    college,
  })
  const sections = sectionsQuery.data
  const section = sections?.find((item) => sectionKey(item) === subjectKey)

  // Level 4: one student's grades.
  if (section && student) {
    return (
      <div className="grid gap-4">
        <BackButton onClick={() => setStudent(null)}>
          Back to students
        </BackButton>
        <div className="rounded-lg border bg-card p-3">
          <p className="font-semibold">{student.name}</p>
          <p className="font-mono text-xs text-muted-foreground">
            {student.number}
          </p>
        </div>
        <AcademicRecordView studentId={student.studentId} />
      </div>
    )
  }

  // Level 3: the students of one section, with Lock.
  if (section) {
    return (
      <SectionGrades
        professorName={professorName}
        professorId={professor.professor_id}
        college={college}
        section={section}
        lockingGradeId={lockingGradeId}
        onLock={onLock}
        onBack={() => setSubjectKey(null)}
        onOpenStudent={setStudent}
      />
    )
  }

  // Level 2: the subjects of one professor.
  return (
    <div className="grid gap-4">
      <BackButton onClick={onBack}>Back to professors</BackButton>
      <div className="flex flex-wrap items-center gap-2">
        <UserCheck className="size-4 text-primary" aria-hidden />
        <h3 className="text-base font-semibold">{professorName}</h3>
        {sections && (
          <Badge variant="secondary">
            {sections.reduce((total, item) => total + item.grade_count, 0)}{" "}
            grade(s) awaiting lock
          </Badge>
        )}
      </div>
      <AsyncBoundary
        query={sectionsQuery}
        isEmpty={(rows) => rows.length === 0}
        emptyMessage="Every submitted grade of this professor has been locked."
        loadingLabel="Loading this professor's subjects…"
      >
        {(rows) => (
          <ul className="grid gap-2 sm:grid-cols-2">
            {rows.map((item) => (
              <li key={sectionKey(item)}>
                <DrillRow
                  onClick={() => setSubjectKey(sectionKey(item))}
                  title={`${item.subject_code} — ${filled(item.subject_title) ?? item.subject_code}`}
                  detail={
                    item.section_code
                      ? `Section ${item.section_code}`
                      : "No section recorded"
                  }
                  count={`${item.grade_count} student(s)`}
                />
              </li>
            ))}
          </ul>
        )}
      </AsyncBoundary>
    </div>
  )
}

function SectionGrades({
  professorName,
  professorId,
  college,
  section,
  lockingGradeId,
  onLock,
  onBack,
  onOpenStudent,
}: {
  professorName: string
  professorId: number
  college: string | undefined
  section: GradeApprovalSection
  lockingGradeId: number | null
  onLock: (grade: AcademicGrade) => void
  onBack: () => void
  onOpenStudent: (student: StudentTarget) => void
}) {
  // A section's own grades; a grade with no section (a transferred record) is
  // found by its subject and professor instead.
  const gradesQuery = useAcademicGradesQuery({
    status: "submitted",
    college,
    per_page: 500,
    ...(section.section_id !== null
      ? { section_id: section.section_id }
      : { subject_id: section.subject_id, professor_id: professorId }),
  })

  return (
    <div className="grid gap-4">
      <BackButton onClick={onBack}>
        Back to {professorName}&apos;s subjects
      </BackButton>
      <div className="flex flex-wrap items-center gap-2">
        <BookOpenText className="size-4 text-primary" aria-hidden />
        <h3 className="text-sm font-semibold">
          {section.subject_code} —{" "}
          {filled(section.subject_title) ?? section.subject_code}
        </h3>
        {section.section_code && (
          <Badge variant="outline">Section {section.section_code}</Badge>
        )}
        <span className="text-xs text-muted-foreground">
          {gradesQuery.data?.meta.total ?? section.grade_count} student(s)
          awaiting lock
        </span>
      </div>
      <AsyncBoundary
        query={{ ...gradesQuery, data: gradesQuery.data?.data }}
        isEmpty={(rows) => rows.length === 0}
        emptyMessage="Every submitted grade in this section has been locked."
        loadingLabel="Loading the students' grades…"
      >
        {(grades) => (
          <DataTable
            caption="Submitted grades awaiting lock"
            rowKey={(grade) => grade.id}
            rows={grades}
            columns={[
              {
                key: "student",
                header: "Student",
                render: (grade) => (
                  <button
                    type="button"
                    className="flex flex-col text-left hover:underline"
                    onClick={() =>
                      onOpenStudent({
                        studentId: grade.student_id,
                        name:
                          filled(grade.student_name) ?? grade.student_number,
                        number: grade.student_number,
                      })
                    }
                  >
                    <span className="font-medium text-foreground">
                      {filled(grade.student_name) ?? grade.student_number}
                    </span>
                    {grade.student_name && (
                      <span className="font-mono text-xs text-muted-foreground">
                        {grade.student_number}
                      </span>
                    )}
                  </button>
                ),
              },
              {
                key: "grade",
                header: "Grade",
                render: (grade) => (
                  <span className="font-semibold tabular-nums">
                    {grade.final_grade ?? grade.mark ?? "—"}
                  </span>
                ),
              },
              {
                key: "mark",
                header: "Mark",
                render: (grade) => grade.mark_label ?? grade.mark ?? "—",
              },
              {
                key: "status",
                header: "Status",
                render: (grade) => (
                  <Badge variant={gradeBadgeVariant(grade.status)}>
                    {grade.status_label}
                  </Badge>
                ),
              },
              {
                key: "actions",
                header: "Actions",
                render: (grade) => (
                  <Button
                    type="button"
                    size="sm"
                    disabled={lockingGradeId === grade.id}
                    onClick={() => onLock(grade)}
                  >
                    Lock
                  </Button>
                ),
              },
            ]}
          />
        )}
      </AsyncBoundary>
    </div>
  )
}

function BackButton({
  onClick,
  children,
}: {
  onClick: () => void
  children: React.ReactNode
}) {
  return (
    <Button
      type="button"
      variant="ghost"
      size="sm"
      className="w-fit gap-1.5"
      onClick={onClick}
    >
      <ArrowLeft className="size-4" aria-hidden />
      {children}
    </Button>
  )
}

function DrillRow({
  title,
  detail,
  count,
  onClick,
}: {
  title: string
  detail: string
  count: string
  onClick: () => void
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      className="flex w-full items-center justify-between gap-3 rounded-lg border bg-card p-3 text-left transition-colors hover:border-primary/60 hover:bg-muted/30"
    >
      <span className="grid gap-0.5">
        <span className="text-sm font-semibold">{title}</span>
        <span className="text-xs text-muted-foreground">{detail}</span>
      </span>
      <span className="flex shrink-0 items-center gap-2">
        <Badge variant="secondary">{count}</Badge>
        <ChevronRight className="size-4 text-muted-foreground" aria-hidden />
      </span>
    </button>
  )
}
