"use client"

import { useState } from "react"

import { useAuth } from "@/features/auth/use-auth"
import { AsyncBoundary } from "@/features/components/portal/async-boundary"
import { DataTable } from "@/features/components/portal/data-table"
import { Paginator } from "@/features/components/portal/paginator"
import { StudentInfoDialog } from "@/features/components/portal/student-info-dialog"
import { WorkspacePage } from "@/features/components/portal/workspace-page"
import { Badge } from "@/features/components/ui/badge"
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
} from "@/features/components/ui/card"
import { Input } from "@/features/components/ui/input"
import { useDebouncedValue } from "@/features/hooks/use-debounced-value"
import { useRegistrarStudentsQuery } from "@/features/hooks/use-registrar-students"
import { formatYearLevel } from "@/features/lib/format-year-level"

function categoryLabel(category: string | null): string {
  if (category === "regular") return "Regular"
  if (category === "irregular") return "Irregular"
  return "—"
}

/**
 * Registrar Staff and the Registrar Head: a read-only list of every student. The Registrar Head
 * opens a student's details by clicking the name; the profile API answers only for the Registrar
 * Head, so Registrar Staff see the name as plain text.
 */
export function RegistrarStudentsWorkspace() {
  const { session } = useAuth()
  const authorized =
    session?.role === "registrar_staff" || session?.role === "registrar_head"
  const canViewStudentInfo = session?.role === "registrar_head"
  const [search, setSearch] = useState("")
  const [page, setPage] = useState(1)
  const [infoStudentId, setInfoStudentId] = useState<number | null>(null)
  const debouncedSearch = useDebouncedValue(search, 300)

  const query = useRegistrarStudentsQuery({
    ...(debouncedSearch.trim() ? { search: debouncedSearch.trim() } : {}),
    page,
    per_page: 20,
  })

  return (
    <WorkspacePage
      title="Students"
      description={
        canViewStudentInfo
          ? "Every student in the system. Select a student's name to see their details. This list is for viewing only."
          : "Every student in the system. This list is for viewing only."
      }
      unauthorized={!authorized}
      lastUpdated={query.dataUpdatedAt}
    >
      <Card>
        <CardHeader>
          <CardTitle level={2}>Students</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="mb-4 w-full max-w-sm">
            <Input
              type="search"
              placeholder="Search by student number or name…"
              aria-label="Search students"
              value={search}
              onChange={(event) => {
                setSearch(event.target.value)
                setPage(1)
              }}
            />
          </div>
          <AsyncBoundary
            query={{ ...query, data: query.data?.data }}
            isEmpty={(rows) => rows.length === 0}
            emptyMessage="No students match this search."
            loadingLabel="Loading the students…"
          >
            {(rows) => (
              <DataTable
                caption="Students"
                rowKey={(student) => student.id}
                rows={rows}
                columns={[
                  {
                    key: "student",
                    header: "Student",
                    render: (student) => (
                      <div>
                        {canViewStudentInfo ? (
                          <button
                            type="button"
                            className="text-left font-medium underline-offset-2 hover:underline focus-visible:underline"
                            onClick={() => setInfoStudentId(student.id)}
                          >
                            {student.name}
                            <span className="sr-only">
                              {" "}
                              ({student.student_number}) — open student
                              information
                            </span>
                          </button>
                        ) : (
                          <div className="font-medium">{student.name}</div>
                        )}
                        <div className="text-xs text-muted-foreground">
                          {student.student_number}
                        </div>
                      </div>
                    ),
                  },
                  {
                    key: "program",
                    header: "Program",
                    render: (student) =>
                      `${student.program_code} · ${formatYearLevel(student.year_level)}`,
                  },
                  {
                    key: "category",
                    header: "Category",
                    render: (student) =>
                      categoryLabel(student.enrollment_category),
                  },
                  {
                    key: "standing",
                    header: "Standing",
                    render: (student) => (
                      <Badge
                        variant={
                          student.academic_standing === "good"
                            ? "success"
                            : "warning"
                        }
                      >
                        {student.academic_standing_label}
                      </Badge>
                    ),
                  },
                ]}
              />
            )}
          </AsyncBoundary>
          <div className="mt-4">
            <Paginator
              currentPage={query.data?.meta.current_page ?? 1}
              lastPage={query.data?.meta.last_page ?? 1}
              onPageChange={setPage}
            />
          </div>
        </CardContent>
      </Card>
      {canViewStudentInfo && (
        <StudentInfoDialog
          studentId={infoStudentId}
          onOpenChange={(open) => {
            if (!open) setInfoStudentId(null)
          }}
        />
      )}
    </WorkspacePage>
  )
}
