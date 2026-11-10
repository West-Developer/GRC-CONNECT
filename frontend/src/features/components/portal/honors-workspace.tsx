"use client"

import { useState } from "react"
import { useAuth } from "@/features/auth/use-auth"
import { AsyncBoundary } from "@/features/components/portal/async-boundary"
import { WorkspacePage } from "@/features/components/portal/workspace-page"
import { Button } from "@/features/components/ui/button"
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
} from "@/features/components/ui/card"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/features/components/ui/select"
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/features/components/ui/table"
import { useHonorsReportQuery } from "@/features/hooks/use-attrition-honors"
import {
  useAcademicTermsQuery,
  useProgramsQuery,
} from "@/features/hooks/use-reference-data"

function formatYearLevel(y: number): string {
  switch (y) {
    case 1:
      return "1st Year"
    case 2:
      return "2nd Year"
    case 3:
      return "3rd Year"
    case 4:
      return "4th Year"
    default:
      return `${y}th Year`
  }
}

export function HonorsWorkspace() {
  const { session } = useAuth()
  const authorized = session?.role === "dean"
  const terms = useAcademicTermsQuery({ enabled: authorized })
  const programs = useProgramsQuery({ enabled: authorized })
  const [termId, setTermId] = useState<number>()
  const [college, setCollege] = useState<string>()
  const [programId, setProgramId] = useState<number>()
  const [yearLevel, setYearLevel] = useState<number>()
  const [page, setPage] = useState(1)
  const effectiveTermId =
    termId ??
    terms.data?.find((term) => term.status === "semester_ongoing")?.id ??
    terms.data?.[0]?.id
  const report = useHonorsReportQuery({
    academicTermId: effectiveTermId,
    college,
    programId,
    yearLevel,
    page,
  })
  return (
    <WorkspacePage
      title="Dean's list"
      description="Live qualifications after all enrolled subjects have submitted or locked grades. GWA is 1.00 to 1.50 with a minimum of 16 units; NSTP, PATHFIT, and PE do not affect it."
      unauthorized={!authorized}
      lastUpdated={report.dataUpdatedAt}
    >
      <div className="flex flex-wrap gap-2">
        <Select
          onValueChange={(v) => {
            setTermId(Number(v))
            setPage(1)
          }}
        >
          <SelectTrigger className="w-56">
            <SelectValue placeholder="Academic term" />
          </SelectTrigger>
          <SelectContent>
            {terms.data?.map((t) => (
              <SelectItem key={t.id} value={String(t.id)}>
                {t.school_year} · {t.semester}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
        <Select
          onValueChange={(value) => {
            setCollege(value === "all" ? undefined : value)
            setPage(1)
          }}
        >
          <SelectTrigger className="w-56">
            <SelectValue placeholder="All colleges" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All colleges</SelectItem>
            <SelectItem value="ccs">College of Computer Studies</SelectItem>
            <SelectItem value="coe">College of Education</SelectItem>
            <SelectItem value="coa">College of Accountancy</SelectItem>
            <SelectItem value="cbae">
              College of Business Administration and Entrepreneurship
            </SelectItem>
          </SelectContent>
        </Select>
        <Select
          onValueChange={(v) => {
            setProgramId(v === "all" ? undefined : Number(v))
            setPage(1)
          }}
        >
          <SelectTrigger className="w-48">
            <SelectValue placeholder="All programs" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All programs</SelectItem>
            {programs.data?.map((p) => (
              <SelectItem key={p.id} value={String(p.id)}>
                {p.code}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
        <Select
          onValueChange={(v) => {
            setYearLevel(v === "all" ? undefined : Number(v))
            setPage(1)
          }}
        >
          <SelectTrigger className="w-36">
            <SelectValue placeholder="All years" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All years</SelectItem>
            {[1, 2, 3, 4].map((y) => (
              <SelectItem key={y} value={String(y)}>
                {formatYearLevel(y)}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
        <Button variant="outline" onClick={() => void report.refetch()}>
          Refresh
        </Button>
      </div>
      <AsyncBoundary
        query={report}
        isEmpty={(data) => data.data.length === 0}
        emptyMessage="No students qualify yet for this filter."
        loadingLabel="Checking submitted grades…"
      >
        {(data) => (
          <Card>
            <CardHeader>
              <CardTitle level={2}>
                {data.summary.qualifier_count} qualifiers
              </CardTitle>
            </CardHeader>
            <CardContent>
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Student</TableHead>
                    <TableHead>Year</TableHead>
                    <TableHead>GWA</TableHead>
                    <TableHead>Units</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {data.data.map((row) => (
                    <TableRow key={row.student_id}>
                      <TableCell>
                        <span className="font-medium">{row.student_name}</span>
                        <p className="text-xs text-muted-foreground">
                          {row.student_number}
                        </p>
                      </TableCell>
                      <TableCell>{formatYearLevel(row.year_level)}</TableCell>
                      <TableCell>{row.gwa}</TableCell>
                      <TableCell>{row.gwa_units}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
              <div className="mt-4 flex justify-end gap-2">
                <Button
                  variant="outline"
                  disabled={page <= 1}
                  onClick={() => setPage((value) => value - 1)}
                >
                  Previous
                </Button>
                <Button
                  variant="outline"
                  disabled={page >= data.meta.last_page}
                  onClick={() => setPage((value) => value + 1)}
                >
                  Next
                </Button>
              </div>
            </CardContent>
          </Card>
        )}
      </AsyncBoundary>
    </WorkspacePage>
  )
}
