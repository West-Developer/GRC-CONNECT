"use client"

import {
  Bar,
  BarChart,
  CartesianGrid,
  Legend,
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
import type { AttritionReport } from "@/features/schemas/attrition-honors-schema"
import { formatYearLevel } from "@/features/lib/format-year-level"

interface AttritionCohortChartProps {
  report: AttritionReport
}

export function AttritionCohortChart({ report }: AttritionCohortChartProps) {
  const programData = report.groups.programs.map((p) => ({
    name: p.program_code,
    fullName: p.program_name,
    retained: p.retained_count,
    attrited: p.attrited_count,
    baseline: p.baseline_count,
    rate: p.attrition_rate,
  }))

  const yearLevelData = report.groups.year_levels.map((y) => ({
    name: formatYearLevel(y.year_level),
    retained: y.retained_count,
    attrited: y.attrited_count,
    baseline: y.baseline_count,
    rate: y.attrition_rate,
  }))

  return (
    <div className="grid gap-4 lg:grid-cols-2">
      {/* Program Retention vs Attrition Breakdown Chart */}
      <Card>
        <CardHeader>
          <CardTitle level={2}>Program Retention & Attrition</CardTitle>
          <CardDescription>
            Comparison of retained students vs. students who stopped or did not enroll for the second semester per program.
          </CardDescription>
        </CardHeader>
        <CardContent>
          {programData.length === 0 ? (
            <div className="flex h-72 items-center justify-center text-sm text-muted-foreground">
              No program data available for this cohort.
            </div>
          ) : (
            <div className="h-72 w-full">
              <ResponsiveContainer width="100%" height="100%">
                <BarChart
                  data={programData}
                  margin={{ top: 12, right: 16, bottom: 24, left: 0 }}
                >
                  <CartesianGrid stroke="var(--dataviz-grid, #e2e8f0)" vertical={false} />
                  <XAxis
                    dataKey="name"
                    tickLine={false}
                    tick={{ fill: "var(--muted-foreground, #64748b)", fontSize: 12 }}
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
                    formatter={(value: any, name: any) => [
                      value,
                      name === "retained" ? "Retained Students" : "Did Not Enroll",
                    ]}
                    labelFormatter={(label: any) => {
                      const item = programData.find((p) => p.name === label)
                      return item ? `${item.name} (${item.fullName})` : label
                    }}
                  />
                  <Legend
                    wrapperStyle={{ paddingTop: "8px" }}
                    formatter={(value) =>
                      value === "retained" ? "Retained Students" : "Did Not Enroll"
                    }
                  />
                  <Bar
                    dataKey="retained"
                    fill="var(--chart-1, #10b981)"
                    radius={[4, 4, 0, 0]}
                    maxBarSize={45}
                  />
                  <Bar
                    dataKey="attrited"
                    fill="var(--chart-5, #f59e0b)"
                    radius={[4, 4, 0, 0]}
                    maxBarSize={45}
                  />
                </BarChart>
              </ResponsiveContainer>
            </div>
          )}
        </CardContent>
      </Card>

      {/* Year Level Attrition Rate (%) Chart */}
      <Card>
        <CardHeader>
          <CardTitle level={2}>Attrition Rate by Year Level</CardTitle>
          <CardDescription>
            Percentage rate of students who discontinued enrollment across academic year cohorts.
          </CardDescription>
        </CardHeader>
        <CardContent>
          {yearLevelData.length === 0 ? (
            <div className="flex h-72 items-center justify-center text-sm text-muted-foreground">
              No year-level data available for this cohort.
            </div>
          ) : (
            <div className="h-72 w-full">
              <ResponsiveContainer width="100%" height="100%">
                <BarChart
                  data={yearLevelData}
                  margin={{ top: 12, right: 16, bottom: 24, left: 0 }}
                >
                  <CartesianGrid stroke="var(--dataviz-grid, #e2e8f0)" vertical={false} />
                  <XAxis
                    dataKey="name"
                    tickLine={false}
                    tick={{ fill: "var(--muted-foreground, #64748b)", fontSize: 12 }}
                  />
                  <YAxis
                    tickLine={false}
                    tick={{ fill: "var(--muted-foreground, #64748b)" }}
                    unit="%"
                    domain={[0, (dataMax: number) => Math.max(Math.ceil(dataMax * 1.25), 15)]}
                    label={{
                      value: "Attrition Rate (%)",
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
                    formatter={(value: any, _name: any, item: any) => [
                      `${value}% (${item.payload.attrited} of ${item.payload.baseline} students)`,
                      "Attrition Rate",
                    ]}
                  />
                  <Bar
                    dataKey="rate"
                    fill="var(--chart-2, #ef4444)"
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
