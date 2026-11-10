"use client"

import { useMemo, useState } from "react"
import {
  CartesianGrid,
  Legend,
  Line,
  LineChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts"

import { Badge } from "@/features/components/ui/badge"
import { Button } from "@/features/components/ui/button"
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/features/components/ui/card"
import type { AnalyticsYearOverYearPoint } from "@/features/schemas/dashboard-schema"

export type TrendViewMode = "stopped" | "rate" | "comparative"

function semesterOrder(semester: string): number {
  if (semester === "1st" || semester.includes("1st")) return 1
  if (semester === "2nd" || semester.includes("2nd")) return 2
  return 3
}

export function StoppedStudentsTrendChart({
  points,
}: {
  points: AnalyticsYearOverYearPoint[]
}) {
  const [viewMode, setViewMode] = useState<TrendViewMode>("stopped")

  const rows = useMemo(() => {
    return [...points]
      .sort(
        (left, right) =>
          left.school_year.localeCompare(right.school_year) ||
          semesterOrder(left.semester) - semesterOrder(right.semester),
      )
      .map((point) => ({
        ...point,
        label: `${point.school_year} · ${point.semester}`,
        stopped_count: point.stopped_count ?? 0,
        attrition_rate: point.attrition_rate ?? 0,
      }))
  }, [points])

  const stats = useMemo(() => {
    if (rows.length === 0) return null
    const totalStopped = rows.reduce((sum, r) => sum + r.stopped_count, 0)
    const latest = rows[rows.length - 1]
    const peak = [...rows].sort((a, b) => b.stopped_count - a.stopped_count)[0]
    return {
      totalStopped,
      latestTerm: latest?.label,
      latestStopped: latest?.stopped_count ?? 0,
      latestRate: latest?.attrition_rate ?? 0,
      peakTerm: peak?.label,
      peakStopped: peak?.stopped_count ?? 0,
    }
  }, [rows])

  return (
    <Card>
      <CardHeader className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <CardTitle level={2}>Stopped Students Trend</CardTitle>
          <CardDescription>
            Trend ng mga nag-stop o hindi nagpatuloy na estudyante kada semestre.
            A rising line indicates increasing attrition; a falling line indicates
            improved retention.
          </CardDescription>
        </div>
        <div
          className="flex flex-wrap items-center gap-1.5 rounded-lg border bg-muted/40 p-1"
          role="tablist"
          aria-label="Trend chart view mode"
        >
          <Button
            type="button"
            size="sm"
            variant={viewMode === "stopped" ? "default" : "ghost"}
            className="h-7 text-xs px-2.5"
            onClick={() => setViewMode("stopped")}
            role="tab"
            aria-selected={viewMode === "stopped"}
          >
            Stopped Count
          </Button>
          <Button
            type="button"
            size="sm"
            variant={viewMode === "rate" ? "default" : "ghost"}
            className="h-7 text-xs px-2.5"
            onClick={() => setViewMode("rate")}
            role="tab"
            aria-selected={viewMode === "rate"}
          >
            Attrition Rate (%)
          </Button>
          <Button
            type="button"
            size="sm"
            variant={viewMode === "comparative" ? "default" : "ghost"}
            className="h-7 text-xs px-2.5"
            onClick={() => setViewMode("comparative")}
            role="tab"
            aria-selected={viewMode === "comparative"}
          >
            Comparative View
          </Button>
        </div>
      </CardHeader>
      <CardContent>
        {stats && (
          <div className="mb-4 flex flex-wrap gap-2 text-xs">
            <Badge variant="outline" className="border-rose-300/60 bg-rose-50/50 text-rose-800 dark:border-rose-900/60 dark:bg-rose-950/40 dark:text-rose-300">
              Total Stopped: <span className="ml-1 font-bold">{stats.totalStopped}</span>
            </Badge>
            <Badge variant="outline">
              Latest ({stats.latestTerm}):{" "}
              <span className="ml-1 font-bold">
                {stats.latestStopped} students ({stats.latestRate}%)
              </span>
            </Badge>
            {stats.peakStopped > 0 && (
              <Badge variant="outline">
                Peak Attrition ({stats.peakTerm}):{" "}
                <span className="ml-1 font-bold">{stats.peakStopped} students</span>
              </Badge>
            )}
          </div>
        )}

        {rows.length === 0 ? (
          <p className="py-10 text-center text-sm text-muted-foreground">
            No stopped student trend data is available yet.
          </p>
        ) : (
          <div className="h-80 w-full">
            <ResponsiveContainer width="100%" height="100%">
              <LineChart
                data={rows}
                margin={{ top: 12, right: 16, bottom: 20, left: 0 }}
              >
                <CartesianGrid stroke="var(--dataviz-grid, #e2e8f0)" vertical={false} />
                <XAxis
                  dataKey="label"
                  stroke="var(--dataviz-axis, #94a3b8)"
                  tickLine={false}
                  tick={{ fill: "var(--muted-foreground, #64748b)", fontSize: 12 }}
                  angle={-20}
                  textAnchor="end"
                  height={54}
                />
                <YAxis
                  allowDecimals={viewMode === "rate"}
                  stroke="var(--dataviz-axis, #94a3b8)"
                  tickLine={false}
                  tick={{ fill: "var(--muted-foreground, #64748b)" }}
                  label={{
                    value:
                      viewMode === "rate"
                        ? "Attrition Rate (%)"
                        : "Students",
                    angle: -90,
                    position: "insideLeft",
                    fill: "var(--muted-foreground, #64748b)",
                    style: { textAnchor: "middle" },
                  }}
                />
                <Tooltip
                  cursor={{ stroke: "var(--dataviz-axis, #94a3b8)", strokeWidth: 1 }}
                  contentStyle={{
                    background: "var(--card, #ffffff)",
                    borderColor: "var(--border, #e2e8f0)",
                    borderRadius: "var(--radius, 0.5rem)",
                    fontSize: "0.85rem",
                    boxShadow: "0 4px 6px -1px rgb(0 0 0 / 0.1)",
                  }}
                  labelStyle={{ color: "var(--card-foreground, #0f172a)" }}
                  formatter={(value: any, name: any) => {
                    if (name === "attrition_rate" || name === "Attrition Rate") {
                      return [`${value}%`, "Attrition Rate"]
                    }
                    if (name === "stopped_count" || name === "Stopped Students") {
                      return [value, "Stopped Students"]
                    }
                    return [value, "Officially Enrolled"]
                  }}
                />
                {viewMode === "comparative" && (
                  <Legend
                    verticalAlign="top"
                    align="right"
                    wrapperStyle={{ paddingBottom: "10px", fontSize: "0.75rem" }}
                  />
                )}

                {/* Stopped Students Series */}
                {(viewMode === "stopped" || viewMode === "comparative") && (
                  <Line
                    type="monotone"
                    dataKey="stopped_count"
                    name="Stopped Students"
                    stroke="#e11d48"
                    strokeWidth={2.5}
                    dot={{ r: 4, fill: "#e11d48" }}
                    activeDot={{ r: 6, fill: "#e11d48" }}
                  />
                )}

                {/* Attrition Rate Series */}
                {viewMode === "rate" && (
                  <Line
                    type="monotone"
                    dataKey="attrition_rate"
                    name="Attrition Rate"
                    stroke="#f59e0b"
                    strokeWidth={2.5}
                    dot={{ r: 4, fill: "#f59e0b" }}
                    activeDot={{ r: 6, fill: "#f59e0b" }}
                  />
                )}

                {/* Enrolled Series (in comparative view) */}
                {viewMode === "comparative" && (
                  <Line
                    type="monotone"
                    dataKey="enrollee_count"
                    name="Officially Enrolled"
                    stroke="#3b82f6"
                    strokeWidth={2}
                    dot={{ r: 3, fill: "#3b82f6" }}
                    activeDot={{ r: 5, fill: "#3b82f6" }}
                  />
                )}
              </LineChart>
            </ResponsiveContainer>
          </div>
        )}
      </CardContent>
    </Card>
  )
}

