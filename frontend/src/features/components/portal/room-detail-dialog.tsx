"use client"

import { useMutation, useQueryClient } from "@tanstack/react-query"
import { CalendarDays, ListIcon, Plus } from "lucide-react"
import { useMemo, useState } from "react"

import { useAuth } from "@/features/auth/use-auth"
import { AsyncBoundary } from "@/features/components/portal/async-boundary"
import { RoomScheduleCalendar } from "@/features/components/portal/room-schedule-calendar"
import { WorkspaceField } from "@/features/components/portal/workspace-field"
import { Alert, AlertDescription } from "@/features/components/ui/alert"
import { Badge } from "@/features/components/ui/badge"
import { Button } from "@/features/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/features/components/ui/dialog"
import { Field, FieldGroup, FieldLabel } from "@/features/components/ui/field"
import { Input } from "@/features/components/ui/input"
import { SearchableCombobox } from "@/features/components/ui/searchable-combobox"
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/features/components/ui/table"
import { ToggleGroup, ToggleGroupItem } from "@/features/components/ui/toggle-group"
import {
  sectionsQueryKey,
  useSectionsQuery,
  useSubjectsQuery,
} from "@/features/hooks/use-reference-data"
import { useFacultyDirectoryQuery } from "@/features/hooks/use-faculty-directory"
import {
  roomOccupancyQueryKey,
  useRoomOccupancyQuery,
} from "@/features/hooks/use-room-occupancy"
import {
  buildRoomWeek,
  findConflictingIds,
  formatTimeRange12,
  isLectureComponentSubject,
  modalityLabel,
  slotClockTime,
  SLOT_COUNT,
} from "@/features/lib/room-calendar"
import { compareBySchedule, parseScheduleDays } from "@/features/lib/schedule-order"
import type { RoomOccupancyEntry } from "@/features/schemas/room-occupancy-schema"
import { isApiClientError } from "@/features/services/api-client"
import {
  replaceSection,
  toSectionReplacement,
} from "@/features/services/scheduling-service"

/**
 * The selected state for a toggle in this dialog — a light destructive tint
 * with destructive-colored text, the same formula `Badge`/`Button`'s own
 * `destructive` variant already uses (`bg-destructive/10 text-destructive`).
 * There is no `--destructive-foreground` token in this design system, so a
 * solid `bg-destructive text-destructive-foreground` fill renders as
 * unreadable dark-on-dark — this stays legible and matches the rest of the
 * page's red/destructive language instead of inventing a new one.
 */
const selectedDestructiveClassName =
  "data-[state=on]:border-destructive data-[state=on]:bg-destructive/10 data-[state=on]:text-destructive dark:data-[state=on]:bg-destructive/20"

const dayOptions = [
  { value: "1", label: "Mon", letter: "M" },
  { value: "2", label: "Tue", letter: "T" },
  { value: "3", label: "Wed", letter: "W" },
  { value: "4", label: "Thu", letter: "Th" },
  { value: "5", label: "Fri", letter: "F" },
  { value: "6", label: "Sat", letter: "Sat" },
] as const

const asTime = (value: string) => (value ? `${value}:00`.slice(0, 8) : "")

function saveErrorMessages(error: unknown): readonly string[] {
  if (isApiClientError(error)) {
    const fieldErrors = Object.values(error.fieldErrors ?? {}).flat()
    if (fieldErrors.length > 0) return fieldErrors
    return [error.message]
  }
  if (error instanceof Error) return [error.message]
  return ["The assignment could not be saved. Try again."]
}

interface RoomDetailDialogProps {
  room: string | null
  termLabel: string
  termId: number
  isCurrentTerm: boolean
  canAssign: boolean
  onOpenChange: (open: boolean) => void
}

export function RoomDetailDialog({
  room,
  termLabel,
  termId,
  isCurrentTerm,
  canAssign,
  onOpenChange,
}: RoomDetailDialogProps) {
  const { session } = useAuth()
  const queryClient = useQueryClient()
  const occupancyQuery = useRoomOccupancyQuery(room, termId)
  const sectionsQuery = useSectionsQuery()
  const subjectsQuery = useSubjectsQuery()
  const facultyQuery = useFacultyDirectoryQuery()
  const [view, setView] = useState<"table" | "calendar">("calendar")
  const [assignOpen, setAssignOpen] = useState(false)
  const [editingSectionId, setEditingSectionId] = useState<number | null>(null)
  const [sectionMode, setSectionMode] = useState<"unscheduled" | "move">("unscheduled")
  const [draft, setDraft] = useState({
    sectionId: "",
    professorId: "",
    day: "",
    startsAt: "",
    endsAt: "",
    modality: "f2f" as "f2f" | "hyflex_a" | "hyflex_b",
    overrideReason: "",
  })

  const subjectMap = useMemo(
    () => new Map((subjectsQuery.data ?? []).map((subject) => [subject.id, subject])),
    [subjectsQuery.data],
  )
  const visibleEntries = useMemo(
    () =>
      [...(occupancyQuery.data ?? [])]
        .filter((entry) => !(entry.is_lecture_component && entry.college === "ccs"))
        .sort(compareBySchedule),
    [occupancyQuery.data],
  )
  const week = useMemo(() => buildRoomWeek(visibleEntries), [visibleEntries])
  const conflictingSectionIds = useMemo(
    () => findConflictingIds(visibleEntries, (entry) => entry.section_id),
    [visibleEntries],
  )
  const unscheduledSectionOptions = useMemo(
    () =>
      (sectionsQuery.data ?? [])
        .filter((section) => section.academic_term_id === termId && section.room === null)
        .filter((section) => {
          const subject = subjectMap.get(section.subject_id)
          return !(subject?.college === "ccs" && isLectureComponentSubject(subject))
        })
        .map((section) => {
          const subject = subjectMap.get(section.subject_id)
          return {
            value: String(section.id),
            label: `${section.section_code} · ${subject?.code ?? `#${section.subject_id}`} — ${subject?.title ?? "Subject"} (Unassigned)`,
          }
        }),
    [sectionsQuery.data, subjectMap, termId],
  )
  /** Already-scheduled sections elsewhere this term, for relocating one into this room instead of creating a fresh assignment. */
  const scheduledElsewhereOptions = useMemo(
    () =>
      (sectionsQuery.data ?? [])
        .filter(
          (section) => section.academic_term_id === termId && section.room !== null && section.room !== room,
        )
        .filter((section) => {
          const subject = subjectMap.get(section.subject_id)
          return !(subject?.college === "ccs" && isLectureComponentSubject(subject))
        })
        .map((section) => {
          const subject = subjectMap.get(section.subject_id)
          const time =
            section.starts_at_time && section.ends_at_time
              ? ` ${formatTimeRange12(section.starts_at_time, section.ends_at_time)}`
              : ""
          return {
            value: String(section.id),
            label: `${section.section_code} · ${subject?.code ?? `#${section.subject_id}`} — currently ${section.room}${section.schedule_days ? ` ${section.schedule_days}` : ""}${time}`,
          }
        }),
    [sectionsQuery.data, subjectMap, termId, room],
  )
  const professorOptions = useMemo(
    () =>
      (facultyQuery.data ?? []).map((member) => ({
        value: String(member.id),
        label: member.name,
      })),
    [facultyQuery.data],
  )
  const selectedSection = (sectionsQuery.data ?? []).find(
    (section) => String(section.id) === draft.sectionId,
  )
  const selectedSubject = selectedSection ? subjectMap.get(selectedSection.subject_id) : undefined

  const openAssignForm = (slot?: { day: number; startSlot: number }) => {
    const endSlot = slot ? Math.min(slot.startSlot + 3, SLOT_COUNT) : 0
    setEditingSectionId(null)
    setSectionMode("unscheduled")
    setDraft({
      sectionId: "",
      professorId: "",
      day: slot ? String(slot.day) : "",
      startsAt: slot ? slotClockTime(slot.startSlot) : "",
      endsAt: slot ? slotClockTime(endSlot) : "",
      modality: "f2f",
      overrideReason: "",
    })
    setAssignOpen(true)
  }

  /** Opens an already-scheduled booking for editing — offered only for the viewer's own college. */
  const openEditForm = (entry: RoomOccupancyEntry) => {
    const section = (sectionsQuery.data ?? []).find((candidate) => candidate.id === entry.section_id)
    if (!section) return

    setEditingSectionId(section.id)
    setDraft({
      sectionId: String(section.id),
      professorId: section.professor_id ? String(section.professor_id) : "",
      day: String(parseScheduleDays(section.schedule_days)[0] ?? ""),
      startsAt: section.starts_at_time?.slice(0, 5) ?? "",
      endsAt: section.ends_at_time?.slice(0, 5) ?? "",
      modality: section.modality ?? "f2f",
      overrideReason: "",
    })
    setAssignOpen(true)
  }

  /** Picking a section already scheduled elsewhere prefills its current day/time/professor/modality so the Chair edits from there rather than starting blank. */
  const selectSectionToMove = (sectionId: string) => {
    const section = (sectionsQuery.data ?? []).find((candidate) => String(candidate.id) === sectionId)
    setDraft((current) => ({
      ...current,
      sectionId,
      professorId: section?.professor_id ? String(section.professor_id) : current.professorId,
      day: section ? String(parseScheduleDays(section.schedule_days)[0] ?? "") : current.day,
      startsAt: section?.starts_at_time?.slice(0, 5) ?? current.startsAt,
      endsAt: section?.ends_at_time?.slice(0, 5) ?? current.endsAt,
      modality: section?.modality ?? current.modality,
    }))
  }

  const assignSection = useMutation({
    mutationFn: async () => {
      if (room === null) throw new Error("Choose a room first.")
      if (!selectedSection) throw new Error("Choose a section to assign.")
      const dayOption = dayOptions.find((option) => option.value === draft.day)
      if (!dayOption) throw new Error("Choose a day.")

      return replaceSection(
        selectedSection.id,
        toSectionReplacement(selectedSection, {
          room,
          professor_id: draft.professorId ? Number(draft.professorId) : null,
          schedule_days: dayOption.letter,
          starts_at_time: asTime(draft.startsAt),
          ends_at_time: asTime(draft.endsAt),
          modality: draft.modality,
          override_reason: draft.overrideReason || null,
        }),
      )
    },
    onSuccess: () => {
      setAssignOpen(false)
      setEditingSectionId(null)
      void queryClient.invalidateQueries({
        queryKey: sectionsQueryKey(session?.userId ?? null),
        exact: true,
      })
      void queryClient.invalidateQueries({
        queryKey: roomOccupancyQueryKey(session?.userId ?? null, room, termId),
        exact: true,
      })
    },
  })
  const closeAssignForm = (open: boolean) => {
    setAssignOpen(open)
    if (!open) setEditingSectionId(null)
  }

  const query = {
    isPending:
      occupancyQuery.isPending ||
      sectionsQuery.isPending ||
      subjectsQuery.isPending ||
      facultyQuery.isPending,
    isError:
      occupancyQuery.isError ||
      sectionsQuery.isError ||
      subjectsQuery.isError ||
      facultyQuery.isError,
    error:
      occupancyQuery.error ?? sectionsQuery.error ?? subjectsQuery.error ?? facultyQuery.error,
    data: true as const,
    refetch: () => {
      void occupancyQuery.refetch()
      void sectionsQuery.refetch()
      void subjectsQuery.refetch()
      void facultyQuery.refetch()
    },
  }
  const assignDisabled = !canAssign || !isCurrentTerm

  return (
    <>
      <Dialog open={room !== null} onOpenChange={onOpenChange}>
        <DialogContent className="max-h-[90dvh] max-w-6xl overflow-y-auto sm:max-w-6xl">
          <DialogHeader>
            <DialogTitle>{room ?? "Room"}</DialogTitle>
            <DialogDescription>
              {termLabel} · every booking in this room this term, including other colleges&apos;.
              {!isCurrentTerm && " Archived term — view only."}
            </DialogDescription>
          </DialogHeader>

          <AsyncBoundary query={query} loadingLabel="Loading this room's schedule…">
            {() => (
              <div className="grid gap-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                  <ToggleGroup
                    type="single"
                    value={view}
                    onValueChange={(value) => {
                      if (value === "table" || value === "calendar") setView(value)
                    }}
                    variant="outline"
                    size="sm"
                    aria-label="Room schedule layout"
                  >
                    <ToggleGroupItem value="calendar" aria-label="Calendar view">
                      <CalendarDays data-icon="inline-start" aria-hidden="true" />
                      Calendar
                    </ToggleGroupItem>
                    <ToggleGroupItem value="table" aria-label="Table view">
                      <ListIcon data-icon="inline-start" aria-hidden="true" />
                      Table
                    </ToggleGroupItem>
                  </ToggleGroup>
                  <div className="flex items-center gap-2">
                    <Badge variant="outline">{visibleEntries.length} booking{visibleEntries.length === 1 ? "" : "s"}</Badge>
                    <Button
                      type="button"
                      size="sm"
                      onClick={() => openAssignForm()}
                      disabled={assignDisabled}
                    >
                      <Plus data-icon="inline-start" aria-hidden="true" />
                      Assign a class
                    </Button>
                  </div>
                </div>

                {(occupancyQuery.data ?? []).some(
                  (entry) => entry.is_lecture_component && entry.college === "ccs",
                ) && (
                  <p className="text-sm text-muted-foreground">
                    IT lecture components are asynchronous and hold no room — they are hidden from this schedule.
                  </p>
                )}

                {view === "table" ? (
                  <div className="overflow-x-auto rounded-lg border">
                    <Table>
                      <TableHeader>
                        <TableRow>
                          <TableHead>Subject</TableHead>
                          <TableHead>Section</TableHead>
                          <TableHead>Professor</TableHead>
                          <TableHead>Day(s)</TableHead>
                          <TableHead>Time</TableHead>
                          <TableHead>Modality</TableHead>
                          <TableHead>College</TableHead>
                          <TableHead>Status</TableHead>
                        </TableRow>
                      </TableHeader>
                      <TableBody>
                        {visibleEntries.map((entry: RoomOccupancyEntry) => {
                          const isConflicting = conflictingSectionIds.has(entry.section_id)
                          const clickable = entry.is_own_college && !assignDisabled
                          return (
                            <TableRow
                              key={entry.section_id}
                              className={clickable ? "cursor-pointer hover:bg-muted/40" : undefined}
                              onClick={clickable ? () => openEditForm(entry) : undefined}
                            >
                              <TableCell className="font-medium">
                                {entry.subject_code} — {entry.subject_title}
                              </TableCell>
                              <TableCell>{entry.section_code}</TableCell>
                              <TableCell>
                                {entry.professor_name ?? (
                                  <Badge variant="destructive">Unassigned</Badge>
                                )}
                              </TableCell>
                              <TableCell>{entry.schedule_days ?? "—"}</TableCell>
                              <TableCell>
                                {entry.starts_at_time && entry.ends_at_time
                                  ? formatTimeRange12(entry.starts_at_time, entry.ends_at_time)
                                  : "—"}
                              </TableCell>
                              <TableCell>{entry.modality ? modalityLabel[entry.modality] : "—"}</TableCell>
                              <TableCell>
                                {entry.is_own_college ? (
                                  <Badge variant="secondary">
                                    {entry.college?.toUpperCase() ?? "This college"}
                                  </Badge>
                                ) : (
                                  <Badge variant="outline">{entry.college?.toUpperCase()}</Badge>
                                )}
                              </TableCell>
                              <TableCell>
                                {isConflicting && (
                                  <Badge variant="destructive">Conflict</Badge>
                                )}
                              </TableCell>
                            </TableRow>
                          )
                        })}
                        {visibleEntries.length === 0 && (
                          <TableRow>
                            <TableCell colSpan={8} className="py-9 text-center text-muted-foreground">
                              No scheduled use in this room this term.
                            </TableCell>
                          </TableRow>
                        )}
                      </TableBody>
                    </Table>
                  </div>
                ) : (
                  <RoomScheduleCalendar
                    week={week}
                    onSelectSlot={(day, slotIndex) => openAssignForm({ day, startSlot: slotIndex })}
                    conflictingSectionIds={conflictingSectionIds}
                    onSelectBooking={assignDisabled ? undefined : openEditForm}
                    disabled={assignDisabled}
                  />
                )}
              </div>
            )}
          </AsyncBoundary>
        </DialogContent>
      </Dialog>

      <Dialog open={assignOpen} onOpenChange={closeAssignForm}>
        <DialogContent className="max-h-[90dvh] max-w-2xl overflow-y-auto sm:max-w-2xl">
          <DialogHeader>
            <DialogTitle>
              {editingSectionId === null
                ? `Assign a class to ${room}`
                : `Edit ${selectedSection?.section_code ?? "booking"} in ${room}`}
            </DialogTitle>
            <DialogDescription>
              {editingSectionId === null
                ? "Pick an unscheduled section for this term, or move one already scheduled elsewhere. The subject comes from the section you choose."
                : "Change the day, time, professor, or modality for this already-scheduled booking."}
            </DialogDescription>
          </DialogHeader>
          <FieldGroup className="grid gap-4">
            {editingSectionId === null ? (
              <>
                <div className="grid gap-2">
                  <p className="text-sm font-medium">Section source</p>
                  <ToggleGroup
                    type="single"
                    value={sectionMode}
                    onValueChange={(value) => {
                      if (value === "unscheduled" || value === "move") {
                        setSectionMode(value)
                        setDraft({ ...draft, sectionId: "" })
                      }
                    }}
                    variant="outline"
                    size="sm"
                    aria-label="Where the section comes from"
                  >
                    <ToggleGroupItem
                      value="unscheduled"
                      aria-label="Unscheduled section"
                      className={selectedDestructiveClassName}
                    >
                      Unscheduled section
                    </ToggleGroupItem>
                    <ToggleGroupItem
                      value="move"
                      aria-label="Move a section already scheduled elsewhere"
                      className={selectedDestructiveClassName}
                    >
                      Move an existing schedule
                    </ToggleGroupItem>
                  </ToggleGroup>
                </div>
                <Field>
                  <FieldLabel htmlFor="room-assign-section">Section</FieldLabel>
                  {sectionMode === "unscheduled" ? (
                    <SearchableCombobox
                      id="room-assign-section"
                      label="Section"
                      options={unscheduledSectionOptions}
                      value={draft.sectionId}
                      onValueChange={(value) => setDraft({ ...draft, sectionId: value })}
                      placeholder="Search section or subject"
                      emptyMessage="No unscheduled section matches."
                    />
                  ) : (
                    <SearchableCombobox
                      id="room-assign-section"
                      label="Section"
                      options={scheduledElsewhereOptions}
                      value={draft.sectionId}
                      onValueChange={selectSectionToMove}
                      placeholder="Search section or subject"
                      emptyMessage="No other scheduled section matches."
                    />
                  )}
                </Field>
              </>
            ) : (
              <WorkspaceField label="Section">
                <Input value={selectedSection?.section_code ?? ""} readOnly />
              </WorkspaceField>
            )}
            <WorkspaceField label="Subject">
              <Input
                value={selectedSubject ? `${selectedSubject.code} — ${selectedSubject.title}` : ""}
                readOnly
                placeholder="Choose a section first"
              />
            </WorkspaceField>
            <Field>
              <FieldLabel htmlFor="room-assign-professor">Professor</FieldLabel>
              <SearchableCombobox
                id="room-assign-professor"
                label="Professor"
                options={professorOptions}
                value={draft.professorId}
                onValueChange={(value) => setDraft({ ...draft, professorId: value })}
                placeholder="Search name"
                emptyMessage="No matching professor."
              />
            </Field>
            <div className="grid gap-2">
              <p className="text-sm font-medium">Day</p>
              <ToggleGroup
                type="single"
                value={draft.day}
                onValueChange={(value) => setDraft({ ...draft, day: value })}
                variant="outline"
                size="sm"
                aria-label="Schedule day — pick one"
              >
                {dayOptions.map((option) => (
                  <ToggleGroupItem
                    key={option.value}
                    value={option.value}
                    aria-label={option.label}
                    className={selectedDestructiveClassName}
                  >
                    {option.label}
                  </ToggleGroupItem>
                ))}
              </ToggleGroup>
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
              <WorkspaceField label="Start time">
                <Input
                  type="time"
                  value={draft.startsAt}
                  onChange={(event) => setDraft({ ...draft, startsAt: event.target.value })}
                />
              </WorkspaceField>
              <WorkspaceField label="End time">
                <Input
                  type="time"
                  value={draft.endsAt}
                  onChange={(event) => setDraft({ ...draft, endsAt: event.target.value })}
                />
              </WorkspaceField>
            </div>
            <WorkspaceField label="Modality">
              <select
                value={draft.modality}
                onChange={(event) =>
                  setDraft({ ...draft, modality: event.target.value as typeof draft.modality })
                }
                className="h-9 rounded-md border bg-background px-2"
              >
                <option value="f2f">F2F</option>
                <option value="hyflex_a">HyFlex A</option>
                <option value="hyflex_b">HyFlex B</option>
              </select>
            </WorkspaceField>
            <WorkspaceField label="Room">
              <Input value={room ?? ""} readOnly />
            </WorkspaceField>
            <WorkspaceField label="Override reason (only needed when changing an AI-generated assignment)">
              <Input
                value={draft.overrideReason}
                onChange={(event) => setDraft({ ...draft, overrideReason: event.target.value })}
                placeholder="Explain the change, if this assignment was generated"
              />
            </WorkspaceField>
          </FieldGroup>
          {assignSection.error !== null && (
            <Alert variant="destructive">
              <AlertDescription>
                {saveErrorMessages(assignSection.error).map((message, index) => (
                  <p key={`${message}-${index}`}>{message}</p>
                ))}
              </AlertDescription>
            </Alert>
          )}
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => closeAssignForm(false)}>
              Cancel
            </Button>
            <Button
              type="button"
              onClick={() => assignSection.mutate()}
              disabled={assignSection.isPending || !draft.sectionId}
            >
              {assignSection.isPending
                ? "Saving…"
                : editingSectionId === null
                  ? "Assign class"
                  : "Save changes"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  )
}
