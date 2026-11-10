"use client"

import { RefreshCw } from "lucide-react"
import { useState } from "react"

import { useAuth } from "@/features/auth/use-auth"
import { AsyncBoundary } from "@/features/components/portal/async-boundary"
import {
  EnrollmentDrilldownDialog,
  type DrilldownStart,
} from "@/features/components/portal/enrollment-drilldown-dialog"
import { EnrollmentStatusOverviewPanel } from "@/features/components/portal/enrollment-status-overview"
import { EnrollmentStepsChart } from "@/features/components/portal/enrollment-steps-chart"
import { WorkspacePage } from "@/features/components/portal/workspace-page"
import { Badge } from "@/features/components/ui/badge"
import { Button } from "@/features/components/ui/button"
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
} from "@/features/components/ui/card"
import {
  useEnrollmentStatusOverviewQuery,
  useEnrollmentSummaryQuery,
} from "@/features/hooks/use-dashboard"
import { useAcademicTermsQuery } from "@/features/hooks/use-reference-data"
import {
  formatAcademicTerm,
  getActiveAcademicTerm,
} from "@/features/services/reference-data-service"

/**
 * What a stage-limited role is looking at. The server enforces the limit at
 * every level of the drill-down; this only says so.
 */
function stageLimitNote(role: string | undefined): string | null {
  switch (role) {
    case "registrar_staff":
      return "Showing only the students waiting for the Registrar's approval."
    case "accounting_staff":
      return "Showing only the students waiting at the payment stage."
    case "admission_staff":
      return "Showing only the students still in the admission process."
    default:
      return null
  }
}

/** Snake-case status keys -> readable labels, no lookup table to keep in sync. */
function humanize(key: string): string {
  return key
    .split("_")
    .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
    .join(" ")
}

/**
 * The Enrollment Dashboard: every eligible student sorted into Enrolled /
 * Ongoing / Not yet done / Not enrolled, overall and per department, with a
 * drill-down from any number to the departments, sections and students behind
 * it. The Dean, Executive Director, Registrar Head and Program Head see every
 * stage; Registrar, Accounting, and Admission Staff see only the students
 * waiting on them. Which students each role can open is decided by the
 * backend (ADR 0024, amended for stakeholder Doc 14).
 */
export function EnrollmentDashboardWorkspace() {
  const { session } = useAuth()
  // Section fill and grade submission are institution-level figures, so only
  // the roles that see every stage get them.
  const canViewSummary =
    session?.role === "dean" ||
    session?.role === "program_chair" ||
    session?.role === "registrar_head" ||
    session?.role === "executive_director"
  const stageNote = stageLimitNote(session?.role)
  const authorized =
    canViewSummary ||
    session?.role === "registrar_staff" ||
    session?.role === "accounting_staff" ||
    session?.role === "admission_staff"
  const termsQuery = useAcademicTermsQuery({ enabled: authorized })
  const activeTerm = getActiveAcademicTerm(termsQuery.data)
  // Between terms nothing is in session; the counts are then an empty state, not a failed request.
  const noActiveTerm = termsQuery.isSuccess && !activeTerm
  const enabled = authorized && termsQuery.isSuccess && !noActiveTerm
  const overviewQuery = useEnrollmentStatusOverviewQuery(
    activeTerm?.id,
    enabled,
  )
  const summaryQuery = useEnrollmentSummaryQuery(
    activeTerm?.id,
    enabled && canViewSummary,
  )
  const [drilldown, setDrilldown] = useState<DrilldownStart | null>(null)
  const [departmentFilter, setDepartmentFilter] = useState<string>("all")
  const combinedQuery = {
    isPending:
      termsQuery.isPending ||
      (!noActiveTerm &&
        (overviewQuery.isPending ||
          (canViewSummary && summaryQuery.isPending))),
    isError:
      termsQuery.isError ||
      (!noActiveTerm &&
        (overviewQuery.isError || (canViewSummary && summaryQuery.isError))),
    error:
      termsQuery.error ??
      (noActiveTerm ? null : overviewQuery.error) ??
      (canViewSummary && !noActiveTerm ? summaryQuery.error : null),
    data:
      overviewQuery.data && (!canViewSummary || summaryQuery.data)
        ? {
            overview: overviewQuery.data,
            summary: canViewSummary ? summaryQuery.data : undefined,
          }
        : undefined,
    refetch: () => {
      void termsQuery.refetch()
      void overviewQuery.refetch()
      if (canViewSummary) void summaryQuery.refetch()
    },
  }

  return (
    <WorkspacePage
      title="Enrollment dashboard"
      description={
        activeTerm
          ? `Counts for ${formatAcademicTerm(activeTerm)}, the active term.${stageNote ? ` ${stageNote}` : ""} Every number is a count of students or rows — nothing here reflects an institutional policy value.`
          : "Counts for the active academic term."
      }
      unauthorized={!authorized}
      lastUpdated={overviewQuery.dataUpdatedAt}
      actions={
        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={() => void combinedQuery.refetch()}
          disabled={overviewQuery.isFetching || summaryQuery.isFetching}
        >
          <RefreshCw aria-hidden="true" />
          Refresh
        </Button>
      }
    >
      <AsyncBoundary
        query={combinedQuery}
        loadingLabel="Loading the enrollment dashboard…"
        emptyMessage="No enrollment term is open right now. The counts appear here once the Registrar opens a term."
      >
        {({ overview, summary }) => {
          const selectedDept =
            departmentFilter !== "all"
              ? overview.departments.find(
                  (d) =>
                    d.department?.toLowerCase() ===
                    departmentFilter.toLowerCase(),
                )
              : null

          const activeOverview = selectedDept
            ? {
                ...overview,
                total_students: selectedDept.total,
                groups: selectedDept.groups,
                steps: selectedDept.steps,
              }
            : overview

          return (
            <>
              <div className="grid gap-4">
                {overview.departments.length > 0 && (
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="text-xs font-semibold uppercase tracking-wider text-muted-foreground mr-1">
                      Department View:
                    </span>
                    <Button
                      type="button"
                      size="sm"
                      variant={
                        departmentFilter === "all" ? "default" : "outline"
                      }
                      className="h-8 text-xs"
                      onClick={() => setDepartmentFilter("all")}
                    >
                      All Departments
                    </Button>
                    {overview.departments.map((dept) => {
                      if (!dept.department) return null
                      const isSelected =
                        departmentFilter.toLowerCase() ===
                        dept.department.toLowerCase()
                      return (
                        <Button
                          key={dept.department}
                          type="button"
                          size="sm"
                          variant={isSelected ? "default" : "outline"}
                          className="h-8 text-xs font-mono"
                          onClick={() => setDepartmentFilter(dept.department!)}
                        >
                          {dept.department.toUpperCase()}
                        </Button>
                      )
                    })}
                  </div>
                )}
                <EnrollmentStatusOverviewPanel
                  overview={activeOverview}
                  selectedDepartment={departmentFilter}
                  onSelectDepartmentQueue={(dept) => setDepartmentFilter(dept)}
                  onOpenGroup={(group) =>
                    setDrilldown({
                      group,
                      department:
                        departmentFilter === "all" ? null : departmentFilter,
                    })
                  }
                  onOpenDepartment={(department, group) =>
                    setDrilldown({ group: group ?? null, department })
                  }
                />
                <EnrollmentStepsChart steps={activeOverview.steps} />
              {summary && (
                <div className="grid gap-4 lg:grid-cols-2">
                  <Card>
                    <CardHeader>
                      <CardTitle level={2}>Section fill</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-2">
                      <p>
                        {summary.published_sections} of {summary.total_sections}{" "}
                        sections published
                      </p>
                      <p>
                        {summary.total_enrolled_seats} of{" "}
                        {summary.total_capacity} published seats filled
                      </p>
                    </CardContent>
                  </Card>
                  <Card>
                    <CardHeader>
                      <CardTitle level={2}>Grade submission</CardTitle>
                    </CardHeader>
                    <CardContent className="flex flex-wrap gap-2">
                      {Object.entries(summary.grade_status_counts).map(
                        ([status, count]) => (
                          <Badge key={status} variant="outline">
                            {humanize(status)}: {count}
                          </Badge>
                        ),
                      )}
                    </CardContent>
                  </Card>
                </div>
              )}
            </div>
            <EnrollmentDrilldownDialog
              open={drilldown !== null}
              onOpenChange={(open) => {
                if (!open) setDrilldown(null)
              }}
              start={drilldown}
              overview={overview}
              academicTermId={activeTerm?.id}
            />
          </>
          )
        }}
      </AsyncBoundary>
    </WorkspacePage>
  )
}
