"use client"

import {
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts"

import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/features/components/ui/card"
import type { ProgramChairAnalyticsSummary } from "@/features/schemas/dashboard-schema"

function humanize(key: string): string {
  return key
    .split("_")
    .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
    .join(" ")
}

interface EnrollmentBreakdownChartProps {
  summary: ProgramChairAnalyticsSummary
}

const statusColors: Record<string, string> = {
  enrolled: "var(--chart-1, #10b981)",
  pending_payment: "var(--chart-3, #3b82f6)",
  pending_program_head_approval: "var(--chart-2, #a855f7)",
  pending_student_review: "var(--chart-3, #d946ef)",
  awaiting_first_payment: "var(--chart-3, #0ea5e9)",
  pending_registrar_approval: "var(--chart-4, #8b5cf6)",
  draft: "var(--chart-5, #f59e0b)",
  withdrawn: "var(--chart-2, #ef4444)",
  rejected: "var(--muted-foreground, #64748b)",
  cancelled: "var(--muted-foreground, #94a3b8)",
}

export function EnrollmentBreakdownChart({ summary }: EnrollmentBreakdownChartProps) {
  const enrollmentStatusData = Object.entries(summary.enrollment_status_counts).map(
    ([status, count]) => ({
      status: humanize(status),
      rawStatus: status,
      count,
      fill: statusColors[status] || "var(--primary, #991b1b)",
    }),
  )

  const gradeStatusData = Object.entries(summary.grade_status_counts).map(
    ([status, count]) => ({
      status: humanize(status),
      count,
    }),
  )

  return (
    <div className="grid gap-4 lg:grid-cols-2">
      {/* Enrollment Lifecycle Status Breakdown */}
      <Card>
        <CardHeader>
          <CardTitle level={2}>Enrollment Status Breakdown</CardTitle>
          <CardDescription>
            Distribution of students across official enrollment stages for the selected range.
          </CardDescription>
        </CardHeader>
        <CardContent>
          {enrollmentStatusData.length === 0 ? (
            <div className="flex h-72 items-center justify-center text-sm text-muted-foreground">
              No enrollment status data available.
            </div>
          ) : (
            <div className="h-72 w-full">
              <ResponsiveContainer width="100%" height="100%">
                <BarChart
                  data={enrollmentStatusData}
                  margin={{ top: 12, right: 16, bottom: 32, left: 0 }}
                >
                  <CartesianGrid stroke="var(--dataviz-grid, #e2e8f0)" vertical={false} />
                  <XAxis
                    dataKey="status"
                    tickLine={false}
                    tick={{ fill: "var(--muted-foreground, #64748b)", fontSize: 11 }}
                    angle={-20}
                    textAnchor="end"
                    height={50}
                  />
                  <YAxis
                    allowDecimals={false}
                    tickLine={false}
                    tick={{ fill: "var(--muted-foreground, #64748b)" }}
                    label={{
                      value: "Students",
                      angle: -90,
                      position: "insideLeft",
                      fill: "var(--muted-foreground, #64748b)",
                      style: { textAnchor: "middle" },
                    }}
                  />
                  <Tooltip
                    contentStyle={{
                      background: "var(--card, #ffffff)",
                      borderColor: "var(--border, #e2e8f0)",
                      borderRadius: "var(--radius, 0.5rem)",
                      fontSize: "0.85rem",
                      boxShadow: "0 4px 6px -1px rgb(0 0 0 / 0.1)",
                    }}
                    formatter={(value: any) => [value, "Students"]}
                  />
                  <Bar dataKey="count" radius={[4, 4, 0, 0]} maxBarSize={45}>
                    {enrollmentStatusData.map((entry, index) => (
                      <Cell key={`cell-${index}`} fill={entry.fill} />
                    ))}
                  </Bar>
                </BarChart>
              </ResponsiveContainer>
            </div>
          )}
        </CardContent>
      </Card>

      {/* Grade Encoding Status Distribution */}
      <Card>
        <CardHeader>
          <CardTitle level={2}>Grade Processing Status</CardTitle>
          <CardDescription>
            Faculty grade encoding and lock stages for subjects taken in this period.
          </CardDescription>
        </CardHeader>
        <CardContent>
          {gradeStatusData.length === 0 ? (
            <div className="flex h-72 items-center justify-center text-sm text-muted-foreground">
              No grade status records found.
            </div>
          ) : (
            <div className="h-72 w-full">
              <ResponsiveContainer width="100%" height="100%">
                <BarChart
                  data={gradeStatusData}
                  margin={{ top: 12, right: 16, bottom: 24, left: 0 }}
                >
                  <CartesianGrid stroke="var(--dataviz-grid, #e2e8f0)" vertical={false} />
                  <XAxis
                    dataKey="status"
                    tickLine={false}
                    tick={{ fill: "var(--muted-foreground, #64748b)", fontSize: 12 }}
                  />
                  <YAxis
                    allowDecimals={false}
                    tickLine={false}
                    tick={{ fill: "var(--muted-foreground, #64748b)" }}
                    label={{
                      value: "Grades Count",
                      angle: -90,
                      position: "insideLeft",
                      fill: "var(--muted-foreground, #64748b)",
                      style: { textAnchor: "middle" },
                    }}
                  />
                  <Tooltip
                    contentStyle={{
                      background: "var(--card, #ffffff)",
                      borderColor: "var(--border, #e2e8f0)",
                      borderRadius: "var(--radius, 0.5rem)",
                      fontSize: "0.85rem",
                      boxShadow: "0 4px 6px -1px rgb(0 0 0 / 0.1)",
                    }}
                    formatter={(value: any) => [value, "Total Grades"]}
                  />
                  <Bar
                    dataKey="count"
                    fill="var(--chart-4, #8b5cf6)"
                    radius={[4, 4, 0, 0]}
                    maxBarSize={45}
                  />
                </BarChart>
              </ResponsiveContainer>
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  )
}
