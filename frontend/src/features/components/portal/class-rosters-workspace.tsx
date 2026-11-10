"use client"

import { useMemo, useState } from "react"
import {
  ClipboardCheck,
  Clock,
  DoorOpen,
  ListChecks,
  Users,
} from "lucide-react"

import { useAuth } from "@/features/auth/use-auth"
import { AsyncBoundary } from "@/features/components/portal/async-boundary"
import { DataTable } from "@/features/components/portal/data-table"
import { SectionGradeSheetPanel } from "@/features/components/portal/grade-submission-workspace"
import { WorkspacePage } from "@/features/components/portal/workspace-page"
import { Badge } from "@/features/components/ui/badge"
import { Button } from "@/features/components/ui/button"
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
} from "@/features/components/ui/card"
import { Field, FieldLabel } from "@/features/components/ui/field"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/features/components/ui/select"
import { useClassRosterQuery } from "@/features/hooks/use-class-roster"
import { useSectionsQuery } from "@/features/hooks/use-reference-data"
import { formatClockTime, formatTimeRange } from "@/features/lib/format-time"
import type { ClassRosterEntry } from "@/features/schemas/class-roster-schema"

function rosterBadgeVariant(
  status: ClassRosterEntry["status"],
): "default" | "secondary" | "outline" {
  if (status === "enrolled") return "default"
  if (status === "dropped") return "outline"
  return "secondary"
}

export function ClassRostersWorkspace() {
  const { session } = useAuth()
  const authorized = session?.role === "faculty"
  const [sectionId, setSectionId] = useState<number | null>(null)
  const [activeTab, setActiveTab] = useState<"roster" | "grades">("roster")

  const sectionsQuery = useSectionsQuery({ enabled: authorized })

  const facultyId = Number(session?.userId)
  const ownSections = useMemo(() => {
    if (!Number.isSafeInteger(facultyId) || facultyId <= 0) return []
    return (sectionsQuery.data ?? []).filter(
      (section) => section.professor_id === facultyId,
    )
  }, [sectionsQuery.data, facultyId])

  // Sort sections so published / active sections appear first
  const sortedSections = useMemo(() => {
    return [...ownSections].sort((a, b) => {
      if (a.status === "published" && b.status !== "published") return -1
      if (b.status === "published" && a.status !== "published") return 1
      return b.academic_term_id - a.academic_term_id
    })
  }, [ownSections])

  const selectedSection = useMemo(() => {
    return ownSections.find((s) => s.id === sectionId) ?? null
  }, [ownSections, sectionId])

  const rosterQuery = useClassRosterQuery(
    { section_id: sectionId ?? undefined, page: 1, per_page: 100 },
    { enabled: authorized && sectionId !== null },
  )

  return (
    <WorkspacePage
      title="Class rosters"
      description="Review enrolled class rosters with student names, and encode or submit official grades in one unified workspace."
      unauthorized={!authorized}
      lastUpdated={rosterQuery.dataUpdatedAt}
    >
      <Card>
        <CardHeader className="pb-3">
          <CardTitle level={2}>Select a section</CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          <AsyncBoundary
            query={{ ...sectionsQuery, data: ownSections }}
            isEmpty={(sections) => sections.length === 0}
            emptyMessage="No sections are currently assigned to your faculty account."
            loadingLabel="Loading your assigned sections…"
          >
            {() => (
              <Field>
                <FieldLabel htmlFor="roster-section">Section</FieldLabel>
                <Select
                  value={sectionId !== null ? String(sectionId) : ""}
                  onValueChange={(value) => setSectionId(Number(value))}
                >
                  <SelectTrigger id="roster-section" className="w-full">
                    <SelectValue placeholder="Choose a section" />
                  </SelectTrigger>
                  <SelectContent>
                    {sortedSections.map((section) => (
                      <SelectItem key={section.id} value={String(section.id)}>
                        Section {section.section_code} ({section.status_label})
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </Field>
            )}
          </AsyncBoundary>
        </CardContent>
      </Card>

      {sectionId !== null && (
        <div className="space-y-4">
          {/* Section Summary & Workspace Mode Switcher */}
          {selectedSection && (
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 rounded-xl border bg-card p-4 shadow-xs">
              <div className="space-y-1">
                <div className="flex items-center gap-2">
                  <Badge variant="outline" className="font-mono text-xs">
                    Section {selectedSection.section_code}
                  </Badge>
                  <span className="font-semibold text-foreground text-sm">
                    {selectedSection.status_label}
                  </span>
                </div>
                <div className="flex flex-wrap items-center gap-3 text-xs text-muted-foreground pt-1">
                  {selectedSection.room && (
                    <span className="inline-flex items-center gap-1 font-medium text-primary">
                      <DoorOpen className="size-3.5" aria-hidden="true" />
                      Room {selectedSection.room}
                    </span>
                  )}
                  {selectedSection.schedule_days && selectedSection.starts_at_time && (
                    <span className="inline-flex items-center gap-1">
                      <Clock className="size-3.5" aria-hidden="true" />
                      {selectedSection.schedule_days} ·{" "}
                      {selectedSection.ends_at_time
                        ? formatTimeRange(
                            selectedSection.starts_at_time,
                            selectedSection.ends_at_time,
                          )
                        : formatClockTime(selectedSection.starts_at_time)}
                    </span>
                  )}
                  <span className="inline-flex items-center gap-1">
                    <Users className="size-3.5" aria-hidden="true" />
                    {selectedSection.enrolled_count} enrolled students
                  </span>
                </div>
              </div>

              {/* View Switcher: Class Roster vs Grade Sheet */}
              <div className="flex items-center gap-1 rounded-lg border bg-muted/30 p-1 shrink-0" role="group" aria-label="Roster view mode">
                <Button
                  type="button"
                  variant={activeTab === "roster" ? "default" : "ghost"}
                  size="sm"
                  className="h-8 gap-1.5 text-xs font-medium"
                  onClick={() => setActiveTab("roster")}
                >
                  <ListChecks className="size-3.5" aria-hidden="true" />
                  Class Roster
                </Button>
                <Button
                  type="button"
                  variant={activeTab === "grades" ? "default" : "ghost"}
                  size="sm"
                  className="h-8 gap-1.5 text-xs font-medium"
                  onClick={() => setActiveTab("grades")}
                >
                  <ClipboardCheck className="size-3.5" aria-hidden="true" />
                  Grade Sheet & Submission
                </Button>
              </div>
            </div>
          )}

          {/* Active Tab Content */}
          {activeTab === "grades" ? (
            <SectionGradeSheetPanel sectionId={sectionId} />
          ) : (
            <Card>
              <CardHeader className="flex flex-row items-center justify-between pb-3">
                <CardTitle level={2}>Roster</CardTitle>
                <span className="text-xs text-muted-foreground">
                  Official enrolled student directory
                </span>
              </CardHeader>
              <CardContent>
                <AsyncBoundary
                  query={{ ...rosterQuery, data: rosterQuery.data?.data }}
                  isEmpty={(rows) => rows.length === 0}
                  emptyMessage="No students are enrolled in this section yet."
                  loadingLabel="Loading the class roster…"
                >
                  {(rows) => (
                    <DataTable
                      caption="Class roster"
                      rowKey={(entry) => entry.id}
                      rows={rows}
                      renderCard={(entry) => (
                        <Card role="article" aria-label={entry.student_number}>
                          <CardHeader>
                            <CardTitle level={3}>{entry.student_number}</CardTitle>
                            {entry.student_name && (
                              <p className="font-semibold text-foreground text-sm">
                                {entry.student_name}
                              </p>
                            )}
                          </CardHeader>
                          <CardContent>
                            <dl className="grid grid-cols-2 gap-2 text-sm">
                              <dt>Subject</dt>
                              <dd>{entry.subject_code}</dd>
                            </dl>
                            <Badge
                              className="mt-3"
                              variant={rosterBadgeVariant(entry.status)}
                            >
                              {entry.status_label}
                            </Badge>
                          </CardContent>
                        </Card>
                      )}
                      columns={[
                        {
                          key: "student",
                          header: "Student",
                          render: (entry) => (
                            <span className="font-mono font-medium">{entry.student_number}</span>
                          ),
                        },
                        {
                          key: "name",
                          header: "Student name",
                          render: (entry) => (
                            <span className="font-medium text-foreground">
                              {entry.student_name ?? "—"}
                            </span>
                          ),
                        },
                        {
                          key: "subject",
                          header: "Subject",
                          render: (entry) => entry.subject_code,
                        },
                        {
                          key: "status",
                          header: "Status",
                          render: (entry) => (
                            <Badge variant={rosterBadgeVariant(entry.status)}>
                              {entry.status_label}
                            </Badge>
                          ),
                        },
                      ]}
                    />
                  )}
                </AsyncBoundary>
              </CardContent>
            </Card>
          )}
        </div>
      )}
    </WorkspacePage>
  )
}
