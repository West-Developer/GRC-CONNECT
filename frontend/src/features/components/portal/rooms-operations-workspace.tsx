"use client"

import { useMemo, useState } from "react"
import { useMutation, useQueryClient } from "@tanstack/react-query"
import { ChevronDown, DoorOpen, Search } from "lucide-react"

import { useAuth } from "@/features/auth/use-auth"
import { AcademicTermSelector } from "@/features/components/portal/academic-term-selector"
import { AsyncBoundary } from "@/features/components/portal/async-boundary"
import { RoomDetailDialog } from "@/features/components/portal/room-detail-dialog"
import {
  RoomScheduleAssignmentDialog,
  type RoomScheduleAssignmentResult,
} from "@/features/components/portal/room-schedule-assignment-dialog"
import { WorkspacePage } from "@/features/components/portal/workspace-page"
import { Alert, AlertDescription } from "@/features/components/ui/alert"
import { Badge } from "@/features/components/ui/badge"
import { Button } from "@/features/components/ui/button"
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
} from "@/features/components/ui/card"
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from "@/features/components/ui/collapsible"
import { Field, FieldGroup, FieldLabel } from "@/features/components/ui/field"
import { SearchableCombobox } from "@/features/components/ui/searchable-combobox"
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/features/components/ui/table"
import { useAcademicTermSelection } from "@/features/hooks/use-academic-term-selection"
import { useFacultyDirectoryQuery } from "@/features/hooks/use-faculty-directory"
import {
  sectionsQueryKey,
  useSectionsQuery,
  useSubjectsQuery,
} from "@/features/hooks/use-reference-data"
import { useRoomOptionsQuery } from "@/features/hooks/use-room-catalog"
import {
  roomOccupancyQueryKey,
  useRoomOccupancySummaryQuery,
} from "@/features/hooks/use-room-occupancy"
import { isLectureComponentSubject } from "@/features/lib/room-calendar"
import type { Section } from "@/features/schemas/reference-data-schema"
import { isApiClientError } from "@/features/services/api-client"
import { formatAcademicTerm } from "@/features/services/reference-data-service"
import { getLocalRoomOptions } from "@/features/services/room-catalog-service"
import {
  replaceSection,
  toSectionReplacement,
} from "@/features/services/scheduling-service"

const asTime = (value: string) => (value ? `${value}:00`.slice(0, 8) : "")

function assignErrorMessage(error: unknown): string {
  if (isApiClientError(error)) {
    const fieldErrors = Object.values(error.fieldErrors ?? {}).flat()
    if (fieldErrors.length > 0) return fieldErrors[0]
    return error.message
  }
  if (error instanceof Error) return error.message
  return "The room could not be assigned. Try again."
}

export function RoomsOperationsWorkspace() {
  const { session } = useAuth()
  const queryClient = useQueryClient()
  const termSelection = useAcademicTermSelection()
  const { term, termId, sortedTerms, isCurrentTerm, setSelectedTermId } =
    termSelection
  const roomsQuery = useRoomOptionsQuery()
  const summaryQuery = useRoomOccupancySummaryQuery(termId)
  const summaryByRoom = useMemo(
    () =>
      new Map((summaryQuery.data ?? []).map((entry) => [entry.room, entry])),
    [summaryQuery.data],
  )
  const rawRoomOptions =
    roomsQuery.data ?? getLocalRoomOptions(session?.college)
  const roomOptions = useMemo(() => {
    const seen = new Set<string>()
    return rawRoomOptions.filter((r) => {
      if (seen.has(r.name)) return false
      seen.add(r.name)
      return true
    })
  }, [rawRoomOptions])
  const [selectedRoom, setSelectedRoom] = useState<string | null>(null)
  const roomComboOptions = roomOptions.map((room) => ({
    value: room.name,
    label: room.name,
  }))
  const isProgramChair = session?.role === "program_chair"

  const sectionsQuery = useSectionsQuery()
  const subjectsQuery = useSubjectsQuery()
  const facultyQuery = useFacultyDirectoryQuery()
  const [assigningSection, setAssigningSection] = useState<Section | null>(null)
  const [findRoomOpen, setFindRoomOpen] = useState(true)
  const [awaitingRoomOpen, setAwaitingRoomOpen] = useState(true)

  const subjectMap = useMemo(
    () =>
      new Map(
        (subjectsQuery.data ?? []).map((subject) => [subject.id, subject]),
      ),
    [subjectsQuery.data],
  )
  const facultyMap = useMemo(
    () =>
      new Map(
        (facultyQuery.data ?? []).map((member) => [member.id, member.name]),
      ),
    [facultyQuery.data],
  )
  const unassignedSections = useMemo(
    () =>
      (sectionsQuery.data ?? [])
        .filter(
          (section) =>
            section.academic_term_id === termId && section.room === null,
        )
        .filter((section) => {
          const subject = subjectMap.get(section.subject_id)
          return !(
            subject?.college === "ccs" && isLectureComponentSubject(subject)
          )
        }),
    [sectionsQuery.data, subjectMap, termId],
  )

  const assignSectionMutation = useMutation({
    mutationFn: ({
      section,
      result,
    }: {
      section: Section
      result: RoomScheduleAssignmentResult
    }) =>
      replaceSection(
        section.id,
        toSectionReplacement(section, {
          room: result.room,
          schedule_days: result.scheduleDays,
          starts_at_time: asTime(result.startsAtTime),
          ends_at_time: asTime(result.endsAtTime),
          modality: result.modality,
        }),
      ),
    onSuccess: (_data, { result }) => {
      setAssigningSection(null)
      void queryClient.invalidateQueries({
        queryKey: sectionsQueryKey(session?.userId ?? null),
        exact: true,
      })
      void queryClient.invalidateQueries({
        queryKey: roomOccupancyQueryKey(
          session?.userId ?? null,
          result.room,
          termId,
        ),
        exact: true,
      })
    },
  })

  const query = {
    isPending:
      termSelection.termsQuery.isPending ||
      roomsQuery.isPending ||
      sectionsQuery.isPending ||
      subjectsQuery.isPending ||
      facultyQuery.isPending,
    isError:
      termSelection.termsQuery.isError ||
      roomsQuery.isError ||
      sectionsQuery.isError ||
      subjectsQuery.isError ||
      facultyQuery.isError,
    error:
      termSelection.termsQuery.error ??
      roomsQuery.error ??
      sectionsQuery.error ??
      subjectsQuery.error ??
      facultyQuery.error,
    data: true as const,
    refetch: () => {
      void termSelection.termsQuery.refetch()
      void roomsQuery.refetch()
      void sectionsQuery.refetch()
      void subjectsQuery.refetch()
      void facultyQuery.refetch()
    },
  }

  return (
    <WorkspacePage
      title="Rooms"
      description={
        session?.role === "registrar_head"
          ? "System-wide room inventory. Pick a school year, semester, and room to see every college's booking in it."
          : "Pick a school year, semester, and room to see its full weekly schedule and assign an open slot."
      }
      unauthorized={
        session?.role !== "program_chair" && session?.role !== "registrar_head"
      }
      lastUpdated={roomsQuery.dataUpdatedAt}
    >
      <AsyncBoundary query={query} loadingLabel="Loading room availability…">
        {() => (
          <div className="grid gap-5">
            <AcademicTermSelector
              sortedTerms={sortedTerms}
              term={term}
              isCurrentTerm={isCurrentTerm}
              onSelectTerm={setSelectedTermId}
            />

            <Collapsible open={findRoomOpen} onOpenChange={setFindRoomOpen}>
              <Card>
                <CardHeader>
                  <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                      <CardTitle level={2}>Find a room</CardTitle>
                      <p className="mt-1 text-sm text-muted-foreground">
                        A shared campus room may already carry another
                        college&apos;s booking — opening it shows every booking
                        in it this term, not just your own.
                      </p>
                    </div>
                    <div className="flex items-center gap-2">
                      <Badge variant="outline">
                        {roomOptions.length} rooms
                      </Badge>
                      <CollapsibleTrigger asChild>
                        <Button
                          type="button"
                          variant="ghost"
                          size="sm"
                          aria-label="Toggle Find a room"
                        >
                          <ChevronDown
                            className={`size-4 transition-transform duration-200 ${findRoomOpen ? "rotate-180" : ""}`}
                            aria-hidden="true"
                          />
                        </Button>
                      </CollapsibleTrigger>
                    </div>
                  </div>
                </CardHeader>
                <CollapsibleContent>
                  <CardContent className="grid gap-4">
                    <FieldGroup>
                      <Field>
                        <FieldLabel htmlFor="room-picker">Room</FieldLabel>
                        <SearchableCombobox
                          id="room-picker"
                          label="Room"
                          options={roomComboOptions}
                          value={selectedRoom ?? ""}
                          onValueChange={(value) =>
                            setSelectedRoom(value || null)
                          }
                          placeholder="Search room, e.g. LAB 1, 3A"
                          emptyMessage="No room matches."
                        />
                      </Field>
                    </FieldGroup>
                    <div className="grid gap-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
                      {roomOptions.map((room) => (
                        <button
                          key={room.id}
                          type="button"
                          onClick={() => setSelectedRoom(room.name)}
                          className="flex items-center gap-2 rounded-lg border p-3 text-left text-sm font-medium transition-colors hover:border-primary hover:bg-primary/5 focus-visible:border-primary focus-visible:outline-none"
                        >
                          <DoorOpen
                            className="size-4 shrink-0 text-primary"
                            aria-hidden="true"
                          />
                          <span className="grid gap-0.5">
                            {room.name}
                            <RoomUsage
                              entry={summaryByRoom.get(room.name)}
                              loaded={summaryQuery.isSuccess}
                            />
                          </span>
                        </button>
                      ))}
                    </div>
                    {roomOptions.length === 0 && (
                      <div className="grid place-items-center gap-2 border-t py-10 text-center">
                        <Search
                          className="size-5 text-muted-foreground"
                          aria-hidden="true"
                        />
                        <p className="font-medium">
                          No rooms are configured for your college yet.
                        </p>
                      </div>
                    )}
                  </CardContent>
                </CollapsibleContent>
              </Card>
            </Collapsible>

            {isProgramChair && (
              <Collapsible
                open={awaitingRoomOpen}
                onOpenChange={setAwaitingRoomOpen}
              >
                <Card>
                  <CardHeader>
                    <div className="flex flex-wrap items-center justify-between gap-3">
                      <div>
                        <CardTitle level={2}>Awaiting a room</CardTitle>
                        <p className="mt-1 text-sm text-muted-foreground">
                          Sections in this term that don&apos;t have a room yet.
                        </p>
                      </div>
                      <div className="flex items-center gap-2">
                        <Badge
                          variant={
                            unassignedSections.length > 0
                              ? "destructive"
                              : "outline"
                          }
                        >
                          {unassignedSections.length} subject
                          {unassignedSections.length === 1 ? "" : "s"}
                        </Badge>
                        <CollapsibleTrigger asChild>
                          <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            aria-label="Toggle Awaiting a room"
                          >
                            <ChevronDown
                              className={`size-4 transition-transform duration-200 ${awaitingRoomOpen ? "rotate-180" : ""}`}
                              aria-hidden="true"
                            />
                          </Button>
                        </CollapsibleTrigger>
                      </div>
                    </div>
                  </CardHeader>
                  <CollapsibleContent>
                    <CardContent className="grid gap-4">
                      {assignSectionMutation.isError && (
                        <Alert variant="destructive">
                          <AlertDescription>
                            {assignErrorMessage(assignSectionMutation.error)}
                          </AlertDescription>
                        </Alert>
                      )}
                      <div className="overflow-x-auto rounded-lg border">
                        <Table>
                          <TableHeader>
                            <TableRow>
                              <TableHead>Subject</TableHead>
                              <TableHead>Section</TableHead>
                              <TableHead>Professor</TableHead>
                              <TableHead />
                            </TableRow>
                          </TableHeader>
                          <TableBody>
                            {unassignedSections.map((section) => {
                              const subject = subjectMap.get(section.subject_id)
                              return (
                                <TableRow key={section.id}>
                                  <TableCell className="font-medium">
                                    {subject
                                      ? `${subject.code} — ${subject.title}`
                                      : `#${section.subject_id}`}
                                  </TableCell>
                                  <TableCell>{section.section_code}</TableCell>
                                  <TableCell>
                                    {section.professor_id ? (
                                      (facultyMap.get(section.professor_id) ??
                                      "—")
                                    ) : (
                                      <Badge variant="outline">
                                        Unassigned
                                      </Badge>
                                    )}
                                  </TableCell>
                                  <TableCell className="text-right">
                                    <Button
                                      type="button"
                                      size="sm"
                                      variant="outline"
                                      disabled={!isCurrentTerm}
                                      onClick={() =>
                                        setAssigningSection(section)
                                      }
                                    >
                                      Assign a room
                                    </Button>
                                  </TableCell>
                                </TableRow>
                              )
                            })}
                            {unassignedSections.length === 0 && (
                              <TableRow>
                                <TableCell
                                  colSpan={4}
                                  className="py-9 text-center text-muted-foreground"
                                >
                                  Every section this term already has a room.
                                </TableCell>
                              </TableRow>
                            )}
                          </TableBody>
                        </Table>
                      </div>
                    </CardContent>
                  </CollapsibleContent>
                </Card>
              </Collapsible>
            )}
          </div>
        )}
      </AsyncBoundary>

      <RoomDetailDialog
        room={selectedRoom}
        termLabel={term ? formatAcademicTerm(term) : "No term selected"}
        termId={termId}
        isCurrentTerm={isCurrentTerm}
        canAssign={session?.role === "program_chair"}
        onOpenChange={(open) => {
          if (!open) setSelectedRoom(null)
        }}
      />

      <RoomScheduleAssignmentDialog
        open={assigningSection !== null}
        onOpenChange={(open) => {
          if (!open) setAssigningSection(null)
        }}
        termId={termId}
        onConfirm={(result) => {
          if (assigningSection)
            assignSectionMutation.mutate({ section: assigningSection, result })
        }}
      />
    </WorkspacePage>
  )
}

const WEEKDAYS = [
  { day: 1, label: "Mon" },
  { day: 2, label: "Tue" },
  { day: 3, label: "Wed" },
  { day: 4, label: "Thu" },
  { day: 5, label: "Fri" },
  { day: 6, label: "Sat" },
] as const

/**
 * What a room tile says about its use this term: "Empty" (muted) or the class
 * count with a dot for each weekday, Monday to Saturday, that has a class.
 * Nothing is shown until the summary has loaded, so a slow response never
 * makes an occupied room look empty.
 */
function RoomUsage({
  entry,
  loaded,
}: {
  entry: { classes_count: number; days: readonly number[] } | undefined
  loaded: boolean
}) {
  if (!loaded) return null

  if (!entry) {
    return (
      <span className="text-xs font-normal text-muted-foreground">Empty</span>
    )
  }

  const usedDays = WEEKDAYS.filter((weekday) =>
    entry.days.includes(weekday.day),
  )

  return (
    <span className="flex items-center gap-2 text-xs font-normal text-muted-foreground">
      <span>
        {entry.classes_count} {entry.classes_count === 1 ? "class" : "classes"}
      </span>
      <span
        className="flex gap-0.5"
        role="img"
        aria-label={`Used on ${usedDays.map((weekday) => weekday.label).join(", ") || "no set day"}`}
      >
        {WEEKDAYS.map((weekday) => (
          <span
            key={weekday.day}
            className={
              entry.days.includes(weekday.day)
                ? "size-1.5 rounded-full bg-primary"
                : "size-1.5 rounded-full bg-muted-foreground/25"
            }
          />
        ))}
      </span>
    </span>
  )
}
