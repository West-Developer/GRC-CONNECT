"use client"

import { useState } from "react"
import { Printer } from "lucide-react"

import { useAuth } from "@/features/auth/use-auth"
import { AsyncBoundary } from "@/features/components/portal/async-boundary"
import { EnrollmentStatusStudentsDialog } from "@/features/components/portal/enrollment-status-students-dialog"
import { WorkspacePage } from "@/features/components/portal/workspace-page"
import { YearOverYearReportDialog } from "@/features/components/portal/year-over-year-report-dialog"
import { Badge } from "@/features/components/ui/badge"
import { Button } from "@/features/components/ui/button"
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
} from "@/features/components/ui/card"
import { useInstitutionSummaryQuery } from "@/features/hooks/use-dashboard"
import { useAcademicTermsQuery } from "@/features/hooks/use-reference-data"
import {
  formatAcademicTerm,
  getActiveAcademicTerm,
} from "@/features/services/reference-data-service"

/** Snake-case status keys -> readable labels, no lookup table to keep in sync. */
function humanize(key: string): string {
  return key
    .split("_")
    .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
    .join(" ")
}

export function InstitutionDashboardWorkspace() {
  const { session } = useAuth()
  const authorized = session?.role === "executive_director"
  const termsQuery = useAcademicTermsQuery({ enabled: authorized })
  const activeTerm = getActiveAcademicTerm(termsQuery.data)

  const [termScope, setTermScope] = useState<"active" | "all">("active")
  const [selectedStatus, setSelectedStatus] = useState<{
    status: string
    label: string
  } | null>(null)
  const [showYoyReport, setShowYoyReport] = useState(false)

  const academicTermId =
    termScope === "active" && activeTerm ? activeTerm.id : undefined
  const summaryQuery = useInstitutionSummaryQuery(academicTermId, authorized)

  const combinedQuery = {
    isPending: summaryQuery.isPending,
    isError: summaryQuery.isError,
    error: summaryQuery.error,
    data: summaryQuery.data,
    refetch: () => {
      void termsQuery.refetch()
      void summaryQuery.refetch()
    },
  }

  return (
    <WorkspacePage
      title="Institution dashboard"
      description={
        termScope === "active" && activeTerm
          ? `Counts for ${formatAcademicTerm(activeTerm)}, the active term. Every number is a row count — nothing here reflects an institutional policy value.`
          : "Institution-wide counts across every academic term. Every number is a row count — nothing here reflects an institutional policy value."
      }
      unauthorized={!authorized}
      lastUpdated={summaryQuery.dataUpdatedAt}
    >
      <AsyncBoundary
        query={combinedQuery}
        loadingLabel="Loading the institution dashboard…"
      >
        {(summary) => (
          <div className="grid gap-4 lg:grid-cols-2">
            <Card>
              <CardHeader className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                  <CardTitle level={2}>
                    {termScope === "active" && activeTerm
                      ? `Enrollment status (${formatAcademicTerm(activeTerm)})`
                      : "Enrollment status, all terms"}
                  </CardTitle>
                  <p className="text-xs text-muted-foreground mt-0.5">
                    {termScope === "active"
                      ? "Filtered to active term (matches Dean dashboard)"
                      : "Cumulative volume across all academic terms"}
                  </p>
                </div>
                <div className="flex items-center gap-1 self-start sm:self-auto">
                  <Button
                    type="button"
                    size="sm"
                    variant={termScope === "active" ? "default" : "outline"}
                    className="h-7 text-xs"
                    onClick={() => setTermScope("active")}
                  >
                    Active term
                  </Button>
                  <Button
                    type="button"
                    size="sm"
                    variant={termScope === "all" ? "default" : "outline"}
                    className="h-7 text-xs"
                    onClick={() => setTermScope("all")}
                  >
                    All terms
                  </Button>
                </div>
              </CardHeader>
              <CardContent className="flex flex-wrap gap-2">
                {Object.entries(summary.status_counts).map(
                  ([status, count]) => (
                    <Button
                      key={status}
                      type="button"
                      variant="outline"
                      size="sm"
                      className="h-8 gap-1.5 cursor-pointer hover:bg-muted font-normal text-xs"
                      onClick={() =>
                        setSelectedStatus({
                          status,
                          label: humanize(status),
                        })
                      }
                    >
                      <span>
                        {humanize(status)}: {count}
                      </span>
                    </Button>
                  ),
                )}
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle level={2}>Programs and sections</CardTitle>
              </CardHeader>
              <CardContent className="grid gap-2">
                <p>
                  {summary.active_programs} of {summary.total_programs} programs
                  active
                </p>
                <p>
                  {summary.published_sections} of {summary.total_sections}{" "}
                  sections published
                </p>
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle level={2}>Students by program</CardTitle>
              </CardHeader>
              <CardContent className="flex flex-wrap gap-2">
                {Object.entries(summary.program_counts).map(([code, count]) => (
                  <Badge key={code} variant="secondary">
                    {code}: {count}
                  </Badge>
                ))}
              </CardContent>
            </Card>

            <Card>
              <CardHeader className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                  <CardTitle level={2}>Year over year</CardTitle>
                  <p className="text-sm text-muted-foreground">
                    Enrollment count per school year, oldest first.
                  </p>
                </div>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="gap-1.5 self-start sm:self-auto"
                  onClick={() => setShowYoyReport(true)}
                >
                  <Printer className="size-3.5" aria-hidden="true" />
                  <span>View & Print Report</span>
                </Button>
              </CardHeader>
              <CardContent className="flex flex-wrap gap-2">
                {summary.year_over_year.map((entry) => (
                  <Button
                    key={entry.school_year}
                    type="button"
                    variant="outline"
                    size="sm"
                    className="h-8 gap-1.5 cursor-pointer hover:bg-muted font-normal text-xs"
                    onClick={() => setShowYoyReport(true)}
                  >
                    <span>
                      {entry.school_year}: {entry.enrollment_count}
                    </span>
                  </Button>
                ))}
              </CardContent>
            </Card>
          </div>
        )}
      </AsyncBoundary>

      <EnrollmentStatusStudentsDialog
        open={selectedStatus !== null}
        onOpenChange={(open) => {
          if (!open) setSelectedStatus(null)
        }}
        status={selectedStatus?.status ?? null}
        statusLabel={selectedStatus?.label ?? ""}
        academicTermId={termScope === "active" ? activeTerm?.id : undefined}
        academicTermLabel={
          termScope === "active" && activeTerm
            ? formatAcademicTerm(activeTerm)
            : "All terms"
        }
      />

      <YearOverYearReportDialog
        open={showYoyReport}
        onOpenChange={setShowYoyReport}
        data={summaryQuery.data?.year_over_year ?? []}
      />
    </WorkspacePage>
  )
}
