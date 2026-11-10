"use client"

import { ChevronRight } from "lucide-react"
import { useState } from "react"

import {
  DepartmentsPanel,
  SectionsPanel,
  StudentDetailPanel,
  StudentsPanel,
} from "@/features/components/portal/enrollment-drilldown-panels"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/features/components/ui/dialog"
import { ENROLLMENT_GROUP_PRESENTATION } from "@/features/lib/enrollment-status-groups"
import type {
  EnrollmentStatusGroup,
  EnrollmentStatusOverview,
} from "@/features/schemas/dashboard-schema"

/** Where a drill-down opens: a group across all departments, or one department. */
export interface DrilldownStart {
  group: EnrollmentStatusGroup | null
  department: string | null
}

interface Path {
  department: string | null
  /** `code: null` is the "No section yet" row; `section: null` means none chosen yet. */
  section: { code: string | null } | null
  student: { id: number; name: string } | null
}

const ROOT: Path = { department: null, section: null, student: null }

interface Crumb {
  key: string
  label: string
  /** Absent for the current (last) crumb. */
  onSelect?: () => void
}

/**
 * The drill-down behind the Enrollment Dashboard: overall → department →
 * section → student → that student's enrollment. Every step back is a
 * breadcrumb button. Departments and sections are aggregate counts; the last
 * two levels are the audited, read-only student level (ADR 0024).
 */
export function EnrollmentDrilldownDialog({
  open,
  onOpenChange,
  start,
  overview,
  academicTermId,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  start: DrilldownStart | null
  overview: EnrollmentStatusOverview
  academicTermId?: number
}) {
  const group = start?.group ?? null
  const [path, setPath] = useState<Path>(ROOT)

  // Reset to where the caller asked to start whenever the dialog is opened
  // for a new target — adjusted during render, not in an effect, so a stale
  // path never commits (and never fetches).
  const scope =
    open && start ? `${start.group ?? ""}|${start.department ?? ""}` : ""
  const [pathScope, setPathScope] = useState(scope)
  if (pathScope !== scope) {
    setPathScope(scope)
    setPath(open && start ? { ...ROOT, department: start.department } : ROOT)
  }

  const groupLabel = group ? ENROLLMENT_GROUP_PRESENTATION[group].label : null
  const departmentLabel =
    overview.departments.find((item) => item.department === path.department)
      ?.label ?? path.department

  const crumbs: Crumb[] = [
    {
      key: "overall",
      label: groupLabel ? `Overall · ${groupLabel}` : "Overall",
      onSelect: () => setPath(ROOT),
    },
  ]
  if (path.department !== null) {
    crumbs.push({
      key: "department",
      label: departmentLabel ?? "Department",
      onSelect: () => setPath({ ...ROOT, department: path.department }),
    })
  }
  if (path.section !== null) {
    crumbs.push({
      key: "section",
      label: path.section.code ?? "No section yet",
      onSelect: () => setPath({ ...path, student: null }),
    })
  }
  if (path.student !== null) {
    crumbs.push({ key: "student", label: path.student.name })
  }

  const description =
    path.student !== null
      ? "Read-only enrollment details for this student. Opening a student record is logged."
      : path.section !== null
        ? "Choose a student to see their enrollment. Opening a student record is logged."
        : path.department !== null
          ? "Choose a section to see the students in it."
          : "Choose a department to see its sections."

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90dvh] w-[calc(100vw-2rem)] overflow-y-auto sm:max-w-4xl">
        <DialogHeader>
          <DialogTitle>
            {groupLabel
              ? `Enrollment status · ${groupLabel}`
              : "Enrollment status"}
          </DialogTitle>
          <DialogDescription>{description}</DialogDescription>
        </DialogHeader>

        <nav aria-label="Drill-down path">
          <ol className="flex flex-wrap items-center gap-1 text-sm">
            {crumbs.map((crumb, index) => (
              <li key={crumb.key} className="flex items-center gap-1">
                {index > 0 && (
                  <ChevronRight
                    className="size-3.5 text-muted-foreground"
                    aria-hidden="true"
                  />
                )}
                {crumb.onSelect ? (
                  <button
                    type="button"
                    onClick={crumb.onSelect}
                    className="cursor-pointer rounded-sm text-muted-foreground underline-offset-4 hover:text-foreground hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                  >
                    {crumb.label}
                  </button>
                ) : (
                  <span aria-current="page" className="font-medium">
                    {crumb.label}
                  </span>
                )}
              </li>
            ))}
          </ol>
        </nav>

        {path.student !== null ? (
          <StudentDetailPanel
            key={path.student.id}
            studentProfileId={path.student.id}
            academicTermId={academicTermId}
          />
        ) : path.section !== null && path.department !== null ? (
          <StudentsPanel
            key={`${path.department}|${path.section.code ?? ""}|${group ?? ""}`}
            department={path.department}
            sectionCode={path.section.code}
            group={group}
            academicTermId={academicTermId}
            onSelect={(student) =>
              setPath({
                ...path,
                student: {
                  id: student.student_profile_id,
                  name: student.student_name,
                },
              })
            }
          />
        ) : path.department !== null ? (
          <SectionsPanel
            department={path.department}
            academicTermId={academicTermId}
            group={group}
            onSelect={(code) =>
              setPath({ ...path, section: { code }, student: null })
            }
          />
        ) : (
          <DepartmentsPanel
            departments={overview.departments}
            group={group}
            onSelect={(department) =>
              setPath({ ...ROOT, department: department.department })
            }
          />
        )}
      </DialogContent>
    </Dialog>
  )
}
