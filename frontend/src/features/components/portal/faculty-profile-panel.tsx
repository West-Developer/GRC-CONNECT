"use client"

import { useMemo, useState } from "react"

import { AsyncBoundary } from "@/features/components/portal/async-boundary"
import { DataTable } from "@/features/components/portal/data-table"
import {
  SectionScheduleCalendar,
  type SectionScheduleItem,
} from "@/features/components/portal/section-schedule-calendar"
import { Badge } from "@/features/components/ui/badge"
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
} from "@/features/components/ui/card"
import {
  Tabs,
  TabsContent,
  TabsList,
  TabsTrigger,
} from "@/features/components/ui/tabs"
import { useFacultyProfileQuery } from "@/features/hooks/use-faculty-profile"
import { formatTimeRange } from "@/features/lib/format-time"
import type { FacultyProfileSection } from "@/features/schemas/faculty-profile-schema"

/** How far one section's grade submission has got, in words. */
function gradeProgress(grades: FacultyProfileSection["grades"]): string {
  if (grades.total === 0) return "No grades entered yet"
  if (grades.locked === grades.total) return "All locked"
  if (grades.draft === 0) return `${grades.total - grades.locked} awaiting lock`
  return `${grades.draft} still draft`
}

/**
 * The Registrar Head's read-only profile of one professor (stakeholder
 * Doc 14): employment information, the terms they taught with their units, the
 * weekly timetable exactly as the professor sees it, and each section's
 * grade-submission counts. Counts only, no student is named.
 */
export function FacultyProfilePanel({ professorId }: { professorId: number }) {
  const [termId, setTermId] = useState<number | null>(null)
  const profileQuery = useFacultyProfileQuery(professorId, termId)
  const profile = profileQuery.data
  const sections = useMemo(
    () => profile?.selected_term?.sections ?? [],
    [profile],
  )
  const calendarItems = useMemo<readonly SectionScheduleItem[]>(
    () =>
      sections.map((section) => ({
        id: section.section_id,
        subject_code: section.subject_code,
        subject_title: section.subject_title,
        units: section.units,
        section_code: section.section_code,
        room: section.room,
        professor_name: profile?.professor.name ?? null,
        schedule_days: section.schedule_days,
        starts_at_time: section.starts_at_time,
        ends_at_time: section.ends_at_time,
        modality: section.modality,
        enrolled_count: section.enrolled_count,
        capacity: section.capacity,
      })),
    [sections, profile],
  )

  return (
    <AsyncBoundary
      query={profileQuery}
      loadingLabel="Loading the professor's teaching profile…"
    >
      {(data) => (
        <div className="grid gap-4">
          <div className="flex flex-wrap items-center gap-2">
            <Badge variant="secondary">
              {data.professor.employment_type_label ??
                "Employment type not recorded"}
            </Badge>
            {data.professor.college && (
              <Badge variant="outline">
                {data.professor.college.toUpperCase()}
              </Badge>
            )}
            <Badge
              variant={
                data.professor.status === "active" ? "secondary" : "destructive"
              }
            >
              {data.professor.status === "active" ? "Active" : "Inactive"}
            </Badge>
            {data.professor.masters_degree && (
              <span className="text-sm text-muted-foreground">
                {data.professor.masters_degree}
              </span>
            )}
          </div>

          {data.terms.length === 0 || data.selected_term === null ? (
            <p className="rounded-lg border border-dashed p-5 text-sm text-muted-foreground">
              This professor has not been assigned any section yet.
            </p>
          ) : (
            <>
              <div className="flex flex-wrap items-end justify-between gap-3">
                <label className="grid gap-1.5 text-sm font-medium">
                  Term
                  <select
                    aria-label="Term"
                    value={data.selected_term.academic_term_id}
                    onChange={(event) => setTermId(Number(event.target.value))}
                    className="h-9 rounded-md border bg-background px-2"
                  >
                    {data.terms.map((term) => (
                      <option
                        key={term.academic_term_id}
                        value={term.academic_term_id}
                      >
                        {term.label} · {term.sections_count}{" "}
                        {term.sections_count === 1 ? "section" : "sections"} ·{" "}
                        {term.total_units} units
                      </option>
                    ))}
                  </select>
                </label>
                <p className="text-sm">
                  <strong className="font-heading text-xl">
                    {data.selected_term.total_units}
                  </strong>{" "}
                  units in {data.selected_term.sections.length}{" "}
                  {data.selected_term.sections.length === 1
                    ? "section"
                    : "sections"}
                </p>
              </div>

              <Tabs defaultValue="timetable" className="gap-3">
                <TabsList aria-label="Professor profile views">
                  <TabsTrigger value="timetable">Weekly timetable</TabsTrigger>
                  <TabsTrigger value="sections">
                    Sections and grades
                  </TabsTrigger>
                </TabsList>

                <TabsContent value="timetable">
                  <Card>
                    <CardHeader>
                      <CardTitle level={3}>Weekly timetable</CardTitle>
                    </CardHeader>
                    <CardContent>
                      <SectionScheduleCalendar
                        items={calendarItems}
                        emptyMessage="No scheduled classes for this term."
                      />
                    </CardContent>
                  </Card>
                </TabsContent>

                <TabsContent value="sections">
                  <DataTable
                    caption="Sections and grade submission"
                    rowKey={(section) => section.section_id}
                    rows={data.selected_term.sections}
                    columns={[
                      {
                        key: "subject",
                        header: "Subject",
                        render: (section) => (
                          <span>
                            <span className="font-medium">
                              {section.subject_code}
                            </span>{" "}
                            · Section {section.section_code}
                            <span className="block text-xs text-muted-foreground">
                              {section.subject_title} · {section.units} units
                            </span>
                          </span>
                        ),
                      },
                      {
                        key: "schedule",
                        header: "Schedule",
                        render: (section) =>
                          section.schedule_days &&
                          section.starts_at_time &&
                          section.ends_at_time
                            ? `${section.schedule_days} ${formatTimeRange(section.starts_at_time, section.ends_at_time)}${section.room ? ` · ${section.room}` : ""}`
                            : "Not scheduled",
                      },
                      {
                        key: "seats",
                        header: "Students",
                        render: (section) =>
                          `${section.enrolled_count} of ${section.capacity}`,
                      },
                      {
                        key: "grades",
                        header: "Grade submission",
                        render: (section) => (
                          <span className="grid gap-1">
                            <span className="flex flex-wrap gap-1">
                              <Badge variant="outline">
                                Draft {section.grades.draft}
                              </Badge>
                              <Badge variant="outline">
                                Submitted {section.grades.submitted}
                              </Badge>
                              <Badge variant="outline">
                                Locked {section.grades.locked}
                              </Badge>
                            </span>
                            <span className="text-xs text-muted-foreground">
                              {gradeProgress(section.grades)}
                            </span>
                          </span>
                        ),
                      },
                    ]}
                  />
                </TabsContent>
              </Tabs>
            </>
          )}
        </div>
      )}
    </AsyncBoundary>
  )
}
