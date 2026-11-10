"use client"

import { useMemo, useState } from "react"
import {
  CalendarDays,
  GraduationCap,
  ListIcon,
  User,
  BookOpen,
  ArrowRight,
} from "lucide-react"
import Link from "next/link"

import { useAuth } from "@/features/auth/use-auth"
import { AcademicTermSelector } from "@/features/components/portal/academic-term-selector"
import { AsyncBoundary } from "@/features/components/portal/async-boundary"
import { DataTable } from "@/features/components/portal/data-table"
import {
  SectionScheduleCalendar,
  type SectionScheduleItem,
} from "@/features/components/portal/section-schedule-calendar"
import { WorkspacePage } from "@/features/components/portal/workspace-page"
import { Badge } from "@/features/components/ui/badge"
import { Button } from "@/features/components/ui/button"
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/features/components/ui/card"
import {
  Empty,
  EmptyContent,
  EmptyDescription,
  EmptyHeader,
  EmptyMedia,
  EmptyTitle,
} from "@/features/components/ui/empty"
import { Skeleton } from "@/features/components/ui/skeleton"
import {
  ToggleGroup,
  ToggleGroupItem,
} from "@/features/components/ui/toggle-group"
import { useEnrollmentsQuery } from "@/features/hooks/use-enrollment"
import { useAcademicTermsQuery } from "@/features/hooks/use-reference-data"
import { useTermSelection } from "@/features/hooks/use-term-selection"
import { formatTimeRange } from "@/features/lib/format-time"
import { groupPairedSubjects } from "@/features/lib/group-paired-subjects"
import { compareBySchedule } from "@/features/lib/schedule-order"
import {
  formatAcademicTerm,
  getActiveAcademicTerm,
} from "@/features/services/reference-data-service"

const TERMINAL_STATUSES = new Set(["rejected", "cancelled", "withdrawn"])

export function StudentScheduleWorkspace() {
  const { session } = useAuth()
  const authorized = session?.role === "student"

  const termsQuery = useAcademicTermsQuery()
  const enrollmentsQuery = useEnrollmentsQuery({
    enabled: authorized,
  })

  // Only the school years and semesters the student has data for are offered (owner, 2026-10-13):
  // the term they are enrolled in now, and every term they have an enrollment with subjects in.
  // The list of every academic term back to the first one is not theirs to browse.
  const studentTerms = useMemo(() => {
    const termIdsWithSchedule = new Set(
      (enrollmentsQuery.data ?? [])
        .filter((enrollment) =>
          (enrollment.subjects ?? []).some((s) => s.status !== "dropped"),
        )
        .map((enrollment) => enrollment.academic_term_id),
    )

    const currentTermId = getActiveAcademicTerm(termsQuery.data)?.id

    return (termsQuery.data ?? []).filter(
      (term) => term.id === currentTermId || termIdsWithSchedule.has(term.id),
    )
  }, [termsQuery.data, enrollmentsQuery.data])

  const { selectedTermId, setSelectedTermId } = useTermSelection(studentTerms)

  const [view, setView] = useState<"calendar" | "table">("calendar")

  const selectedTerm = useMemo(
    () => studentTerms.find((term) => term.id === selectedTermId),
    [studentTerms, selectedTermId],
  )

  const activeEnrollment = useMemo(() => {
    const all = enrollmentsQuery.data ?? []
    return (
      all.find(
        (e) =>
          e.academic_term_id === selectedTermId &&
          !TERMINAL_STATUSES.has(e.status),
      ) ??
      all.find((e) => e.academic_term_id === selectedTermId) ??
      null
    )
  }, [enrollmentsQuery.data, selectedTermId])

  const subjects = useMemo(
    () =>
      (activeEnrollment?.subjects ?? []).filter((s) => s.status !== "dropped"),
    [activeEnrollment],
  )

  const sortedSubjects = useMemo(() => {
    const sorted = [...subjects].sort((a, b) => {
      const aSched = {
        schedule_days: a.schedule_days ?? null,
        starts_at_time: a.starts_at_time ?? null,
        ends_at_time: a.ends_at_time ?? null,
      }
      const bSched = {
        schedule_days: b.schedule_days ?? null,
        starts_at_time: b.starts_at_time ?? null,
        ends_at_time: b.ends_at_time ?? null,
      }
      return compareBySchedule(aSched, bSched)
    })
    return groupPairedSubjects(sorted)
  }, [subjects])

  const calendarItems: SectionScheduleItem[] = useMemo(() => {
    return subjects.map((subj) => ({
      id: subj.section_id,
      subject_code: subj.subject_code,
      subject_title: subj.subject_title,
      units: subj.units,
      section_code: subj.section_code,
      room: subj.room,
      professor_name: subj.professor_name,
      schedule_days: subj.schedule_days ?? null,
      starts_at_time: subj.starts_at_time ?? null,
      ends_at_time: subj.ends_at_time ?? null,
      modality: subj.modality ?? null,
    }))
  }, [subjects])

  const sectionCodes = useMemo(() => {
    const unique = new Set<string>()
    for (const subj of subjects) {
      if (subj.section_code) unique.add(subj.section_code)
    }
    return Array.from(unique).sort()
  }, [subjects])

  return (
    <WorkspacePage
      title="Schedule"
      description="View your weekly class timetable, professor assignments, and room allocations."
      unauthorized={!authorized}
    >
      <div className="flex flex-wrap items-center justify-between gap-3 border-b pb-4">
        <AcademicTermSelector
          sortedTerms={studentTerms}
          term={selectedTerm ?? null}
          isCurrentTerm={selectedTerm?.status === "semester_ongoing"}
          onSelectTerm={setSelectedTermId}
          isLoading={termsQuery.isPending}
          readOnly
        />
        {selectedTerm && (
          <Badge variant="outline" className="text-xs">
            {formatAcademicTerm(selectedTerm)}
          </Badge>
        )}
      </div>

      <AsyncBoundary
        query={{
          isPending: termsQuery.isPending || enrollmentsQuery.isPending,
          isError: termsQuery.isError || enrollmentsQuery.isError,
          error: termsQuery.error ?? enrollmentsQuery.error,
          data: enrollmentsQuery.data,
          refetch: () => {
            void termsQuery.refetch()
            void enrollmentsQuery.refetch()
          },
        }}
        loadingLabel="Loading your class schedule…"
        loadingFallback={<Skeleton className="h-64" />}
      >
        {() =>
          !activeEnrollment || subjects.length === 0 ? (
            <Card>
              <CardContent className="py-8">
                <Empty
                  role="region"
                  aria-label="No enrolled schedule found"
                >
                  <EmptyHeader>
                    <EmptyMedia variant="icon">
                      <CalendarDays className="size-8" aria-hidden="true" />
                    </EmptyMedia>
                    <EmptyTitle>No class schedule for this term</EmptyTitle>
                    <EmptyDescription>
                      You do not have an active enrollment or scheduled subjects
                      for{" "}
                      {selectedTerm
                        ? formatAcademicTerm(selectedTerm)
                        : "the selected term"}
                      . Submit your section or subject selections in the
                      Enrollment module to generate your weekly schedule.
                    </EmptyDescription>
                  </EmptyHeader>
                  <EmptyContent>
                    <Button asChild>
                      <Link href="/portal/enrollment">
                        Go to Enrollment
                        <ArrowRight
                          data-icon="inline-end"
                          aria-hidden="true"
                        />
                      </Link>
                    </Button>
                  </EmptyContent>
                </Empty>
              </CardContent>
            </Card>
          ) : (
            <div className="grid gap-6">
              {/* Top Overview Cards */}
              {/* 2x2 and icon-free on a phone so the timetable is reached without
                  scrolling past four tall cards (stakeholder Doc 13). */}
              <div className="grid grid-cols-2 gap-2 sm:gap-4 lg:grid-cols-4">
                <Card>
                  <CardContent className="flex items-center gap-3 p-3 sm:p-4">
                    <div className="hidden size-10 place-items-center rounded-lg bg-primary/10 text-primary sm:grid">
                      <CalendarDays className="size-5" aria-hidden="true" />
                    </div>
                    <div className="min-w-0">
                      <p className="text-xs text-muted-foreground">Section</p>
                      <p className="truncate font-semibold text-foreground">
                        {sectionCodes.length > 0
                          ? sectionCodes.join(", ")
                          : "Custom sections"}
                      </p>
                    </div>
                  </CardContent>
                </Card>

                <Card>
                  <CardContent className="flex items-center gap-3 p-3 sm:p-4">
                    <div className="hidden size-10 place-items-center rounded-lg bg-primary/10 text-primary sm:grid">
                      <BookOpen className="size-5" aria-hidden="true" />
                    </div>
                    <div className="min-w-0">
                      <p className="text-xs text-muted-foreground">
                        Total Units
                      </p>
                      <p className="truncate font-semibold text-foreground">
                        {activeEnrollment.total_units} units
                      </p>
                    </div>
                  </CardContent>
                </Card>

                <Card>
                  <CardContent className="flex items-center gap-3 p-3 sm:p-4">
                    <div className="hidden size-10 place-items-center rounded-lg bg-primary/10 text-primary sm:grid">
                      <GraduationCap className="size-5" aria-hidden="true" />
                    </div>
                    <div className="min-w-0">
                      <p className="text-xs text-muted-foreground">Subjects</p>
                      <p className="truncate font-semibold text-foreground">
                        {subjects.length} enrolled class
                        {subjects.length === 1 ? "" : "es"}
                      </p>
                    </div>
                  </CardContent>
                </Card>

                <Card>
                  <CardContent className="flex items-center gap-3 p-3 sm:p-4">
                    <div className="hidden size-10 place-items-center rounded-lg bg-primary/10 text-primary sm:grid">
                      <User className="size-5" aria-hidden="true" />
                    </div>
                    <div className="min-w-0">
                      <p className="text-xs text-muted-foreground">Status</p>
                      <Badge variant="secondary" className="mt-0.5">
                        {activeEnrollment.status_label}
                      </Badge>
                    </div>
                  </CardContent>
                </Card>
              </div>

              {/* Main Schedule Container with Dual View Switcher */}
              <Card role="region" aria-label="Weekly class schedule">
                <CardHeader className="flex flex-col gap-3 border-b pb-4 sm:flex-row sm:items-center sm:justify-between">
                  <div>
                    <CardTitle
                      level={2}
                      className="flex items-center gap-2 text-lg font-bold"
                    >
                      <CalendarDays
                        className="size-5 text-primary"
                        aria-hidden="true"
                      />
                      Weekly Class Timetable
                    </CardTitle>
                    <CardDescription>
                      Scheduled class hours, room allocations, and professor
                      assignments.
                    </CardDescription>
                  </div>
                  <div className="flex flex-wrap items-center gap-2">
                    <ToggleGroup
                      type="single"
                      value={view}
                      onValueChange={(val) => {
                        if (val === "table" || val === "calendar") setView(val)
                      }}
                      variant="outline"
                      size="sm"
                      aria-label="Schedule layout view"
                    >
                      <ToggleGroupItem value="calendar" aria-label="View in calendar">
                        <CalendarDays
                          data-icon="inline-start"
                          aria-hidden="true"
                        />
                        Calendar view
                      </ToggleGroupItem>
                      <ToggleGroupItem value="table" aria-label="Schedule list">
                        <ListIcon
                          data-icon="inline-start"
                          aria-hidden="true"
                        />
                        Table view
                      </ToggleGroupItem>
                    </ToggleGroup>
                  </div>
                </CardHeader>
                <CardContent className="pt-4">
                  {view === "calendar" ? (
                    <SectionScheduleCalendar
                      items={calendarItems}
                      disabled={true}
                      emptyMessage="No weekly timetable slots scheduled."
                    />
                  ) : (
                    <DataTable
                      caption="Student weekly class schedule"
                      rowKey={(subj) => subj.section_id}
                      rows={sortedSubjects}
                      columns={[
                        {
                          key: "code",
                          header: "Subject code",
                          render: (subj) => (
                            <span className="font-semibold">
                              {subj.subject_code}
                            </span>
                          ),
                        },
                        {
                          key: "title",
                          header: "Description",
                          render: (subj) => subj.subject_title,
                        },
                        {
                          key: "units",
                          header: "Units",
                          render: (subj) => subj.units ?? "—",
                        },
                        {
                          key: "section",
                          header: "Section",
                          render: (subj) => subj.section_code ?? "—",
                        },
                        {
                          key: "day",
                          header: "Day",
                          render: (subj) =>
                            subj.schedule_days ?? "To be confirmed",
                        },
                        {
                          key: "time",
                          header: "Time",
                          render: (subj) =>
                            subj.starts_at_time && subj.ends_at_time
                              ? formatTimeRange(
                                  subj.starts_at_time,
                                  subj.ends_at_time,
                                )
                              : "To be confirmed",
                        },
                        {
                          key: "room",
                          header: "Room",
                          render: (subj) => subj.room ?? "To be confirmed",
                        },
                        {
                          key: "professor",
                          header: "Professor",
                          render: (subj) => (
                            <span className="flex items-center gap-1.5 font-medium">
                              <User
                                className="size-3 text-muted-foreground"
                                aria-hidden="true"
                              />
                              {subj.professor_name ?? "Announced after enrollment"}
                            </span>
                          ),
                        },
                        {
                          key: "status",
                          header: "Status",
                          render: (subj) => (
                            <Badge variant="outline">{subj.status_label}</Badge>
                          ),
                        },
                      ]}
                    />
                  )}
                </CardContent>
              </Card>

              {/* Helpful Information Notice */}
              <p className="text-xs text-muted-foreground">
                Class schedules and room assignments are determined by the
                department and approved by the Registrar. If you need to make
                enrollment adjustments or file add/drop requests, please proceed
                to{" "}
                <Link
                  href="/portal/enrollment"
                  className="font-medium text-primary underline underline-offset-4 hover:text-primary/80"
                >
                  Enrollment
                </Link>
                .
              </p>
            </div>
          )
        }
      </AsyncBoundary>
    </WorkspacePage>
  )
}
