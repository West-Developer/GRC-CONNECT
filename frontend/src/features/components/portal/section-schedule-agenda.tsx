"use client"

import { useState } from "react"
import { Clock, TriangleAlert, User } from "lucide-react"

import { Badge } from "@/features/components/ui/badge"
import {
  Tabs,
  TabsContent,
  TabsList,
  TabsTrigger,
} from "@/features/components/ui/tabs"
import {
  CALENDAR_DAYS,
  formatTimeRange12,
  modalityLabel,
  type RoomCalendarPlacement,
} from "@/features/lib/room-calendar"
import { cn } from "@/features/lib/utils"
import type { SectionScheduleItem } from "@/features/components/portal/section-schedule-calendar"

const dayLabels: Record<number, string> = {
  1: "Monday",
  2: "Tuesday",
  3: "Wednesday",
  4: "Thursday",
  5: "Friday",
  6: "Saturday",
}

const dayShortLabels: Record<number, string> = {
  1: "Mon",
  2: "Tue",
  3: "Wed",
  4: "Thu",
  5: "Fri",
  6: "Sat",
}

/** Every class that meets on `day`, earliest first (blocks and overlap clusters alike). */
function classesOn(
  week: ReadonlyMap<number, RoomCalendarPlacement<SectionScheduleItem>[]>,
  day: number,
): SectionScheduleItem[] {
  const entries = (week.get(day) ?? []).flatMap((placement) =>
    placement.kind === "cluster" ? placement.entries : [placement.entry],
  )

  return entries.sort((a, b) =>
    (a.starts_at_time ?? "").localeCompare(b.starts_at_time ?? ""),
  )
}

function classCount(count: number): string {
  return `${count} ${count === 1 ? "class" : "classes"}`
}

/**
 * The phone version of the weekly schedule: one day at a time instead of a
 * 58rem-wide grid that would be clipped to about two columns. Built from the
 * same `buildRoomWeek` placements as the grid, so both views always agree on
 * which class meets when, and each class reads in full (no truncated titles).
 */
export function SectionScheduleAgenda({
  week,
  conflictingIds,
  onSelectSubject,
}: {
  week: ReadonlyMap<number, RoomCalendarPlacement<SectionScheduleItem>[]>
  conflictingIds: ReadonlySet<number>
  /** Omit for a read-only agenda (a student viewing their own schedule). */
  onSelectSubject?: (item: SectionScheduleItem) => void
}) {
  const [chosenDay, setChosenDay] = useState<number | null>(null)
  // Until the person chooses a day, open on the first day that has classes.
  const firstBusyDay = CALENDAR_DAYS.find(
    (day) => classesOn(week, day).length > 0,
  )
  const activeDay = chosenDay ?? firstBusyDay ?? CALENDAR_DAYS[0]

  return (
    <Tabs
      value={String(activeDay)}
      onValueChange={(value) => setChosenDay(Number(value))}
      className="rounded-lg border bg-background p-3"
    >
      <TabsList
        aria-label="Day of the week"
        className="grid w-full grid-cols-6 gap-0.5"
      >
        {CALENDAR_DAYS.map((day) => {
          const count = classesOn(week, day).length

          return (
            <TabsTrigger
              key={day}
              value={String(day)}
              aria-label={`${dayShortLabels[day]}, ${classCount(count)}`}
              className="flex-col gap-0 px-0 py-1.5"
            >
              <span>{dayShortLabels[day]}</span>
              <span
                aria-hidden="true"
                className={cn(
                  "text-[0.65rem] leading-none font-normal",
                  count === 0 && "opacity-60",
                )}
              >
                {count}
              </span>
            </TabsTrigger>
          )
        })}
      </TabsList>

      {CALENDAR_DAYS.map((day) => {
        const classes = classesOn(week, day)

        return (
          <TabsContent key={day} value={String(day)} className="mt-1">
            {classes.length === 0 ? (
              <p className="rounded-lg border border-dashed p-4 text-center text-sm text-muted-foreground">
                No classes on {dayLabels[day]}.
              </p>
            ) : (
              <ul className="grid gap-2">
                {classes.map((entry) => (
                  <li key={entry.id}>
                    <AgendaCard
                      entry={entry}
                      day={day}
                      isConflicting={conflictingIds.has(entry.id)}
                      onSelectSubject={onSelectSubject}
                    />
                  </li>
                ))}
              </ul>
            )}
          </TabsContent>
        )
      })}
    </Tabs>
  )
}

function AgendaCard({
  entry,
  day,
  isConflicting,
  onSelectSubject,
}: {
  entry: SectionScheduleItem
  day: number
  isConflicting: boolean
  onSelectSubject?: (item: SectionScheduleItem) => void
}) {
  const timeRange =
    entry.starts_at_time && entry.ends_at_time
      ? formatTimeRange12(entry.starts_at_time, entry.ends_at_time)
      : ""
  const modality = entry.modality
    ? (modalityLabel[entry.modality] ?? entry.modality)
    : null

  const label = [
    entry.subject_code,
    entry.subject_title,
    entry.room ? `Room ${entry.room}` : null,
    entry.professor_name ?? "Unassigned professor",
    dayLabels[day],
    timeRange,
    modality,
    isConflicting ? "schedule conflict" : null,
  ]
    .filter(Boolean)
    .join(", ")

  const className = cn(
    "flex w-full flex-col gap-1.5 rounded-lg border p-3 text-left text-sm shadow-xs",
    isConflicting
      ? "border-destructive/60 bg-destructive/10 text-destructive"
      : "border-emerald-300/90 bg-emerald-50 text-emerald-950 dark:border-emerald-700/80 dark:bg-emerald-950/60 dark:text-emerald-100",
    onSelectSubject &&
      "cursor-pointer focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none active:scale-[0.99]",
  )

  const content = (
    <>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <span className="flex items-center gap-1.5 font-mono text-xs font-semibold">
          <Clock className="size-3.5 shrink-0" aria-hidden="true" />
          {timeRange}
        </span>
        {isConflicting && (
          <Badge variant="destructive" className="gap-1">
            <TriangleAlert className="size-3" aria-hidden="true" />
            Conflict
          </Badge>
        )}
      </div>
      <div>
        <p className="font-bold">{entry.subject_code}</p>
        {entry.subject_title && (
          <p className="text-sm font-medium opacity-90">
            {entry.subject_title}
          </p>
        )}
      </div>
      <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs opacity-90">
        <span className="flex items-center gap-1">
          <User className="size-3 shrink-0" aria-hidden="true" />
          {entry.professor_name ?? "Unassigned"}
        </span>
        {entry.room && <span>Room {entry.room}</span>}
        {modality && (
          <Badge variant="secondary" className="text-[0.65rem]">
            {modality}
          </Badge>
        )}
      </div>
    </>
  )

  if (onSelectSubject) {
    return (
      <button
        type="button"
        aria-label={`${label} — click to edit assignment`}
        className={className}
        onClick={() => onSelectSubject(entry)}
      >
        {content}
      </button>
    )
  }

  return (
    <div role="group" aria-label={label} className={className}>
      {content}
    </div>
  )
}
