"use client"

import { useMemo, useState } from "react"
import {
  CalendarDays,
  Clock,
  DoorOpen,
  Folder,
  FolderOpen,
  History,
  Info,
  LayoutList,
  Sparkles,
  Users,
} from "lucide-react"

import { useAuth } from "@/features/auth/use-auth"
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
  CardHeader,
  CardTitle,
} from "@/features/components/ui/card"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/features/components/ui/dialog"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/features/components/ui/select"
import {
  useAcademicTermsQuery,
  useSectionsQuery,
  useSubjectsQuery,
} from "@/features/hooks/use-reference-data"
import {
  getFacultyTeachingSchedule,
  type TeachingScheduleRow,
} from "@/features/services/faculty-service"

export function TeachingScheduleWorkspace() {
  const { session } = useAuth()
  const termsQuery = useAcademicTermsQuery()
  const subjectsQuery = useSubjectsQuery()
  const sectionsQuery = useSectionsQuery()

  const [viewMode, setViewMode] = useState<"table" | "calendar">("table")
  const [selectedTermFilter, setSelectedTermFilter] = useState<string>("current")
  const [selectedRoom, setSelectedRoom] = useState<string>("all")
  const [activeDetailRow, setActiveDetailRow] = useState<TeachingScheduleRow | null>(null)

  const facultyId = Number(session?.userId)
  const assignedSections = useMemo(() => {
    if (!Number.isSafeInteger(facultyId) || facultyId <= 0) return []
    return (sectionsQuery.data ?? []).filter(
      (section) => section.professor_id === facultyId,
    )
  }, [sectionsQuery.data, facultyId])

  const rows = useMemo(() => {
    return getFacultyTeachingSchedule(
      assignedSections,
      subjectsQuery.data ?? [],
      termsQuery.data ?? [],
    )
  }, [assignedSections, subjectsQuery.data, termsQuery.data])

  // Identify current / active semester
  const currentTerm = useMemo(() => {
    const terms = termsQuery.data ?? []
    if (terms.length === 0) return null
    return (
      terms.find((t) => t.status === "semester_ongoing") ??
      terms[terms.length - 1] ??
      null
    )
  }, [termsQuery.data])

  // Available semesters represented in this professor's teaching assignments
  const termGroups = useMemo(() => {
    const termMap = new Map<number, { id: number; label: string; count: number; isCurrent: boolean }>()
    for (const row of rows) {
      const existing = termMap.get(row.termId)
      const isCurrent = currentTerm?.id === row.termId
      if (existing) {
        existing.count += 1
      } else {
        termMap.set(row.termId, {
          id: row.termId,
          label: row.termLabel,
          count: 1,
          isCurrent,
        })
      }
    }
    return Array.from(termMap.values())
  }, [rows, currentTerm])

  // Historical semesters (all except the current semester)
  const historyTerms = useMemo(() => {
    return termGroups.filter((t) => !t.isCurrent)
  }, [termGroups])

  // Current semester group
  const currentTermGroup = useMemo(() => {
    return termGroups.find((t) => t.isCurrent) ?? null
  }, [termGroups])

  // Determine effective term filtering
  const filteredByTerm = useMemo(() => {
    if (selectedTermFilter === "all") return rows
    if (selectedTermFilter === "current") {
      if (!currentTerm) return rows
      return rows.filter((r) => r.termId === currentTerm.id)
    }
    if (selectedTermFilter === "history") {
      if (!currentTerm) return rows
      return rows.filter((r) => r.termId !== currentTerm.id)
    }
    const specificTermId = Number(selectedTermFilter)
    if (Number.isSafeInteger(specificTermId) && specificTermId > 0) {
      return rows.filter((r) => r.termId === specificTermId)
    }
    return rows
  }, [rows, selectedTermFilter, currentTerm])

  // Extract unique rooms assigned to this professor
  const uniqueRooms = useMemo(() => {
    const set = new Set<string>()
    for (const r of rows) {
      if (r.room && r.room !== "Room pending") {
        set.add(r.room)
      }
    }
    return Array.from(set).sort()
  }, [rows])

  // Filtered rows applying both Term and Room filters
  const displayedRows = useMemo(() => {
    if (selectedRoom === "all") return filteredByTerm
    return filteredByTerm.filter((r) => r.room === selectedRoom)
  }, [filteredByTerm, selectedRoom])

  // Convert displayed rows to SectionScheduleItem for SectionScheduleCalendar
  const calendarItems: readonly SectionScheduleItem[] = useMemo(() => {
    return displayedRows.map((r) => ({
      id: r.sectionId,
      subject_code: r.subjectCode,
      subject_title: r.subjectTitle,
      units: r.units ?? 3,
      section_code: r.sectionCode,
      room: r.room,
      professor_name: session?.displayName ?? null,
      schedule_days: r.rawDays,
      starts_at_time: r.startsAtTime,
      ends_at_time: r.endsAtTime,
      modality: r.modality ?? null,
      enrolled_count: r.enrolledCount,
      capacity: r.capacity,
    }))
  }, [displayedRows, session?.displayName])

  const combinedQuery = {
    isPending:
      termsQuery.isPending ||
      subjectsQuery.isPending ||
      sectionsQuery.isPending,
    isError:
      termsQuery.isError || subjectsQuery.isError || sectionsQuery.isError,
    error: termsQuery.error ?? subjectsQuery.error ?? sectionsQuery.error,
    data: rows,
    refetch: () => {
      void termsQuery.refetch()
      void subjectsQuery.refetch()
      void sectionsQuery.refetch()
    },
  }

  return (
    <WorkspacePage
      title="Teaching schedule"
      description="This schedule contains sections assigned to your faculty account."
      lastUpdated={sectionsQuery.dataUpdatedAt}
    >
      <AsyncBoundary
        query={combinedQuery}
        isEmpty={(schedule) => schedule.length === 0}
        emptyMessage="No teaching schedule is available for your account."
        loadingLabel="Loading your teaching schedule…"
      >
        {() => (
          <div className="space-y-6">
            {/* Top Toolbar: Semester Folders, Schedule History & Controls */}
            <Card>
              <CardContent className="pt-6 space-y-4">
                {/* Semester Selection: Current vs History vs All */}
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between border-b pb-4">
                  <div className="flex flex-wrap items-center gap-2" role="group" aria-label="Filter schedule by term">
                    {/* Current Semester button */}
                    <Button
                      type="button"
                      variant={selectedTermFilter === "current" ? "default" : "outline"}
                      size="sm"
                      className="h-8 gap-1.5 text-xs"
                      onClick={() => setSelectedTermFilter("current")}
                    >
                      <Sparkles className="size-3.5 text-amber-500" aria-hidden="true" />
                      Current Semester
                      {currentTermGroup && (
                        <span className="font-mono font-semibold">({currentTermGroup.label})</span>
                      )}
                      <Badge
                        variant={selectedTermFilter === "current" ? "secondary" : "outline"}
                        className="ml-1 px-1.5 py-0 text-[10px]"
                      >
                        {currentTermGroup ? currentTermGroup.count : 0}
                      </Badge>
                    </Button>

                    {/* Schedule History button / selector */}
                    {historyTerms.length > 0 && (
                      <Button
                        type="button"
                        variant={selectedTermFilter === "history" || historyTerms.some((t) => String(t.id) === selectedTermFilter) ? "default" : "outline"}
                        size="sm"
                        className="h-8 gap-1.5 text-xs"
                        onClick={() => setSelectedTermFilter(selectedTermFilter === "history" ? "current" : "history")}
                      >
                        <History className="size-3.5" aria-hidden="true" />
                        Schedule History
                        <Badge
                          variant={selectedTermFilter === "history" ? "secondary" : "outline"}
                          className="ml-1 px-1.5 py-0 text-[10px]"
                        >
                          {historyTerms.reduce((sum, t) => sum + t.count, 0)}
                        </Badge>
                      </Button>
                    )}

                    {/* All Semesters */}
                    <Button
                      type="button"
                      variant={selectedTermFilter === "all" ? "default" : "outline"}
                      size="sm"
                      className="h-8 gap-1.5 text-xs"
                      onClick={() => setSelectedTermFilter("all")}
                    >
                      {selectedTermFilter === "all" ? (
                        <FolderOpen className="size-3.5" aria-hidden="true" />
                      ) : (
                        <Folder className="size-3.5" aria-hidden="true" />
                      )}
                      All Semesters
                      <Badge
                        variant={selectedTermFilter === "all" ? "secondary" : "outline"}
                        className="ml-1 px-1.5 py-0 text-[10px]"
                      >
                        {rows.length}
                      </Badge>
                    </Button>
                  </div>

                  {/* View Mode Toggle: Table View vs Calendar View */}
                  <div className="flex items-center gap-1 rounded-lg border bg-muted/30 p-1" role="group" aria-label="Schedule display mode">
                    <Button
                      type="button"
                      variant={viewMode === "table" ? "default" : "ghost"}
                      size="sm"
                      className="h-7 gap-1 px-2.5 text-xs font-medium"
                      onClick={() => setViewMode("table")}
                      aria-label="Table view"
                    >
                      <LayoutList className="size-3.5" aria-hidden="true" />
                      Table View
                    </Button>
                    <Button
                      type="button"
                      variant={viewMode === "calendar" ? "default" : "ghost"}
                      size="sm"
                      className="h-7 gap-1 px-2.5 text-xs font-medium"
                      onClick={() => setViewMode("calendar")}
                      aria-label="Calendar view"
                    >
                      <CalendarDays className="size-3.5" aria-hidden="true" />
                      Weekly Calendar
                    </Button>
                  </div>
                </div>

                {/* Secondary Bar: History Term Specific Filter & Room View Filter */}
                <div className="flex flex-wrap items-center justify-between gap-3 text-xs text-muted-foreground">
                  <div className="flex flex-wrap items-center gap-2">
                    {/* Specific term selector if in history mode */}
                    {(selectedTermFilter === "history" || historyTerms.some((t) => String(t.id) === selectedTermFilter)) && historyTerms.length > 1 && (
                      <div className="flex items-center gap-1.5">
                        <span>Specific term:</span>
                        <Select
                          value={selectedTermFilter}
                          onValueChange={(val) => setSelectedTermFilter(val)}
                        >
                          <SelectTrigger
                            aria-label="Filter by historical term"
                            className="h-7 w-44 text-xs"
                          >
                            <SelectValue placeholder="Select historical term" />
                          </SelectTrigger>
                          <SelectContent>
                            <SelectItem value="history">All Historical Terms</SelectItem>
                            {historyTerms.map((t) => (
                              <SelectItem key={t.id} value={String(t.id)}>
                                {t.label} ({t.count} classes)
                              </SelectItem>
                            ))}
                          </SelectContent>
                        </Select>
                      </div>
                    )}

                    {/* Assigned Room Filter */}
                    {uniqueRooms.length > 0 && (
                      <div className="flex items-center gap-1.5">
                        <DoorOpen className="size-3.5 text-primary" aria-hidden="true" />
                        <span className="font-medium text-foreground">Room:</span>
                        <Select
                          value={selectedRoom}
                          onValueChange={(val) => setSelectedRoom(val)}
                        >
                          <SelectTrigger
                            aria-label="Filter by assigned room"
                            className="h-7 w-36 text-xs font-medium"
                          >
                            <SelectValue placeholder="All Rooms" />
                          </SelectTrigger>
                          <SelectContent>
                            <SelectItem value="all">All Rooms ({rows.length})</SelectItem>
                            {uniqueRooms.map((room) => {
                              const roomClasses = rows.filter((r) => r.room === room).length
                              return (
                                <SelectItem key={room} value={room}>
                                  Room {room} ({roomClasses})
                                </SelectItem>
                              )
                            })}
                          </SelectContent>
                        </Select>
                      </div>
                    )}
                  </div>

                  {/* Summary count */}
                  <div>
                    Showing <span className="font-semibold text-foreground">{displayedRows.length}</span> assigned {displayedRows.length === 1 ? "class" : "classes"}
                    {selectedRoom !== "all" && <span> in <strong>Room {selectedRoom}</strong></span>}
                  </div>
                </div>
              </CardContent>
            </Card>

            {/* Main Content Area */}
            {viewMode === "calendar" ? (
              <Card>
                <CardHeader className="pb-3">
                  <div className="flex items-center justify-between">
                    <div>
                      <CardTitle level={2} className="flex items-center gap-2 text-base">
                        <CalendarDays className="size-4 text-primary" aria-hidden="true" />
                        Weekly Teaching Timetable (Monday – Saturday)
                      </CardTitle>
                      <p className="text-xs text-muted-foreground mt-0.5">
                        Arranged by day and time slots. Click any class block to view detailed room and section information.
                      </p>
                    </div>
                  </div>
                </CardHeader>
                <CardContent>
                  <SectionScheduleCalendar
                    items={calendarItems}
                    onSelectSubject={(item) => {
                      const matched = rows.find((r) => r.sectionId === item.id)
                      if (matched) setActiveDetailRow(matched)
                    }}
                    emptyMessage="No scheduled synchronous classes found for the selected semester and room."
                  />
                </CardContent>
              </Card>
            ) : (
              <DataTable
                caption="Teaching schedule"
                rowKey={(row) => row.sectionId}
                rows={displayedRows}
                renderCard={(row) => (
                  <Card
                    role="article"
                    aria-label={`${row.subjectCode} ${row.subjectTitle}`}
                  >
                    <CardHeader>
                      <CardTitle level={2}>
                        {row.subjectCode} · {row.subjectTitle}
                      </CardTitle>
                    </CardHeader>
                    <CardContent>
                      <dl className="grid grid-cols-2 gap-2 text-sm">
                        <dt>Term</dt>
                        <dd>{row.termLabel}</dd>
                        <dt>Days</dt>
                        <dd>{row.days}</dd>
                        <dt>Time</dt>
                        <dd>{row.time}</dd>
                        <dt>Room</dt>
                        <dd>
                          <span className="inline-flex items-center gap-1 font-semibold text-primary">
                            <DoorOpen className="size-3.5" aria-hidden="true" />
                            {row.room}
                          </span>
                        </dd>
                      </dl>
                      <Badge className="mt-3">{row.statusLabel}</Badge>
                    </CardContent>
                  </Card>
                )}
                columns={[
                  {
                    key: "subject",
                    header: "Subject",
                    render: (row) => `${row.subjectCode} · ${row.subjectTitle}`,
                  },
                  { key: "term", header: "Term", render: (row) => row.termLabel },
                  { key: "days", header: "Days", render: (row) => row.days },
                  { key: "time", header: "Time", render: (row) => row.time },
                  {
                    key: "room",
                    header: "Room",
                    render: (row) => (
                      <span className="inline-flex items-center gap-1 font-medium text-primary">
                        <DoorOpen className="size-3.5" aria-hidden="true" />
                        {row.room}
                      </span>
                    ),
                  },
                  {
                    key: "status",
                    header: "Status",
                    render: (row) => <Badge>{row.statusLabel}</Badge>,
                  },
                ]}
              />
            )}

            {/* Class Detail Dialog */}
            <Dialog
              open={activeDetailRow !== null}
              onOpenChange={(open) => !open && setActiveDetailRow(null)}
            >
              <DialogContent className="max-w-md sm:max-w-md">
                <DialogHeader>
                  <DialogTitle className="flex items-center gap-2">
                    <DoorOpen className="size-5 text-primary" aria-hidden="true" />
                    Room {activeDetailRow?.room} · Assigned Class
                  </DialogTitle>
                  <DialogDescription>
                    Section {activeDetailRow?.sectionCode} schedule details
                  </DialogDescription>
                </DialogHeader>
                {activeDetailRow && (
                  <div className="space-y-4 pt-2 text-sm">
                    <div className="rounded-lg border p-3 bg-muted/20 space-y-2">
                      <div className="font-semibold text-foreground text-base">
                        {activeDetailRow.subjectCode} — {activeDetailRow.subjectTitle}
                      </div>
                      <div className="flex items-center gap-2 text-xs text-muted-foreground">
                        <span>Section {activeDetailRow.sectionCode}</span>
                        <span>•</span>
                        <span>{activeDetailRow.termLabel}</span>
                      </div>
                    </div>

                    <div className="grid grid-cols-2 gap-3 text-xs">
                      <div className="rounded-md border p-2.5">
                        <span className="text-muted-foreground flex items-center gap-1">
                          <DoorOpen className="size-3.5" aria-hidden="true" /> Assigned Room
                        </span>
                        <p className="font-semibold text-foreground mt-1 text-sm">
                          {activeDetailRow.room}
                        </p>
                      </div>
                      <div className="rounded-md border p-2.5">
                        <span className="text-muted-foreground flex items-center gap-1">
                          <Clock className="size-3.5" aria-hidden="true" /> Days & Time
                        </span>
                        <p className="font-semibold text-foreground mt-1 text-sm">
                          {activeDetailRow.days} · {activeDetailRow.time}
                        </p>
                      </div>
                      <div className="rounded-md border p-2.5">
                        <span className="text-muted-foreground flex items-center gap-1">
                          <Users className="size-3.5" aria-hidden="true" /> Enrolled Students
                        </span>
                        <p className="font-semibold text-foreground mt-1 text-sm">
                          {activeDetailRow.enrolledCount} / {activeDetailRow.capacity} students
                        </p>
                      </div>
                      <div className="rounded-md border p-2.5">
                        <span className="text-muted-foreground flex items-center gap-1">
                          <Info className="size-3.5" aria-hidden="true" /> Section Status
                        </span>
                        <p className="font-semibold text-foreground mt-1 text-sm">
                          <Badge variant="outline">{activeDetailRow.statusLabel}</Badge>
                        </p>
                      </div>
                    </div>
                  </div>
                )}
              </DialogContent>
            </Dialog>
          </div>
        )}
      </AsyncBoundary>
    </WorkspacePage>
  )
}
