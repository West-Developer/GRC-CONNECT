"use client"

import { useState } from "react"
import { useAuth } from "@/features/auth/use-auth"
import { AsyncBoundary } from "@/features/components/portal/async-boundary"
import { WorkspacePage } from "@/features/components/portal/workspace-page"
import { Badge } from "@/features/components/ui/badge"
import { Button } from "@/features/components/ui/button"
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
} from "@/features/components/ui/card"
import { Input } from "@/features/components/ui/input"
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
import { useDebouncedValue } from "@/features/hooks/use-debounced-value"
import { useGraduatesQuery } from "@/features/hooks/use-graduates"
import {
  useCurriculaQuery,
  useProgramsQuery,
} from "@/features/hooks/use-reference-data"

const GRADUATION_YEARS = [
    "2017-2018",
    "2018-2019",
    "2019-2020",
    "2020-2021",
    "2021-2022",
    "2022-2023",
    "2023-2024",
    "2024-2025",
    "2025-2026",
    "2026-2027",
]

export function GraduatesWorkspace() {
  const { session } = useAuth()
  const authorized =
    session?.role === "registrar_head" ||
    session?.role === "registrar_staff" ||
    session?.role === "dean" ||
    session?.role === "executive_director" ||
    session?.role === "it_admin"

  const programs = useProgramsQuery({ enabled: authorized })
  const curricula = useCurriculaQuery({ enabled: authorized })

  const [programId, setProgramId] = useState<number>()
  const [graduationSchoolYear, setGraduationSchoolYear] = useState<string>()
  const [curriculumId, setCurriculumId] = useState<number>()
  const [search, setSearch] = useState<string>("")
  const [page, setPage] = useState(1)
  // Query the server 300 ms after typing stops, not on every keystroke.
  const debouncedSearch = useDebouncedValue(search, 300)

  const graduatesQuery = useGraduatesQuery({
    programId,
    graduationSchoolYear,
    curriculumId,
    search: debouncedSearch ? debouncedSearch : undefined,
    page,
    perPage: 25,
  })

  const summary = graduatesQuery.data?.summary
  const meta = graduatesQuery.data?.meta

  return (
    <WorkspacePage
      title="Graduates directory"
      description="Official register of graduated students across all programs, school years, and curriculum versions."
      unauthorized={!authorized}
      lastUpdated={graduatesQuery.dataUpdatedAt}
    >
      <div className="flex flex-wrap items-center gap-3">
        <Input
          placeholder="Search by student name or ID..."
          aria-label="Search graduates"
          value={search}
          onChange={(e) => {
            setSearch(e.target.value)
            setPage(1)
          }}
          className="w-full sm:w-64"
        />

        <Select
          onValueChange={(value) => {
            setProgramId(value === "all" ? undefined : Number(value))
            setPage(1)
          }}
        >
          <SelectTrigger className="w-full sm:w-56" aria-label="Filter by program">
            <SelectValue placeholder="All programs" />
          </SelectTrigger>
          <SelectContent className="max-h-60 overflow-y-auto">
            <SelectItem value="all">All programs</SelectItem>
            {programs.data?.map((p) => (
              <SelectItem key={p.id} value={String(p.id)}>
                {p.code} — {p.name}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>

        <Select
          onValueChange={(value) => {
            setGraduationSchoolYear(value === "all" ? undefined : value)
            setPage(1)
          }}
        >
          <SelectTrigger className="w-full sm:w-48" aria-label="Filter by graduation school year">
            <SelectValue placeholder="All graduation years" />
          </SelectTrigger>
          <SelectContent className="max-h-60 overflow-y-auto">
            <SelectItem value="all">All graduation years</SelectItem>
            {GRADUATION_YEARS.map((sy) => (
              <SelectItem key={sy} value={sy}>
                SY {sy}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>

        <Select
          onValueChange={(value) => {
            setCurriculumId(value === "all" ? undefined : Number(value))
            setPage(1)
          }}
        >
          <SelectTrigger className="w-full sm:w-48" aria-label="Filter by curriculum version">
            <SelectValue placeholder="All curricula" />
          </SelectTrigger>
          <SelectContent className="max-h-60 overflow-y-auto">
            <SelectItem value="all">All curricula</SelectItem>
            {curricula.data?.map((c) => (
              <SelectItem key={c.id} value={String(c.id)}>
                {c.name} ({c.effective_school_year})
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      <Card>
        <CardHeader className="flex flex-row items-center justify-between pb-2">
          <CardTitle className="text-base font-semibold">
            {summary ? `${summary.total_graduates} Confirmed Graduates` : "Graduates List"}
          </CardTitle>
          {meta && (
            <div className="text-xs text-muted-foreground">
              Page {meta.current_page} of {meta.last_page} ({meta.total} total)
            </div>
          )}
        </CardHeader>
        <CardContent className="p-0">
          <AsyncBoundary
            query={graduatesQuery}
            loadingLabel="Loading graduates..."
          >
            {(response) => (
              <div className="overflow-x-auto">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>Student</TableHead>
                      <TableHead>Program</TableHead>
                      <TableHead>Curriculum</TableHead>
                      <TableHead>Graduation Year</TableHead>
                      <TableHead className="text-right">Final GPA</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {response.data.length === 0 ? (
                      <TableRow>
                        <TableCell colSpan={5} className="py-8 text-center text-muted-foreground">
                          No graduates found matching your search and filters.
                        </TableCell>
                      </TableRow>
                    ) : (
                      response.data.map((g) => (
                        <TableRow key={g.id}>
                          <TableCell>
                            <p className="font-medium">{g.full_name}</p>
                            <p className="text-xs text-muted-foreground font-mono">
                              {g.student_number}
                            </p>
                          </TableCell>
                          <TableCell>
                            <div className="flex items-center gap-1.5">
                              <Badge variant="outline" className="font-medium text-xs">
                                {g.program_code}
                              </Badge>
                              <span className="text-xs text-muted-foreground hidden md:inline">
                                {g.program_name}
                              </span>
                            </div>
                          </TableCell>
                          <TableCell>
                            <Badge variant="secondary" className="font-mono text-xs">
                              {g.curriculum_version ?? "—"}
                            </Badge>
                          </TableCell>
                          <TableCell>
                            <span className="text-sm font-medium">
                              {g.graduation_school_year ? `SY ${g.graduation_school_year}` : "—"}
                            </span>
                          </TableCell>
                          <TableCell className="text-right">
                            {g.final_gpa !== null && g.final_gpa !== undefined ? (
                              <Badge
                                variant={g.final_gpa <= 1.75 ? "default" : "outline"}
                                className="font-mono font-semibold"
                              >
                                {g.final_gpa.toFixed(2)}
                              </Badge>
                            ) : (
                              <span className="text-xs text-muted-foreground">—</span>
                            )}
                          </TableCell>
                        </TableRow>
                      ))
                    )}
                  </TableBody>
                </Table>
              </div>
            )}
          </AsyncBoundary>
        </CardContent>
      </Card>

      {meta && meta.last_page > 1 && (
        <div className="flex items-center justify-end gap-2 pt-2">
          <Button
            variant="outline"
            size="sm"
            disabled={meta.current_page <= 1}
            onClick={() => setPage((p) => Math.max(1, p - 1))}
          >
            Previous
          </Button>
          <span className="text-xs text-muted-foreground px-2">
            {meta.current_page} / {meta.last_page}
          </span>
          <Button
            variant="outline"
            size="sm"
            disabled={meta.current_page >= meta.last_page}
            onClick={() => setPage((p) => p + 1)}
          >
            Next
          </Button>
        </div>
      )}
    </WorkspacePage>
  )
}
