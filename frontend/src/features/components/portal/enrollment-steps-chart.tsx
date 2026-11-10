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
import {
  ENROLLMENT_STEP_LABELS,
  ENROLLMENT_STEPS,
  formatCount,
} from "@/features/lib/enrollment-status-groups"

// State, not identity: the reserved status tokens, the same ones the group
// tiles use.
const ENROLLED_FILL = "var(--success)"
const ONGOING_FILL = "var(--muted-foreground)"

function buildRows(steps: Record<string, number>) {
  return ENROLLMENT_STEPS.map((step) => {
    const count = steps[step] ?? 0
    const label = ENROLLMENT_STEP_LABELS[step]

    return {
      step,
      label,
      count,
      axisLabel: `${label} · ${formatCount(count)}`,
      enrolled: step === "enrolled" ? count : 0,
      ongoing: step === "enrolled" ? 0 : count,
    }
  })
}

/**
 * Where students currently sit on the way to enrolled: draft, waiting on the
 * Program Head, waiting on the Registrar, waiting on payment, and — the last
 * step — enrolled. Counts come from the dashboard overview, so every role that
 * can open the dashboard sees the chart for the students it is allowed to see.
 */
export function EnrollmentStepsChart({
  steps,
}: {
  steps: Record<string, number>
}) {
  const rows = buildRows(steps)
  const hasAny = rows.some((row) => row.count > 0)

  return (
    <Card>
      <CardHeader>
        <CardTitle level={2}>Enrollment progress by step</CardTitle>
        <CardDescription>
          How many students are at each step, from draft through enrolled.
        </CardDescription>
      </CardHeader>
      <CardContent>
        {!hasAny ? (
          <p className="py-10 text-center text-sm text-muted-foreground">
            No student has started enrolling for this term yet.
          </p>
        ) : (
          <>
            <div style={{ height: rows.length * 64 + 56 }}>
              <ResponsiveContainer width="100%" height="100%">
                <BarChart
                  data={rows}
                  layout="vertical"
                  margin={{ top: 8, right: 24, bottom: 8, left: 8 }}
                  barCategoryGap="30%"
                >
                  <CartesianGrid
                    stroke="var(--dataviz-grid)"
                    horizontal={false}
                  />
                  <XAxis
                    type="number"
                    allowDecimals={false}
                    stroke="var(--dataviz-axis)"
                    tickLine={false}
                    tick={{ fill: "var(--muted-foreground)", fontSize: 12 }}
                  />
                  <YAxis
                    type="category"
                    dataKey="axisLabel"
                    width={210}
                    stroke="var(--dataviz-axis)"
                    tickLine={false}
                    tick={{ fill: "var(--muted-foreground)", fontSize: 12 }}
                  />
                  <Tooltip
                    cursor={{ fill: "var(--muted)" }}
                    contentStyle={{
                      background: "var(--card)",
                      borderColor: "var(--border)",
                      borderRadius: "var(--radius)",
                    }}
                    labelStyle={{ color: "var(--card-foreground)" }}
                    itemStyle={{ color: "var(--card-foreground)" }}
                  />
                  <Legend
                    iconType="square"
                    wrapperStyle={{ color: "var(--muted-foreground)" }}
                  />
                  <Bar
                    dataKey="enrolled"
                    name="Enrolled"
                    stackId="step"
                    fill={ENROLLED_FILL}
                    barSize={24}
                  />
                  <Bar
                    dataKey="ongoing"
                    name="Ongoing"
                    stackId="step"
                    fill={ONGOING_FILL}
                    barSize={24}
                  />
                </BarChart>
              </ResponsiveContainer>
            </div>
            <table className="sr-only">
              <caption>Students at each enrollment step</caption>
              <thead>
                <tr>
                  <th scope="col">Step</th>
                  <th scope="col">Students</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => (
                  <tr key={row.step}>
                    <th scope="row">{row.label}</th>
                    <td>{row.count}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </>
        )}
      </CardContent>
    </Card>
  )
}
