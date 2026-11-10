"use client"

import {
  CartesianGrid,
  Line,
  LineChart,
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

export interface EnrollmentYearOverYearPoint {
  school_year: string
  semester: string
  enrollee_count: number
}

function semesterOrder(semester: string): number {
  return semester === "1st" ? 1 : semester === "2nd" ? 2 : 3
}

/**
 * A single chronological series makes enrollment movement legible. The
 * backend has already limited `enrollee_count` to official Enrolled records,
 * so draft and pending workflows cannot be mistaken for student headcount.
 */
export function EnrollmentYearOverYearChart({
  points,
}: {
  points: EnrollmentYearOverYearPoint[]
}) {
  const rows = [...points]
    .sort(
      (left, right) =>
        left.school_year.localeCompare(right.school_year) ||
        semesterOrder(left.semester) - semesterOrder(right.semester),
    )
    .map((point) => ({
      ...point,
      label: `${point.school_year} · ${point.semester}`,
    }))

  return (
    <Card>
      <CardHeader>
        <CardTitle>Official Enrollment Trend</CardTitle>
        <CardDescription>
          Officially enrolled students per term. A rising line means more
          enrolled students; a falling line means fewer.
        </CardDescription>
      </CardHeader>
      <CardContent>
        {rows.length === 0 ? (
          <p className="py-10 text-center text-sm text-muted-foreground">
            No official enrollment trend data is available yet.
          </p>
        ) : (
          <div className="h-80 w-full">
            <ResponsiveContainer width="100%" height="100%">
              <LineChart
                data={rows}
                margin={{ top: 8, right: 16, bottom: 20, left: 0 }}
              >
                <CartesianGrid stroke="var(--dataviz-grid)" vertical={false} />
                <XAxis
                  dataKey="label"
                  stroke="var(--dataviz-axis)"
                  tickLine={false}
                  tick={{ fill: "var(--muted-foreground)", fontSize: 12 }}
                  angle={-20}
                  textAnchor="end"
                  height={54}
                />
                <YAxis
                  allowDecimals={false}
                  stroke="var(--dataviz-axis)"
                  tickLine={false}
                  tick={{ fill: "var(--muted-foreground)" }}
                  label={{
                    value: "Students",
                    angle: -90,
                    position: "insideLeft",
                    fill: "var(--muted-foreground)",
                  }}
                />
                <Tooltip
                  cursor={{ stroke: "var(--dataviz-axis)", strokeWidth: 1 }}
                  contentStyle={{
                    background: "var(--card)",
                    borderColor: "var(--border)",
                    borderRadius: "var(--radius)",
                  }}
                  labelStyle={{ color: "var(--card-foreground)" }}
                  itemStyle={{ color: "var(--card-foreground)" }}
                  formatter={(value) => [value, "Officially enrolled"]}
                />
                <Line
                  type="monotone"
                  dataKey="enrollee_count"
                  name="Officially enrolled"
                  stroke="var(--dataviz-series-1)"
                  strokeWidth={2}
                  dot={{ r: 4 }}
                  activeDot={{ r: 6 }}
                />
              </LineChart>
            </ResponsiveContainer>
          </div>
        )}
      </CardContent>
    </Card>
  )
}
