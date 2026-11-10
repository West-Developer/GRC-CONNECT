"use client"

import { useState } from "react"
import { TriangleAlert, Users } from "lucide-react"

import {
  CALENDAR_DAYS,
  SLOT_COUNT,
  formatTimeRange12,
  freeSlots,
  modalityLabel,
  slotLabel,
  type RoomCalendarPlacement,
} from "@/features/lib/room-calendar"
import type { RoomOccupancyEntry } from "@/features/schemas/room-occupancy-schema"
import { cn } from "@/features/lib/utils"
import { useElementWidth } from "@/features/hooks/use-element-width"
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

/** The week grid is `min-w-[46rem]`; a box narrower than that shows one day at a time instead of scrolling sideways. */
const WEEK_GRID_MIN_WIDTH_PX = 46 * 16

interface RoomScheduleCalendarProps {
  week: Map<number, RoomCalendarPlacement<RoomOccupancyEntry>[]>
  onSelectSlot: (day: number, slotIndex: number) => void
  /** Section ids flagged by `findConflictingIds` — a real double-booking, not a legitimate HyFlex A/B pair. */
  conflictingSectionIds?: ReadonlySet<number>
  /** Opens an existing booking for editing. Only offered for the viewer's own college — a booking's `Section` row is otherwise not visible to them at all. */
  onSelectBooking?: (entry: RoomOccupancyEntry) => void
  disabled?: boolean
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
 * Monday–Saturday columns, 30-minute rows from 7:30 AM to 9:00 PM. When the box
 * is too narrow for the whole week, one day is shown at a time (chosen with the
 * Mon–Sat buttons above the grid), so nothing ever scrolls sideways.
 */
export function RoomScheduleCalendar({
  week,
  onSelectSlot,
  conflictingSectionIds,
  onSelectBooking,
  disabled = false,
}: RoomScheduleCalendarProps) {
  const [openCluster, setOpenCluster] = useState<{
    day: number
    entries: RoomOccupancyEntry[]
  } | null>(null)
  const [boxRef, boxWidth] = useElementWidth<HTMLDivElement>()
  const oneDayAtATime = boxWidth > 0 && boxWidth < WEEK_GRID_MIN_WIDTH_PX
  const [selectedDay, setSelectedDay] = useState<number>(CALENDAR_DAYS[0])
  const visibleDays: readonly number[] = oneDayAtATime
    ? [selectedDay]
    : CALENDAR_DAYS

  return (
    <div ref={boxRef} className="overflow-x-auto rounded-lg border">
      {oneDayAtATime && (
        <div
          role="group"
          aria-label="Day of the week"
          className="flex flex-wrap gap-1 border-b bg-muted/40 p-2"
        >
          {CALENDAR_DAYS.map((day) => (
            <Button
              key={day}
              type="button"
              size="sm"
              variant={day === selectedDay ? "default" : "outline"}
              aria-pressed={day === selectedDay}
              onClick={() => setSelectedDay(day)}
            >
              {dayShortLabels[day]}
            </Button>
          ))}
        </div>
      )}
      <div
        className={cn("grid w-full", !oneDayAtATime && "min-w-[46rem]")}
        style={{
          gridTemplateColumns: oneDayAtATime
            ? "4.25rem minmax(0, 1fr)"
            : "4.25rem repeat(6, minmax(7rem, 1fr))",
          gridTemplateRows: `auto repeat(${SLOT_COUNT}, 2.5rem)`,
        }}
      >
        <div className="sticky top-0 left-0 z-20 border-r border-b bg-muted/60" />
        {visibleDays.map((day) => (
          <div
            key={day}
            className="sticky top-0 z-10 border-b bg-muted/60 px-2 py-2 text-center text-sm font-semibold"
          >
            {oneDayAtATime ? dayLabels[day] : dayShortLabels[day]}
          </div>
        ))}

        {Array.from({ length: SLOT_COUNT }, (_, index) => (
          <div
            key={`time-${index}`}
            className="sticky left-0 z-10 border-r bg-background px-2 py-1 text-right text-xs text-muted-foreground"
            style={{ gridColumn: 1, gridRow: index + 2 }}
          >
            {index % 2 === 0 ? slotLabel(index) : ""}
          </div>
        ))}

        {visibleDays.map((day, dayIndex) =>
          freeSlots(week, day).map((slotIndex) => (
            <button
              key={`free-${day}-${slotIndex}`}
              type="button"
              onClick={() => onSelectSlot(day, slotIndex)}
              disabled={disabled}
              className="group border-r border-b border-dashed border-border/60 transition-colors hover:bg-primary/5 focus-visible:bg-primary/10 focus-visible:outline-none disabled:pointer-events-none disabled:opacity-50"
              style={{ gridColumn: dayIndex + 2, gridRow: slotIndex + 2 }}
              aria-label={`${dayLabels[day]} ${slotLabel(slotIndex)} — available, assign a class`}
            >
              <span className="hidden text-[0.65rem] text-primary group-hover:inline group-focus-visible:inline">
                Available
              </span>
            </button>
          )),
        )}

        {visibleDays.map((day, dayIndex) =>
          (week.get(day) ?? []).map((placement, index) =>
            placement.kind === "cluster" ? (
              <RoomCalendarClusterCell
                key={`cluster-${day}-${index}`}
                cluster={placement}
                dayIndex={dayIndex}
                hasConflict={placement.entries.some((entry) =>
                  conflictingSectionIds?.has(entry.section_id),
                )}
                onOpen={() => setOpenCluster({ day, entries: placement.entries })}
              />
            ) : (
              <RoomCalendarBlockCell
                key={`block-${day}-${index}`}
                block={placement}
                dayIndex={dayIndex}
                isConflicting={conflictingSectionIds?.has(placement.entry.section_id) ?? false}
                onSelectBooking={onSelectBooking}
              />
            ),
          ),
        )}
      </div>

      <Dialog open={openCluster !== null} onOpenChange={(open) => !open && setOpenCluster(null)}>
        <DialogContent className="max-h-[85dvh] max-w-3xl overflow-y-auto sm:max-w-3xl">
          <DialogHeader>
            <DialogTitle>
              {openCluster ? `${dayLabels[openCluster.day]} bookings` : "Bookings"}
            </DialogTitle>
            <DialogDescription>
              Too many overlapping bookings to show side by side — every one is listed here.
              Complementary HyFlex A/B pairs are expected to share a room and time; anything
              flagged Conflict is a real double-booking.
            </DialogDescription>
          </DialogHeader>
          <div className="overflow-x-auto rounded-lg border">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Subject</TableHead>
                  <TableHead>Section</TableHead>
                  <TableHead>Professor</TableHead>
                  <TableHead>Time</TableHead>
                  <TableHead>Modality</TableHead>
                  <TableHead>College</TableHead>
                  <TableHead>Status</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {(openCluster?.entries ?? []).map((entry) => {
                  const isConflicting = conflictingSectionIds?.has(entry.section_id) ?? false
                  const clickable = entry.is_own_college && onSelectBooking !== undefined
                  return (
                    <TableRow
                      key={entry.section_id}
                      className={clickable ? "cursor-pointer hover:bg-muted/40" : undefined}
                      onClick={clickable ? () => onSelectBooking?.(entry) : undefined}
                    >
                      <TableCell className="font-medium">{entry.subject_code}</TableCell>
                      <TableCell>{entry.section_code}</TableCell>
                      <TableCell>{entry.professor_name ?? "Unassigned"}</TableCell>
                      <TableCell>
                        {entry.starts_at_time && entry.ends_at_time
                          ? formatTimeRange12(entry.starts_at_time, entry.ends_at_time)
                          : "—"}
                      </TableCell>
                      <TableCell>{entry.modality ? modalityLabel[entry.modality] : "—"}</TableCell>
                      <TableCell>
                        {entry.is_own_college ? (
                          <Badge variant="secondary">{entry.college?.toUpperCase() ?? "This college"}</Badge>
                        ) : (
                          <Badge variant="outline">{entry.college?.toUpperCase()}</Badge>
                        )}
                      </TableCell>
                      <TableCell>{conflictBadge(isConflicting)}</TableCell>
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

function RoomCalendarClusterCell({
  cluster,
  dayIndex,
  hasConflict,
  onOpen,
}: {
  cluster: Extract<RoomCalendarPlacement<RoomOccupancyEntry>, { kind: "cluster" }>
  dayIndex: number
  hasConflict: boolean
  onOpen: () => void
}) {
  return (
    <button
      type="button"
      onClick={onOpen}
      className={cn(
        "m-px flex flex-col items-center justify-center gap-1 overflow-hidden rounded-md border border-dashed p-1.5 text-center transition-colors focus-visible:outline-none",
        hasConflict
          ? "border-destructive/50 bg-destructive/10 text-destructive hover:bg-destructive/20"
          : "border-warning/50 bg-warning/10 text-warning hover:bg-warning/20",
      )}
      style={{
        gridColumn: dayIndex + 2,
        gridRow: `${cluster.startSlot + 2} / span ${cluster.spanSlots}`,
      }}
      aria-label={`${dayLabels[cluster.day]}: ${cluster.entries.length} overlapping bookings${hasConflict ? ", includes a real conflict" : ""} — view the list`}
    >
      {hasConflict ? (
        <TriangleAlert className="size-4" aria-hidden="true" />
      ) : (
        <Users className="size-4" aria-hidden="true" />
      )}
      <span className="text-[0.7rem] leading-tight font-semibold">
        {cluster.entries.length} bookings
      </span>
      <span className="text-[0.65rem] leading-tight underline">View list</span>
    </button>
  )
}

function RoomCalendarBlockCell({
  block,
  dayIndex,
  isConflicting,
  onSelectBooking,
}: {
  block: Extract<RoomCalendarPlacement<RoomOccupancyEntry>, { kind: "block" }>
  dayIndex: number
  isConflicting: boolean
  onSelectBooking?: (entry: RoomOccupancyEntry) => void
}) {
  const { entry } = block
  const laneWidthPercent = 100 / block.laneCount
  const compact = block.laneCount > 1
  const timeRange =
    entry.starts_at_time && entry.ends_at_time
      ? formatTimeRange12(entry.starts_at_time, entry.ends_at_time)
      : ""
  const isSectionOverlay = Boolean(entry.is_section_overlay)
  const label = [
    isSectionOverlay ? `[Section ${entry.section_code} Schedule]` : null,
    entry.subject_code,
    entry.section_code,
    entry.room ? `Room ${entry.room}` : null,
    entry.professor_name ?? "Unassigned professor",
    dayLabels[block.day],
    timeRange,
    entry.modality ? modalityLabel[entry.modality] : null,
    entry.college ? entry.college.toUpperCase() : null,
    isConflicting ? "room conflict" : null,
    block.isClipped ? "outside 7:30 AM to 9:00 PM" : null,
  ]
    .filter(Boolean)
    .join(", ")
  const clickable = !isSectionOverlay && entry.is_own_college && onSelectBooking !== undefined
  const style = {
    gridColumn: dayIndex + 2,
    gridRow: `${block.startSlot + 2} / span ${block.spanSlots}`,
    marginLeft: `${block.lane * laneWidthPercent}%`,
    width: `calc(${laneWidthPercent}% - 2px)`,
  }
  const className = cn(
    "m-px overflow-hidden rounded-md border p-1.5 text-left leading-tight transition-all",
    compact ? "text-[0.68rem]" : "text-[0.72rem]",
    isConflicting
      ? "border-destructive/60 bg-destructive/10 text-destructive"
      : isSectionOverlay
        ? "border-emerald-400/80 bg-emerald-100 text-emerald-950 shadow-xs ring-1 ring-emerald-400/50 dark:border-emerald-700 dark:bg-emerald-950/75 dark:text-emerald-100"
        : entry.is_own_college
          ? "border-primary/40 bg-primary/10 text-foreground"
          : "border-dashed border-muted-foreground/50 bg-muted/60 text-muted-foreground",
    clickable && "cursor-pointer hover:brightness-95 focus-visible:outline-none",
  )
  const content = (
    <>
      <p className="flex items-center gap-1 truncate font-semibold">
        {isConflicting && <TriangleAlert className="size-3 shrink-0" aria-hidden="true" />}
        {isSectionOverlay && (
          <span className="size-2 shrink-0 rounded-full bg-emerald-600 dark:bg-emerald-400" />
        )}
        {entry.subject_code}
      </p>
      {compact ? (
        <>
          {timeRange && <p className="truncate font-medium">{timeRange}</p>}
          {isSectionOverlay ? (
            <p className="truncate font-medium text-emerald-800 dark:text-emerald-300">
              {entry.room ? `Room ${entry.room}` : "Room not set"}
            </p>
          ) : (
            !entry.is_own_college && entry.college && (
              <p className="truncate font-semibold uppercase">{entry.college}</p>
            )
          )}
        </>
      ) : (
        <>
          <p className="truncate font-medium">
            {entry.section_code}
            {isSectionOverlay && entry.room ? ` · Room ${entry.room}` : ""}
          </p>
          <p className="truncate">{entry.professor_name ?? "Unassigned"}</p>
          {timeRange && <p className="truncate">{timeRange}</p>}
          {!isSectionOverlay && !entry.is_own_college && entry.college && (
            <p className="truncate font-semibold uppercase">{entry.college}</p>
          )}
        </>
      )}
      {block.isClipped && <p className="truncate italic">Outside grid hours</p>}
    </>
  )

  if (clickable) {
    return (
      <button
        type="button"
        aria-label={`${label} — edit this booking`}
        title={label}
        className={className}
        style={style}
        onClick={() => onSelectBooking?.(entry)}
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
