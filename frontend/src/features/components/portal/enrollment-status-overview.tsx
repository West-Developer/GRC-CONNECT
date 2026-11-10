"use client"

import { EnrollmentGroupBar } from "@/features/components/portal/enrollment-group-bar"
import { Badge } from "@/features/components/ui/badge"
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/features/components/ui/card"
import {
  ENROLLMENT_GROUP_PRESENTATION,
  ENROLLMENT_GROUPS,
  formatCount,
  formatShare,
} from "@/features/lib/enrollment-status-groups"
import type {
  EnrollmentStatusGroup,
  EnrollmentStatusOverview,
} from "@/features/schemas/dashboard-schema"

interface EnrollmentStatusOverviewPanelProps {
  overview: EnrollmentStatusOverview
  /** Open the drill-down for one group across every department. */
  onOpenGroup: (group: EnrollmentStatusGroup) => void
  /** Open one department, optionally already narrowed to a group. */
  onOpenDepartment: (department: string, group?: EnrollmentStatusGroup) => void
  /** Filter or view queue for one department. */
  onSelectDepartmentQueue?: (department: string) => void
  selectedDepartment?: string
}

/**
 * The top of the Enrollment Dashboard: how many students are in each of the
 * four groups, overall and per department. Every number here is a button that
 * opens the drill-down (department, section, student) at that point.
 */
export function EnrollmentStatusOverviewPanel({
  overview,
  onOpenGroup,
  onOpenDepartment,
  onSelectDepartmentQueue,
  selectedDepartment = "all",
}: EnrollmentStatusOverviewPanelProps) {
  const total = overview.total_students
  const selectedDeptObj =
    selectedDepartment !== "all"
      ? overview.departments.find(
          (d) =>
            d.department?.toLowerCase() === selectedDepartment.toLowerCase(),
        )
      : null

  return (
    <>
      <Card>
        <CardHeader>
          <div className="flex flex-wrap items-center justify-between gap-2">
            <div className="space-y-1">
              <div className="flex items-center gap-2">
                <CardTitle level={2}>Enrollment status</CardTitle>
                {selectedDeptObj && (
                  <Badge variant="secondary" className="font-medium">
                    {selectedDeptObj.label}
                  </Badge>
                )}
              </div>
              <CardDescription>
                {selectedDeptObj
                  ? `Showing enrollment status and queue for ${selectedDeptObj.label}.`
                  : "Every eligible student, by where they are in enrolling this term. Select a group to see the departments, sections and students in it."}
              </CardDescription>
            </div>
          </div>
        </CardHeader>
        <CardContent className="grid gap-5">
          <div>
            <p className="text-4xl font-semibold tracking-tight">
              {formatCount(total)}
            </p>
            <p className="text-sm text-muted-foreground">
              {total === 1 ? "student" : "students"} counted for this term
            </p>
          </div>
          <EnrollmentGroupBar groups={overview.groups} />
          <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            {ENROLLMENT_GROUPS.map((group) => {
              const presentation = ENROLLMENT_GROUP_PRESENTATION[group]
              const Icon = presentation.icon
              const count = overview.groups[group]

              return (
                <li key={group}>
                  <button
                    type="button"
                    aria-label={`${presentation.label}: ${formatCount(count)} students, ${formatShare(count, total)}. Show departments.`}
                    onClick={() => onOpenGroup(group)}
                    className="grid h-full w-full cursor-pointer gap-1 rounded-lg border p-4 text-left transition-colors hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                  >
                    <span className="flex items-center gap-2 text-sm font-medium">
                      <Icon
                        className="size-4 shrink-0"
                        style={{ color: presentation.color }}
                        aria-hidden="true"
                      />
                      {presentation.label}
                    </span>
                    <span className="text-3xl font-semibold tracking-tight">
                      {formatCount(count)}
                    </span>
                    <span className="text-xs text-muted-foreground">
                      {formatShare(count, total)} · {presentation.description}
                    </span>
                  </button>
                </li>
              )
            })}
          </ul>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle level={2}>By department</CardTitle>
          <CardDescription>
            The same four groups for each department. Select a department for
            its sections, or a count for just that group.
          </CardDescription>
        </CardHeader>
        <CardContent className="grid gap-4 md:grid-cols-2">
          {overview.departments.map((department) => {
            const code = department.department
            const drillable = code !== null

            return (
              <section
                key={code ?? "none"}
                aria-label={department.label}
                className="grid gap-3 rounded-lg border p-4"
              >
                {drillable ? (
                  <button
                    type="button"
                    onClick={() => onOpenDepartment(code)}
                    className="flex cursor-pointer items-baseline justify-between gap-3 rounded-sm text-left hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                  >
                    <span className="font-medium">{department.label}</span>
                    <span className="shrink-0 text-sm text-muted-foreground">
                      {formatCount(department.total)} students
                    </span>
                  </button>
                ) : (
                  <p className="flex items-baseline justify-between gap-3">
                    <span className="font-medium">{department.label}</span>
                    <span className="shrink-0 text-sm text-muted-foreground">
                      {formatCount(department.total)} students
                    </span>
                  </p>
                )}
                <EnrollmentGroupBar groups={department.groups} />
                <ul className="grid grid-cols-2 gap-2">
                  {ENROLLMENT_GROUPS.map((group) => {
                    const presentation = ENROLLMENT_GROUP_PRESENTATION[group]
                    const Icon = presentation.icon
                    const count = department.groups[group]
                    const content = (
                      <>
                        <Icon
                          className="size-3.5 shrink-0"
                          style={{ color: presentation.color }}
                          aria-hidden="true"
                        />
                        <span className="min-w-0 text-xs text-muted-foreground">
                          {presentation.label}
                        </span>
                        <span className="text-sm font-medium tabular-nums">
                          {formatCount(count)}
                        </span>
                      </>
                    )

                    return (
                      <li key={group}>
                        {drillable ? (
                          <button
                            type="button"
                            aria-label={`${department.label}, ${presentation.label}: ${formatCount(count)}`}
                            onClick={() => onOpenDepartment(code, group)}
                            className="grid min-h-11 w-full cursor-pointer grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-1.5 rounded-md px-1.5 py-1 text-left hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                          >
                            {content}
                          </button>
                        ) : (
                          <span className="grid min-h-11 grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-1.5 px-1.5 py-1">
                            {content}
                          </span>
                        )}
                      </li>
                    )
                  })}
                </ul>
                {drillable && onSelectDepartmentQueue && (
                  <div className="flex items-center justify-between border-t pt-2 mt-0.5">
                    <span className="text-xs text-muted-foreground">
                      Department queue
                    </span>
                    <button
                      type="button"
                      onClick={() => onSelectDepartmentQueue(code)}
                      className="text-xs font-semibold text-primary hover:underline flex items-center gap-1 cursor-pointer"
                    >
                      View Queue →
                    </button>
                  </div>
                )}
              </section>
            )
          })}
        </CardContent>
      </Card>
    </>
  )
}
