"use client"

import { useMemo, useState } from "react"
import {
  CalendarDays,
  Clock,
  TriangleAlert,
  User,
  Users,
} from "lucide-react"

import {
  CALENDAR_DAYS,
  SLOT_COUNT,
  buildRoomWeek,
  findConflictingIds,
  formatTimeRange12,
  modalityLabel,
  slotLabel,
  type RoomCalendarPlacement,
} from "@/features/lib/room-calendar"
import { cn } from "@/features/lib/utils"
import { useElementWidth } from "@/features/hooks/use-element-width"
import { useIsPhone } from "@/features/hooks/use-media-query"
import { SectionScheduleAgenda } from "@/features/components/portal/section-schedule-agenda"
import { Badge } from "@/features/components/ui/badge"
import { Button } from "@/features/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/features/components/ui/dialog"
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/features/components/ui/table"

export interface SectionScheduleItem {
  id: number
  subject_code: string
  subject_title?: string | null
  units?: number | null
  section_code?: string | null
  room?: string | null
  professor_name?: string | null
  schedule_days: string | null
  starts_at_time: string | null
  ends_at_time: string | null
  modality: "hyflex_a" | "hyflex_b" | "f2f" | null
  is_lecture_component?: boolean
  capacity?: number | null
  enrolled_count?: number | null
}

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

/** The week grid is `min-w-[58rem]`; a box narrower than that gets the day-by-day agenda instead of a sideways scroll. */
const WEEK_GRID_MIN_WIDTH_PX = 58 * 16

interface SectionScheduleCalendarProps {
  items: readonly SectionScheduleItem[]
  onSelectSubject?: (item: SectionScheduleItem) => void
  disabled?: boolean
  className?: string
  emptyMessage?: string
}

function conflictBadge(isConflicting: boolean) {
  if (!isConflicting) return null
  return (
    <Badge variant="destructive" className="gap-1">
      <TriangleAlert className="size-3" aria-hidden="true" />
      Conflict
    </Badge>
  )
}

/**
 * Weekly schedule calendar grid (Monday–Saturday, 7:30 AM to 9:00 PM) for
 * visualizing class section subjects and schedules.
 */
export function SectionScheduleCalendar({
  items,
  onSelectSubject,
  disabled = false,
  className,
  emptyMessage = "No scheduled classes found for this section.",
}: SectionScheduleCalendarProps) {
  const isPhone = useIsPhone()
  const [boxRef, boxWidth] = useElementWidth<HTMLDivElement>()
  const showAgenda =
    isPhone || (boxWidth > 0 && boxWidth < WEEK_GRID_MIN_WIDTH_PX)
  const [openCluster, setOpenCluster] = useState<{
    day: number
    entries: SectionScheduleItem[]
  } | null>(null)

  // Split into calendar-plannable items vs asynchronous / unscheduled items
  const { scheduledItems, unscheduledItems } = useMemo(() => {
    const scheduled: SectionScheduleItem[] = []
    const unscheduled: SectionScheduleItem[] = []

    for (const item of items) {
      if (
        item.schedule_days &&
        item.starts_at_time &&
        item.ends_at_time
      ) {
        scheduled.push({ ...item, modality: item.modality ?? null })
      } else {
        unscheduled.push({ ...item, modality: item.modality ?? null })
      }
    }

    return { scheduledItems: scheduled, unscheduledItems: unscheduled }
  }, [items])

  const week = useMemo(() => buildRoomWeek(scheduledItems), [scheduledItems])
  const conflictingIds = useMemo(
    () => findConflictingIds<SectionScheduleItem>(scheduledItems, (item) => item.id),
    [scheduledItems],
  )

  const hasAnyPlacements = Array.from(week.values()).some(
    (placements) => placements.length > 0,
  )

  return (
    <div ref={boxRef} className={cn("grid gap-4", className)}>
      {conflictingIds.size > 0 && (
        <div className="flex items-center gap-2 rounded-lg border border-destructive/40 bg-destructive/10 p-3 text-sm text-destructive">
          <TriangleAlert className="size-4 shrink-0" aria-hidden="true" />
          <span>
            <strong>Schedule Conflict Detected:</strong> Two or more subjects have
            overlapping days and times in this section. Review the highlighted
            classes below.
          </span>
        </div>
      )}

      {/* Phones, and any box narrower than the 58rem grid, get a day-at-a-time
          agenda instead of a grid that would have to scroll sideways. */}
      {showAgenda && hasAnyPlacements && (
        <SectionScheduleAgenda
          week={week}
          conflictingIds={conflictingIds}
          onSelectSubject={disabled ? undefined : onSelectSubject}
        />
      )}

      {!showAgenda && (
      <div className="overflow-x-auto rounded-lg border bg-background">
        <div
          className="grid min-w-[58rem]"
          style={{
            gridTemplateColumns: "4.5rem repeat(6, minmax(9.5rem, 1fr))",
            gridTemplateRows: `auto repeat(${SLOT_COUNT}, 2.5rem)`,
          }}
        >
          {/* Top-left corner sticky anchor */}
          <div className="sticky top-0 left-0 z-20 border-r border-b bg-muted/60" />

          {/* Mon–Sat Column Headers */}
          {CALENDAR_DAYS.map((day) => (
            <div
              key={day}
              className="sticky top-0 z-10 border-b bg-muted/60 px-2 py-2 text-center text-sm font-semibold"
            >
              {dayShortLabels[day]}
            </div>
          ))}

          {/* Time Gutter (Left column) */}
          {Array.from({ length: SLOT_COUNT }, (_, index) => (
            <div
              key={`time-${index}`}
              className="sticky left-0 z-10 border-r bg-background px-2 py-1 text-right text-xs text-muted-foreground"
              style={{ gridColumn: 1, gridRow: index + 2 }}
            >
              {index % 2 === 0 ? slotLabel(index) : ""}
            </div>
          ))}

          {/* Background grid lines for all days/slots */}
          {CALENDAR_DAYS.map((day, dayIndex) =>
            Array.from({ length: SLOT_COUNT }, (_, slotIndex) => (
              <div
                key={`bg-cell-${day}-${slotIndex}`}
                className="border-r border-b border-border/40"
                style={{ gridColumn: dayIndex + 2, gridRow: slotIndex + 2 }}
                aria-hidden="true"
              />
            )),
          )}

          {/* Schedule Placement Cards */}
          {CALENDAR_DAYS.map((day, dayIndex) =>
            (week.get(day) ?? []).map((placement, index) =>
              placement.kind === "cluster" ? (
                <ScheduleClusterCell
                  key={`cluster-${day}-${index}`}
                  cluster={placement}
                  dayIndex={dayIndex}
                  hasConflict={placement.entries.some((entry) =>
                    conflictingIds.has(entry.id),
                  )}
                  onOpen={() =>
                    setOpenCluster({ day, entries: placement.entries })
                  }
                />
              ) : (
                <ScheduleBlockCell
                  key={`block-${day}-${index}-${placement.entry.id}`}
                  block={placement}
                  dayIndex={dayIndex}
                  isConflicting={conflictingIds.has(placement.entry.id)}
                  onSelectSubject={disabled ? undefined : onSelectSubject}
                />
              ),
            ),
          )}
        </div>
      </div>
      )}

      {!hasAnyPlacements && unscheduledItems.length === 0 && (
        <div className="flex flex-col items-center justify-center rounded-lg border border-dashed p-8 text-center text-muted-foreground">
          <CalendarDays className="size-8 mb-2 opacity-50" aria-hidden="true" />
          <p className="text-sm font-medium">{emptyMessage}</p>
        </div>
      )}

      {/* Asynchronous & Unscheduled Subjects Tray */}
      {unscheduledItems.length > 0 && (
        <div className="rounded-lg border bg-muted/20 p-4">
          <div className="flex items-center gap-2 mb-3">
            <Clock className="size-4 text-muted-foreground" aria-hidden="true" />
            <h4 className="text-sm font-semibold">
              Asynchronous & Unscheduled Subjects ({unscheduledItems.length})
            </h4>
          </div>
          <p className="text-xs text-muted-foreground mb-3">
            The following subjects are part of this section but do not have fixed
            synchronous room slots (such as online/asynchronous lecture components or
            schedules pending assignment).
          </p>
          <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
            {unscheduledItems.map((item) => {
              const clickable = !disabled && onSelectSubject !== undefined
              return (
                <div
                  key={item.id}
                  className={cn(
                    "flex flex-col justify-between gap-2 rounded-md border bg-card p-3 text-sm shadow-xs transition-colors",
                    clickable &&
                      "cursor-pointer hover:border-primary/50 hover:bg-primary/5",
                  )}
                  onClick={clickable ? () => onSelectSubject?.(item) : undefined}
                >
                  <div>
                    <div className="flex items-center justify-between gap-1">
                      <span className="font-semibold text-foreground">
                        {item.subject_code}
                      </span>
                      {item.units != null && (
                        <Badge variant="outline" className="text-[0.65rem]">
                          {item.units} {item.units === 1 ? "unit" : "units"}
                        </Badge>
                      )}
                    </div>
                    {item.subject_title && (
                      <p className="text-xs text-muted-foreground line-clamp-1">
                        {item.subject_title}
                      </p>
                    )}
                  </div>
                  <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                    <span className="flex items-center gap-1">
                      <User className="size-3" aria-hidden="true" />
                      {item.professor_name ?? "Unassigned"}
                    </span>
                    {item.modality && (
                      <Badge variant="secondary" className="text-[0.65rem] py-0">
                        {modalityLabel[item.modality] ?? item.modality}
                      </Badge>
                    )}
                  </div>
                  {clickable && (
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      className="mt-1 w-full text-xs h-7"
                      onClick={(e) => {
                        e.stopPropagation()
                        onSelectSubject(item)
                      }}
                    >
                      Assign schedule
                    </Button>
                  )}
                </div>
              )
            })}
          </div>
        </div>
      )}

      {/* Overlapping Cluster Dialog */}
      <Dialog
        open={openCluster !== null}
        onOpenChange={(open) => !open && setOpenCluster(null)}
      >
        <DialogContent className="max-h-[85dvh] max-w-3xl overflow-y-auto sm:max-w-3xl">
          <DialogHeader>
            <DialogTitle>
              {openCluster ? `${dayLabels[openCluster.day]} Classes` : "Classes"}
            </DialogTitle>
            <DialogDescription>
              Multiple classes scheduled during this time slot.
            </DialogDescription>
          </DialogHeader>
          <div className="overflow-x-auto rounded-lg border">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Subject</TableHead>
                  <TableHead>Room</TableHead>
                  <TableHead>Professor</TableHead>
                  <TableHead>Time</TableHead>
                  <TableHead>Modality</TableHead>
                  <TableHead>Status</TableHead>
                  {onSelectSubject && <TableHead className="text-right">Action</TableHead>}
                </TableRow>
              </TableHeader>
              <TableBody>
                {(openCluster?.entries ?? []).map((entry) => {
                  const isConflicting = conflictingIds.has(entry.id)
                  const clickable = !disabled && onSelectSubject !== undefined
                  return (
                    <TableRow
                      key={entry.id}
                      className={clickable ? "cursor-pointer hover:bg-muted/40" : undefined}
                      onClick={clickable ? () => onSelectSubject?.(entry) : undefined}
                    >
                      <TableCell className="font-medium">
                        <div>{entry.subject_code}</div>
                        {entry.subject_title && (
                          <div className="text-xs text-muted-foreground">
                            {entry.subject_title}
                          </div>
                        )}
                      </TableCell>
                      <TableCell>{entry.room ?? "—"}</TableCell>
                      <TableCell>{entry.professor_name ?? "Unassigned"}</TableCell>
                      <TableCell>
                        {entry.starts_at_time && entry.ends_at_time
                          ? formatTimeRange12(entry.starts_at_time, entry.ends_at_time)
                          : "—"}
                      </TableCell>
                      <TableCell>
                        {entry.modality ? modalityLabel[entry.modality] ?? entry.modality : "—"}
                      </TableCell>
                      <TableCell>{conflictBadge(isConflicting)}</TableCell>
                      {onSelectSubject && (
                        <TableCell className="text-right">
                          <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={(e) => {
                              e.stopPropagation()
                              setOpenCluster(null)
                              onSelectSubject(entry)
                            }}
                          >
                            Edit
                          </Button>
                        </TableCell>
                      )}
                    </TableRow>
                  )
                })}
              </TableBody>
            </Table>
          </div>
        </DialogContent>
      </Dialog>
    </div>
  )
}

function ScheduleClusterCell({
  cluster,
  dayIndex,
  hasConflict,
  onOpen,
}: {
  cluster: Extract<RoomCalendarPlacement<SectionScheduleItem>, { kind: "cluster" }>
  dayIndex: number
  hasConflict: boolean
  onOpen: () => void
}) {
  return (
    <button
      type="button"
      onClick={onOpen}
      className={cn(
        "m-px flex flex-col items-center justify-center gap-1 overflow-hidden rounded-md border border-dashed p-1.5 text-center transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
        hasConflict
          ? "border-destructive/60 bg-destructive/15 text-destructive hover:bg-destructive/25"
          : "border-warning/60 bg-warning/15 text-warning-foreground hover:bg-warning/25",
      )}
      style={{
        gridColumn: dayIndex + 2,
        gridRow: `${cluster.startSlot + 2} / span ${cluster.spanSlots}`,
      }}
      aria-label={`${dayLabels[cluster.day]}: ${cluster.entries.length} overlapping classes${hasConflict ? ", includes schedule conflict" : ""} — view list`}
    >
      {hasConflict ? (
        <TriangleAlert className="size-4" aria-hidden="true" />
      ) : (
        <Users className="size-4" aria-hidden="true" />
      )}
      <span className="text-[0.7rem] leading-tight font-semibold">
        {cluster.entries.length} classes
      </span>
      <span className="text-[0.65rem] leading-tight underline">View list</span>
    </button>
  )
}

function ScheduleBlockCell({
  block,
  dayIndex,
  isConflicting,
  onSelectSubject,
}: {
  block: Extract<RoomCalendarPlacement<SectionScheduleItem>, { kind: "block" }>
  dayIndex: number
  isConflicting: boolean
  onSelectSubject?: (item: SectionScheduleItem) => void
}) {
  const { entry } = block
  const laneWidthPercent = 100 / block.laneCount
  const compact = block.laneCount > 1
  const timeRange =
    entry.starts_at_time && entry.ends_at_time
      ? formatTimeRange12(entry.starts_at_time, entry.ends_at_time)
      : ""

  const label = [
    entry.subject_code,
    entry.subject_title,
    entry.room ? `Room ${entry.room}` : null,
    entry.professor_name ?? "Unassigned professor",
    dayLabels[block.day],
    timeRange,
    entry.modality ? modalityLabel[entry.modality] ?? entry.modality : null,
    isConflicting ? "schedule conflict" : null,
    block.isClipped ? "outside 7:30 AM to 9:00 PM" : null,
  ]
    .filter(Boolean)
    .join(", ")

  const clickable = onSelectSubject !== undefined
  const style = {
    gridColumn: dayIndex + 2,
    gridRow: `${block.startSlot + 2} / span ${block.spanSlots}`,
    marginLeft: `${block.lane * laneWidthPercent}%`,
    width: `calc(${laneWidthPercent}% - 2px)`,
  }

  const className = cn(
    "m-px overflow-hidden rounded-md border p-1.5 text-left leading-tight transition-all duration-150",
    compact ? "text-[0.68rem]" : "text-[0.72rem]",
    isConflicting
      ? "border-destructive/60 bg-destructive/15 text-destructive shadow-xs"
      : "border-emerald-300/90 bg-emerald-50 text-emerald-950 hover:bg-emerald-100/90 shadow-xs dark:border-emerald-700/80 dark:bg-emerald-950/60 dark:text-emerald-100 dark:hover:bg-emerald-950/80",
    clickable &&
      "cursor-pointer hover:border-emerald-500/80 hover:shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 active:scale-[0.99]",
  )

  const content = (
    <div className="flex h-full flex-col justify-between gap-0.5 overflow-hidden">
      <div>
        <p className="flex items-center gap-1 font-bold text-emerald-950 dark:text-emerald-50 truncate">
          {isConflicting && (
            <TriangleAlert className="size-3 shrink-0 text-destructive" aria-hidden="true" />
          )}
          <span className="truncate">{entry.subject_code}</span>
          {entry.room && (
            <span className="ml-auto inline-flex items-center rounded bg-emerald-100/90 dark:bg-emerald-900/80 px-1 py-0.5 text-[0.62rem] font-semibold text-emerald-900 dark:text-emerald-200 border border-emerald-300/80 dark:border-emerald-700 shrink-0">
              {entry.room}
            </span>
          )}
        </p>
        {entry.subject_title && !compact && (
          <p className="truncate text-xs text-emerald-800/90 dark:text-emerald-200/90 font-medium">
            {entry.subject_title}
          </p>
        )}
      </div>

      <div className="space-y-0.5 text-[0.65rem] text-emerald-900/80 dark:text-emerald-300/80 mt-auto">
        <p className="truncate flex items-center gap-1">
          <User className="size-2.5 shrink-0 opacity-75" aria-hidden="true" />
          <span className="truncate">{entry.professor_name ?? "Unassigned"}</span>
        </p>
        {timeRange && (
          <p className="truncate flex items-center gap-1 font-mono text-[0.62rem]">
            <Clock className="size-2.5 shrink-0 opacity-75" aria-hidden="true" />
            <span>{timeRange}</span>
          </p>
        )}
        {entry.modality && (
          <p className="truncate">
            <span className="inline-block rounded bg-emerald-200/60 dark:bg-emerald-900/60 px-1 py-0.2 text-[0.6rem] font-medium text-emerald-900 dark:text-emerald-200">
              {modalityLabel[entry.modality] ?? entry.modality}
            </span>
          </p>
        )}
      </div>

      {block.isClipped && (
        <p className="truncate text-[0.6rem] italic text-emerald-700/80 dark:text-emerald-300/80">
          Outside 7:30 AM–9:00 PM
        </p>
      )}
    </div>
  )

  if (clickable) {
    return (
      <button
        type="button"
        aria-label={`${label} — click to edit assignment`}
        title={label}
        className={className}
        style={style}
        onClick={() => onSelectSubject(entry)}
      >
        {content}
      </button>
    )
  }

  return (
    <div role="group" aria-label={label} title={label} className={className} style={style}>
      {content}
    </div>
  )
}
