"use client"

import { useEffect, useMemo, useState } from "react"
import { ArrowLeft, DoorOpen } from "lucide-react"

import { useAuth } from "@/features/auth/use-auth"
import { AsyncBoundary } from "@/features/components/portal/async-boundary"
import { RoomScheduleCalendar } from "@/features/components/portal/room-schedule-calendar"
import type { SectionScheduleItem } from "@/features/components/portal/section-schedule-calendar"
import { WorkspaceField } from "@/features/components/portal/workspace-field"
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
import { ToggleGroup, ToggleGroupItem } from "@/features/components/ui/toggle-group"
import { useRoomOptionsQuery } from "@/features/hooks/use-room-catalog"
import { useRoomOccupancyQuery } from "@/features/hooks/use-room-occupancy"
import {
  buildRoomWeek,
  findConflictingIds,
  slotClockTime,
  SLOT_COUNT,
} from "@/features/lib/room-calendar"
import { cn } from "@/features/lib/utils"
import type { RoomOccupancyEntry } from "@/features/schemas/room-occupancy-schema"
import { getLocalRoomOptions } from "@/features/services/room-catalog-service"

const dayOptions = [
  { value: "1", label: "Mon", letter: "M" },
  { value: "2", label: "Tue", letter: "T" },
  { value: "3", label: "Wed", letter: "W" },
  { value: "4", label: "Thu", letter: "Th" },
  { value: "5", label: "Fri", letter: "F" },
  { value: "6", label: "Sat", letter: "Sat" },
] as const

/**
 * A typical 3-unit subject meets 3 hours a week, split into two 1.5-hour
 * sessions (e.g. TUE/THU) rather than three separate days — so this picker
 * caps a single meeting pattern at two days, matching how the real
 * curriculum reference data is actually shaped.
 */
const MAX_MEETING_DAYS = 2

/**
 * The selected state for a toggle here — a light destructive tint with
 * destructive-colored text, the same formula `Badge`/`Button`'s own
 * `destructive` variant already uses (`bg-destructive/10 text-destructive`).
 * There is no `--destructive-foreground` token in this design system, so a
 * solid fill would render unreadable dark-on-dark.
 */
const selectedDestructiveClassName =
  "data-[state=on]:border-destructive data-[state=on]:bg-destructive/10 data-[state=on]:text-destructive dark:data-[state=on]:bg-destructive/20"

export interface RoomScheduleAssignmentResult {
  room: string
  scheduleDays: string
  startsAtTime: string
  endsAtTime: string
  modality: "f2f" | "hyflex_a" | "hyflex_b"
}

interface RoomScheduleAssignmentDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  termId: number
  initialRoom?: string | null
  /** Excluded from the room's occupancy so editing a section's own current booking never reads as a self-conflict. */
  excludeSectionId?: number
  /** The block section code currently being scheduled (e.g. "IT401") */
  sectionCode?: string | null
  /** Sibling subjects in the same section that already have schedules, displayed as a light-green overlay. */
  sectionScheduleItems?: readonly SectionScheduleItem[]
  onConfirm: (result: RoomScheduleAssignmentResult) => void
}

type Step = "room" | "calendar" | "form"

/**
 * Room → calendar → day/time/modality, in that order — the same
 * conflict-aware picking the Rooms workspace already offers, reused here so
 * assigning a room from the Schedule page is never a blind guess against
 * another subject's booking.
 */
export function RoomScheduleAssignmentDialog({
  open,
  onOpenChange,
  termId,
  initialRoom,
  excludeSectionId,
  sectionCode,
  sectionScheduleItems,
  onConfirm,
}: RoomScheduleAssignmentDialogProps) {
  const { session } = useAuth()
  const [step, setStep] = useState<Step>("room")
  const [room, setRoom] = useState<string | null>(initialRoom ?? null)
  const [calendarViewMode, setCalendarViewMode] = useState<
    "overlay" | "room_only" | "section_only"
  >("overlay")
  const [formDraft, setFormDraft] = useState({
    days: [] as string[],
    startsAt: "",
    endsAt: "",
    modality: "f2f" as "f2f" | "hyflex_a" | "hyflex_b",
  })

  // Reset the picker each time the dialog opens (or its initial room
  // changes while open) — adjusted during render, not in an effect.
  const resetKey = open ? `open|${initialRoom ?? ""}` : "closed"
  const [appliedResetKey, setAppliedResetKey] = useState<string | null>(null)
  if (appliedResetKey !== resetKey) {
    setAppliedResetKey(resetKey)
    if (open) {
      setRoom(initialRoom ?? null)
      setStep("room")
      setCalendarViewMode("overlay")
      setFormDraft({ days: [], startsAt: "", endsAt: "", modality: "f2f" })
    }
  }

  const roomsQuery = useRoomOptionsQuery()
  const roomOptions = roomsQuery.data ?? getLocalRoomOptions(session?.college)
  const roomComboOptions = useMemo(
    () => roomOptions.map((option) => ({ value: option.name, label: option.name })),
    [roomOptions],
  )

  const occupancyQuery = useRoomOccupancyQuery(room, termId)

  useEffect(() => {
    if (open && room) {
      void occupancyQuery.refetch()
    }
  }, [open, room, occupancyQuery])

  const sectionOverlayEntries = useMemo<RoomOccupancyEntry[]>(() => {
    if (!sectionScheduleItems || sectionScheduleItems.length === 0) return []
    return sectionScheduleItems.map((item) => ({
      type: "room_occupancy" as const,
      section_id: item.id,
      section_code: item.section_code ?? sectionCode ?? "Section",
      subject_code: item.subject_code,
      subject_title: item.subject_title ?? "Subject",
      professor_name: item.professor_name ?? null,
      schedule_days: item.schedule_days,
      starts_at_time: item.starts_at_time,
      ends_at_time: item.ends_at_time,
      modality: item.modality,
      college: session?.college ?? null,
      is_own_college: true,
      is_lecture_component: Boolean(item.is_lecture_component),
      is_section_overlay: true,
      room: item.room ?? null,
    }))
  }, [sectionScheduleItems, sectionCode, session?.college])

  const visibleEntries = useMemo(() => {
    const roomEntries = (occupancyQuery.data ?? []).filter(
      (entry) => entry.section_id !== excludeSectionId,
    )
    if (calendarViewMode === "room_only") {
      return roomEntries
    }
    if (calendarViewMode === "section_only") {
      return sectionOverlayEntries
    }
    const existingIds = new Set(roomEntries.map((e) => e.section_id))
    const uniqueOverlay = sectionOverlayEntries.filter(
      (e) => !existingIds.has(e.section_id),
    )
    return [...roomEntries, ...uniqueOverlay]
  }, [
    occupancyQuery.data,
    excludeSectionId,
    calendarViewMode,
    sectionOverlayEntries,
  ])

  const week = useMemo(() => buildRoomWeek(visibleEntries), [visibleEntries])
  const conflictingSectionIds = useMemo(
    () => findConflictingIds(visibleEntries, (entry) => entry.section_id),
    [visibleEntries],
  )

  const reset = () => {
    setFormDraft({ days: [], startsAt: "", endsAt: "", modality: "f2f" })
  }
  const close = () => {
    reset()
    onOpenChange(false)
  }

  const pickRoom = (name: string) => {
    setRoom(name)
    setStep("calendar")
  }

  const selectSlot = (day: number, startSlot: number) => {
    const endSlot = Math.min(startSlot + 3, SLOT_COUNT)
    setFormDraft({
      days: [String(day)],
      startsAt: slotClockTime(startSlot),
      endsAt: slotClockTime(endSlot),
      modality: "f2f",
    })
    setStep("form")
  }

  const canSave =
    room !== null && formDraft.days.length > 0 && formDraft.startsAt !== "" && formDraft.endsAt !== ""

  const save = () => {
    if (!canSave || room === null) return
    const scheduleDays = dayOptions
      .filter((option) => formDraft.days.includes(option.value))
      .map((option) => option.letter)
      .join("")
    onConfirm({
      room,
      scheduleDays,
      startsAtTime: formDraft.startsAt,
      endsAtTime: formDraft.endsAt,
      modality: formDraft.modality,
    })
    close()
  }

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        if (!next) close()
      }}
    >
      <DialogContent className="max-h-[92dvh] w-[96vw] max-w-7xl overflow-y-auto sm:max-w-7xl">
        <DialogHeader>
          <DialogTitle>
            {step === "room" && "Pick a room"}
            {step === "calendar" && `${room} — pick an open slot`}
            {step === "form" && `Confirm the schedule in ${room}`}
          </DialogTitle>
          <DialogDescription>
            {step === "room" && "Choose the room this section will meet in."}
            {step === "calendar" &&
              "Every booking in this room is shown, including other colleges' — click an open cell to use it."}
            {step === "form" &&
              `Up to ${MAX_MEETING_DAYS} meeting days — most 3-unit subjects split into two 1.5-hour sessions rather than three single-hour ones.`}
          </DialogDescription>
        </DialogHeader>

        {step === "room" && (
          <AsyncBoundary
            query={{
              isPending: roomsQuery.isPending,
              isError: roomsQuery.isError,
              error: roomsQuery.error,
              data: true as const,
              refetch: () => void roomsQuery.refetch(),
            }}
            loadingLabel="Loading rooms…"
          >
            {() => (
              <div className="grid gap-4">
                <Field>
                  <FieldLabel htmlFor="room-assignment-picker-search">Room</FieldLabel>
                  <SearchableCombobox
                    id="room-assignment-picker-search"
                    label="Room"
                    options={roomComboOptions}
                    value=""
                    onValueChange={(value) => {
                      if (value) pickRoom(value)
                    }}
                    placeholder="Search room, e.g. LAB 1, 3A"
                    emptyMessage="No room matches."
                  />
                </Field>
                <div className="grid gap-2 sm:grid-cols-3 lg:grid-cols-4">
                  {roomOptions.map((option) => (
                    <button
                      key={option.id}
                      type="button"
                      onClick={() => pickRoom(option.name)}
                      className="flex items-center gap-2 rounded-lg border p-3 text-left text-sm font-medium transition-colors hover:border-primary hover:bg-primary/5 focus-visible:border-primary focus-visible:outline-none"
                    >
                      <DoorOpen className="size-4 shrink-0 text-primary" aria-hidden="true" />
                      {option.name}
                    </button>
                  ))}
                </div>
              </div>
            )}
          </AsyncBoundary>
        )}

        {step === "calendar" && room !== null && (
          <AsyncBoundary
            query={{
              isPending: occupancyQuery.isPending,
              isError: occupancyQuery.isError,
              error: occupancyQuery.error,
              data: true as const,
              refetch: () => void occupancyQuery.refetch(),
            }}
            loadingLabel="Loading this room's schedule…"
          >
            {() => (
              <div className="grid gap-3">
                <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border bg-muted/20 p-2.5">
                  <div className="flex flex-wrap items-center gap-2">
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      className="w-fit"
                      onClick={() => setStep("room")}
                    >
                      <ArrowLeft data-icon="inline-start" aria-hidden="true" />
                      Change room
                    </Button>
                    <span className="text-xs font-semibold text-foreground">
                      Selected Room: <span className="text-primary">{room}</span>
                    </span>
                  </div>

                  {sectionCode && sectionOverlayEntries.length > 0 && (
                    <div className="flex flex-wrap items-center gap-1.5">
                      <span className="text-xs font-medium text-muted-foreground">Schedule view:</span>
                      <div className="inline-flex rounded-md border bg-background p-0.5 shadow-xs">
                        <button
                          type="button"
                          onClick={() => setCalendarViewMode("overlay")}
                          className={cn(
                            "inline-flex items-center gap-1.5 rounded px-2.5 py-1 text-xs font-medium transition-colors",
                            calendarViewMode === "overlay"
                              ? "bg-primary text-primary-foreground shadow-xs"
                              : "text-muted-foreground hover:bg-muted hover:text-foreground",
                          )}
                        >
                          <span className="size-2 rounded-full bg-emerald-400" />
                          Overlay {sectionCode} (Green)
                        </button>
                        <button
                          type="button"
                          onClick={() => setCalendarViewMode("room_only")}
                          className={cn(
                            "rounded px-2.5 py-1 text-xs font-medium transition-colors",
                            calendarViewMode === "room_only"
                              ? "bg-primary text-primary-foreground shadow-xs"
                              : "text-muted-foreground hover:bg-muted hover:text-foreground",
                          )}
                        >
                          Room {room} only
                        </button>
                        <button
                          type="button"
                          onClick={() => setCalendarViewMode("section_only")}
                          className={cn(
                            "inline-flex items-center gap-1 rounded px-2.5 py-1 text-xs font-medium transition-colors",
                            calendarViewMode === "section_only"
                              ? "bg-emerald-600 text-white shadow-xs"
                              : "text-muted-foreground hover:bg-muted hover:text-foreground",
                          )}
                        >
                          <span className="size-2 rounded-full bg-emerald-300" />
                          {sectionCode} schedule only
                        </button>
                      </div>
                    </div>
                  )}
                </div>

                <div className="flex flex-wrap items-center gap-3 px-1 text-xs">
                  <div className="flex items-center gap-1.5">
                    <span className="size-3 rounded border border-primary/40 bg-primary/10" />
                    <span className="text-muted-foreground">Room {room} bookings</span>
                  </div>
                  {sectionCode && sectionOverlayEntries.length > 0 && (
                    <div className="flex items-center gap-1.5">
                      <span className="size-3 rounded border border-emerald-400/80 bg-emerald-100 ring-1 ring-emerald-400/50 dark:bg-emerald-950/75" />
                      <span className="font-medium text-emerald-800 dark:text-emerald-300">
                        {sectionCode} plotted schedule (Light Green)
                      </span>
                    </div>
                  )}
                  <div className="flex items-center gap-1.5">
                    <span className="size-3 rounded border border-dashed border-border bg-background" />
                    <span className="text-muted-foreground">Available (Click to select slot)</span>
                  </div>
                </div>

                <RoomScheduleCalendar
                  week={week}
                  onSelectSlot={selectSlot}
                  conflictingSectionIds={conflictingSectionIds}
                />
              </div>
            )}
          </AsyncBoundary>
        )}

        {step === "form" && room !== null && (
          <FieldGroup className="grid gap-4">
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="w-fit"
              onClick={() => setStep("calendar")}
            >
              <ArrowLeft data-icon="inline-start" aria-hidden="true" />
              Back to calendar
            </Button>
            <div className="grid gap-2">
              <p className="text-sm font-medium">Day(s) — up to {MAX_MEETING_DAYS}</p>
              <ToggleGroup
                type="multiple"
                value={formDraft.days}
                onValueChange={(value) =>
                  setFormDraft((current) => ({
                    ...current,
                    days: value.slice(0, MAX_MEETING_DAYS),
                  }))
                }
                variant="outline"
                size="sm"
                aria-label={`Schedule days, up to ${MAX_MEETING_DAYS}`}
              >
                {dayOptions.map((option) => (
                  <ToggleGroupItem
                    key={option.value}
                    value={option.value}
                    aria-label={option.label}
                    className={selectedDestructiveClassName}
                    disabled={
                      formDraft.days.length >= MAX_MEETING_DAYS &&
                      !formDraft.days.includes(option.value)
                    }
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
                  value={formDraft.startsAt}
                  onChange={(event) =>
                    setFormDraft({ ...formDraft, startsAt: event.target.value })
                  }
                />
              </WorkspaceField>
              <WorkspaceField label="End time">
                <Input
                  type="time"
                  value={formDraft.endsAt}
                  onChange={(event) =>
                    setFormDraft({ ...formDraft, endsAt: event.target.value })
                  }
                />
              </WorkspaceField>
            </div>
            <WorkspaceField label="Modality">
              <select
                value={formDraft.modality}
                onChange={(event) =>
                  setFormDraft({
                    ...formDraft,
                    modality: event.target.value as typeof formDraft.modality,
                  })
                }
                className="h-9 rounded-md border bg-background px-2"
              >
                <option value="f2f">F2F</option>
                <option value="hyflex_a">HyFlex A</option>
                <option value="hyflex_b">HyFlex B</option>
              </select>
            </WorkspaceField>
            <WorkspaceField label="Room">
              <Input value={room} readOnly />
            </WorkspaceField>
          </FieldGroup>
        )}

        <DialogFooter>
          <Button type="button" variant="outline" onClick={close}>
            Cancel
          </Button>
          {step === "form" && (
            <Button type="button" onClick={save} disabled={!canSave}>
              Save schedule
            </Button>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
