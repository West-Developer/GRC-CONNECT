"use client"

import Link from "next/link"
import { useState } from "react"

import { useAuth } from "@/features/auth/use-auth"
import { AsyncBoundary } from "@/features/components/portal/async-boundary"
import { DataTable } from "@/features/components/portal/data-table"
import { EnrollmentGroupBar } from "@/features/components/portal/enrollment-group-bar"
import { Paginator } from "@/features/components/portal/paginator"
import { Badge } from "@/features/components/ui/badge"
import { Button } from "@/features/components/ui/button"
import {
  useEnrollmentStatusSectionsQuery,
  useEnrollmentStatusStudentQuery,
  useEnrollmentStatusStudentsQuery,
} from "@/features/hooks/use-dashboard"
import { formatYearLevelOrdinal } from "@/features/lib/curriculum-ordinal"
import {
  ENROLLMENT_GROUP_PRESENTATION,
  ENROLLMENT_GROUPS,
  formatCount,
} from "@/features/lib/enrollment-status-groups"
import type {
  EnrollmentStatusDepartment,
  EnrollmentStatusGroup,
  EnrollmentStatusGroupCounts,
  EnrollmentStatusStudent,
} from "@/features/schemas/dashboard-schema"

function formatDateTime(iso: string | null): string {
  if (!iso) return "—"

  return new Date(iso).toLocaleString("en-US", {
    month: "short",
    day: "numeric",
    year: "numeric",
    hour: "numeric",
    minute: "2-digit",
  })
}

export function GroupBadge({ group }: { group: EnrollmentStatusGroup }) {
  const presentation = ENROLLMENT_GROUP_PRESENTATION[group]
  const Icon = presentation.icon

  return (
    <Badge variant="outline" className="gap-1.5">
      <Icon
        className="size-3.5"
        style={{ color: presentation.color }}
        aria-hidden="true"
      />
      {presentation.label}
    </Badge>
  )
}

/**
 * One drill-down choice — a department or a section — as a button. With a
 * `group` chosen it shows only that group's count (and is disabled at zero);
 * without one it shows the total and how it splits across the four groups.
 */
function DrillRow({
  title,
  total,
  groups,
  group,
  onOpen,
}: {
  title: string
  total: number
  groups: EnrollmentStatusGroupCounts
  group: EnrollmentStatusGroup | null
  onOpen: () => void
}) {
  const count = group === null ? total : groups[group]
  const summary =
    group === null
      ? `${formatCount(total)} students`
      : `${formatCount(count)} of ${formatCount(total)} students`

  return (
    <button
      type="button"
      disabled={count === 0}
      onClick={onOpen}
      aria-label={
        group === null
          ? `${title}: ${summary}`
          : `${title}: ${formatCount(count)} ${ENROLLMENT_GROUP_PRESENTATION[group].label}, of ${formatCount(total)} students`
      }
      className="grid w-full cursor-pointer gap-2 rounded-lg border p-3 text-left transition-colors hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-60"
    >
      <span className="flex items-baseline justify-between gap-3">
        <span className="font-medium">{title}</span>
        <span className="shrink-0 text-sm text-muted-foreground">
          {summary}
        </span>
      </span>
      <EnrollmentGroupBar groups={groups} />
      {group === null && (
        <span className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
          {ENROLLMENT_GROUPS.map((key) => {
            const presentation = ENROLLMENT_GROUP_PRESENTATION[key]
            const Icon = presentation.icon

            return (
              <span key={key} className="inline-flex items-center gap-1">
                <Icon
                  className="size-3"
                  style={{ color: presentation.color }}
                  aria-hidden="true"
                />
                {presentation.label} {formatCount(groups[key])}
              </span>
            )
          })}
        </span>
      )}
    </button>
  )
}

export function DepartmentsPanel({
  departments,
  group,
  onSelect,
}: {
  departments: readonly EnrollmentStatusDepartment[]
  group: EnrollmentStatusGroup | null
  onSelect: (department: EnrollmentStatusDepartment) => void
}) {
  const selectable = departments.filter(
    (department) => department.department !== null,
  )

  return (
    <ul className="grid gap-2">
      {selectable.map((department) => (
        <li key={department.department}>
          <DrillRow
            title={department.label}
            total={department.total}
            groups={department.groups}
            group={group}
            onOpen={() => onSelect(department)}
          />
        </li>
      ))}
    </ul>
  )
}

export function SectionsPanel({
  department,
  academicTermId,
  group,
  onSelect,
}: {
  department: string
  academicTermId?: number
  group: EnrollmentStatusGroup | null
  /** `null` is the "No section yet" row. */
  onSelect: (sectionCode: string | null) => void
}) {
  const query = useEnrollmentStatusSectionsQuery(department, academicTermId)

  return (
    <AsyncBoundary
      query={query}
      loadingLabel="Loading sections…"
      isEmpty={(data) => data.sections.length === 0}
      emptyMessage="No students are counted for this department in this term."
    >
      {(data) => {
        const visible = data.sections.filter(
          (section) => group === null || section.groups[group] > 0,
        )

        return visible.length === 0 ? (
          <p className="py-6 text-center text-sm text-muted-foreground">
            No section has students in this group.
          </p>
        ) : (
          <ul className="grid gap-2">
            {visible.map((section) => (
              <li key={section.section_code ?? "none"}>
                <DrillRow
                  title={section.section_code ?? "No section yet"}
                  total={section.total}
                  groups={section.groups}
                  group={group}
                  onOpen={() => onSelect(section.section_code)}
                />
              </li>
            ))}
          </ul>
        )
      }}
    </AsyncBoundary>
  )
}

export function StudentsPanel({
  department,
  sectionCode,
  group,
  academicTermId,
  onSelect,
}: {
  department: string
  sectionCode: string | null
  group: EnrollmentStatusGroup | null
  academicTermId?: number
  onSelect: (student: EnrollmentStatusStudent) => void
}) {
  const [page, setPage] = useState(1)
  const query = useEnrollmentStatusStudentsQuery({
    department,
    academicTermId,
    sectionCode,
    group: group ?? undefined,
    page,
    perPage: 15,
  })

  return (
    <AsyncBoundary
      query={query}
      loadingLabel="Loading students…"
      isEmpty={(result) => result.data.length === 0}
      emptyMessage="No students match this selection."
    >
      {(result) => (
        <div className="grid gap-4">
          <DataTable
            caption="Students in this selection"
            rowKey={(student) => student.student_profile_id}
            rows={result.data}
            columns={[
              {
                key: "student",
                header: "Student",
                render: (student) => (
                  <span className="grid">
                    <Button
                      type="button"
                      variant="link"
                      className="h-auto justify-start p-0 text-left"
                      onClick={() => onSelect(student)}
                    >
                      {student.student_name}
                    </Button>
                    <span className="font-mono text-xs text-muted-foreground">
                      {student.student_number}
                    </span>
                  </span>
                ),
              },
              {
                key: "program",
                header: "Program",
                render: (student) =>
                  `${student.program_code} · ${formatYearLevelOrdinal(student.year_level)}`,
              },
              {
                key: "section",
                header: "Section",
                render: (student) => student.section_code ?? "—",
              },
              {
                key: "status",
                header: "Status",
                render: (student) => (
                  <span className="grid gap-1">
                    <GroupBadge group={student.group} />
                    {student.enrollment_status_label && (
                      <span className="text-xs text-muted-foreground">
                        {student.enrollment_status_label}
                      </span>
                    )}
                  </span>
                ),
              },
              {
                key: "updated",
                header: "Last update",
                render: (student) => (
                  <span className="text-xs text-muted-foreground">
                    {formatDateTime(
                      student.enrolled_at ?? student.submitted_at,
                    )}
                  </span>
                ),
              },
            ]}
          />
          {result.meta.last_page > 1 && (
            <Paginator
              currentPage={result.meta.current_page}
              lastPage={result.meta.last_page}
              onPageChange={setPage}
            />
          )}
        </div>
      )}
    </AsyncBoundary>
  )
}

export function StudentDetailPanel({
  studentProfileId,
  academicTermId,
}: {
  studentProfileId: number
  academicTermId?: number
}) {
  const { session } = useAuth()
  const query = useEnrollmentStatusStudentQuery(
    studentProfileId,
    academicTermId,
  )

  return (
    <AsyncBoundary query={query} loadingLabel="Loading student…">
      {(student) => (
        <div className="grid gap-5">
          <div className="flex flex-wrap items-start justify-between gap-3">
            <div>
              <p className="text-lg font-semibold">{student.student_name}</p>
              <p className="font-mono text-sm text-muted-foreground">
                {student.student_number}
              </p>
            </div>
            <div className="grid justify-items-end gap-1">
              <GroupBadge group={student.group} />
              {student.enrollment && (
                <span className="text-xs text-muted-foreground">
                  {student.enrollment.status_label}
                </span>
              )}
            </div>
          </div>

          <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            {[
              ["Program", `${student.program_code} — ${student.program_name}`],
              ["Year level", formatYearLevelOrdinal(student.year_level)],
              ["Section", student.section_code ?? "No section yet"],
              [
                "Enrollment category",
                student.enrollment_category
                  ? student.enrollment_category.charAt(0).toUpperCase() +
                    student.enrollment_category.slice(1)
                  : "—",
              ],
            ].map(([label, value]) => (
              <div key={label} className="rounded-lg border p-3">
                <dt className="text-xs text-muted-foreground">{label}</dt>
                <dd className="text-sm font-medium">{value}</dd>
              </div>
            ))}
          </dl>

          {student.enrollment === null ? (
            <p className="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
              This student has not started enrolling for this term.
            </p>
          ) : (
            <>
              <section aria-label="Enrollment timeline" className="grid gap-2">
                <h3 className="text-sm font-semibold">Enrollment timeline</h3>
                <ol className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                  {[
                    ["Submitted", student.enrollment.submitted_at],
                    ["Decided", student.enrollment.registrar_decided_at],
                    [
                      "Payment confirmed",
                      student.enrollment.payment_confirmed_at,
                    ],
                    ["Enrolled", student.enrollment.enrolled_at],
                  ].map(([label, at]) => (
                    <li key={label} className="rounded-lg border p-3">
                      <p className="text-xs text-muted-foreground">{label}</p>
                      <p className="text-sm font-medium">
                        {formatDateTime(at)}
                      </p>
                    </li>
                  ))}
                </ol>
              </section>

              <section aria-label="Subjects" className="grid gap-2">
                <h3 className="text-sm font-semibold">
                  Subjects · {student.enrollment.total_units} units
                </h3>
                <DataTable
                  caption="Subjects on this enrollment"
                  rowKey={(subject) =>
                    `${subject.subject_code}-${subject.section_code ?? ""}`
                  }
                  rows={student.subjects}
                  emptyMessage="No subjects are on this enrollment."
                  columns={[
                    {
                      key: "code",
                      header: "Code",
                      render: (subject) => subject.subject_code,
                    },
                    {
                      key: "title",
                      header: "Description",
                      render: (subject) => subject.subject_title,
                    },
                    {
                      key: "units",
                      header: "Units",
                      render: (subject) => subject.units ?? "—",
                    },
                    {
                      key: "section",
                      header: "Section",
                      render: (subject) => subject.section_code ?? "—",
                    },
                  ]}
                />
              </section>
            </>
          )}

          {session?.role === "registrar_head" && (
            <div className="flex flex-wrap items-center gap-3 rounded-lg border p-3">
              <p className="text-sm text-muted-foreground">
                The official Certificate of Registration and full record are in
                COR Records.
              </p>
              <Button asChild variant="outline" size="sm">
                <Link href="/portal/cor-records">Open COR Records</Link>
              </Button>
            </div>
          )}
        </div>
      )}
    </AsyncBoundary>
  )
}
