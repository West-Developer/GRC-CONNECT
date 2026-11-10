"use client"

import { useMemo, useState } from "react"
import { CalendarDays, ListIcon, PencilLine } from "lucide-react"

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
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/features/components/ui/table"
import { ToggleGroup, ToggleGroupItem } from "@/features/components/ui/toggle-group"
import { formatTimeRange12, modalityLabel } from "@/features/lib/room-calendar"
import {
  SectionScheduleCalendar,
  type SectionScheduleItem,
} from "@/features/components/portal/section-schedule-calendar"

interface SectionScheduleCalendarDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  title: string
  subtitle?: string
  items: readonly SectionScheduleItem[]
  onSelectSubject?: (item: SectionScheduleItem) => void
  disabled?: boolean
  defaultView?: "calendar" | "table"
  /** Custom label for subject action button, e.g. "Edit" or a function */
  actionLabel?: string | ((item: SectionScheduleItem) => string)
  /** Callback to check if a specific item's action button should be disabled */
  isItemDisabled?: (item: SectionScheduleItem) => boolean
  /** Optional action button rendered in the footer (e.g. Choose Section button for students) */
  footerAction?: React.ReactNode
}

export function SectionScheduleCalendarDialog({
  open,
  onOpenChange,
  title,
  subtitle,
  items,
  onSelectSubject,
  disabled = false,
  defaultView = "table",
  actionLabel,
  isItemDisabled,
  footerAction,
}: SectionScheduleCalendarDialogProps) {
  const [view, setView] = useState<"calendar" | "table">(defaultView)

  const totalUnits = useMemo(
    () => items.reduce((sum, item) => sum + (item.units ?? 0), 0),
    [items],
  )

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[92dvh] max-w-6xl overflow-y-auto sm:max-w-6xl">
        <DialogHeader className="gap-1">
          <div className="flex flex-wrap items-center gap-2">
            <DialogTitle className="text-xl font-bold">{title}</DialogTitle>
            <Badge variant="secondary">{items.length} {items.length === 1 ? "subject" : "subjects"}</Badge>
            {totalUnits > 0 && <Badge variant="outline">{totalUnits} units</Badge>}
          </div>
          {subtitle && <DialogDescription>{subtitle}</DialogDescription>}
        </DialogHeader>

        <div className="grid gap-4">
          <div className="flex flex-wrap items-center justify-between gap-3 border-b pb-3">
            <ToggleGroup
              type="single"
              value={view}
              onValueChange={(val) => {
                if (val === "calendar" || val === "table") setView(val)
              }}
              variant="outline"
              size="sm"
              aria-label="Schedule layout view"
            >
              <ToggleGroupItem value="table" aria-label="Table view">
                <ListIcon data-icon="inline-start" aria-hidden="true" />
                Schedule list
              </ToggleGroupItem>
              <ToggleGroupItem value="calendar" aria-label="Calendar view">
                <CalendarDays data-icon="inline-start" aria-hidden="true" />
                View in calendar
              </ToggleGroupItem>
            </ToggleGroup>

            <span className="text-xs text-muted-foreground">
              {view === "calendar"
                ? "Weekly visual timetable across Monday to Saturday."
                : "Weekly class list in tabular format. Click \"View in calendar\" to see the timetable."}
            </span>
          </div>

          {view === "calendar" ? (
            <SectionScheduleCalendar
              items={items}
              onSelectSubject={onSelectSubject}
              disabled={disabled}
            />
          ) : (
            <div className="overflow-x-auto rounded-lg border">
              <Table data-stack-mobile>
                <TableHeader>
                  <TableRow>
                    <TableHead>Subject code</TableHead>
                    <TableHead>Description</TableHead>
                    <TableHead>Units</TableHead>
                    <TableHead>Sched ID</TableHead>
                    <TableHead>Day(s)</TableHead>
                    <TableHead>Time</TableHead>
                    <TableHead>Room</TableHead>
                    <TableHead>Professor</TableHead>
                    <TableHead>Modality</TableHead>
                    {onSelectSubject && <TableHead className="text-right">Action</TableHead>}
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {items.map((item) => {
                    const itemDisabled = disabled || (isItemDisabled ? isItemDisabled(item) : false)
                    const label = typeof actionLabel === "function" ? actionLabel(item) : (actionLabel ?? "Assign schedule")
                    return (
                      <TableRow key={item.id}>
                        <TableCell data-stack="full" className="font-semibold">{item.subject_code}</TableCell>
                        <TableCell data-stack="full">{item.subject_title ?? "—"}</TableCell>
                        <TableCell data-label="Units">{item.units ?? "—"}</TableCell>
                        <TableCell data-label="Sched ID" className="font-mono text-xs text-muted-foreground">{item.id}</TableCell>
                        <TableCell data-label="Day(s)">{item.schedule_days ?? "—"}</TableCell>
                        <TableCell data-label="Time">
                          {item.starts_at_time && item.ends_at_time
                            ? formatTimeRange12(item.starts_at_time, item.ends_at_time)
                            : "—"}
                        </TableCell>
                        <TableCell data-label="Room">{item.room ?? "—"}</TableCell>
                        <TableCell data-label="Professor">
                          {item.professor_name ? (
                            item.professor_name
                          ) : (
                            <Badge variant="destructive" className="text-xs font-normal">
                              Unassigned
                            </Badge>
                          )}
                        </TableCell>
                        <TableCell data-label="Modality">
                          {item.modality ? (
                            <Badge variant="secondary" className="text-xs font-normal">
                              {modalityLabel[item.modality] ?? item.modality.toUpperCase()}
                            </Badge>
                          ) : (
                            "—"
                          )}
                        </TableCell>
                        {onSelectSubject && (
                          <TableCell data-stack="full" className="text-right">
                            <Button
                              type="button"
                              size="sm"
                              variant="outline"
                              disabled={itemDisabled}
                              onClick={() => onSelectSubject(item)}
                            >
                              <PencilLine data-icon="inline-start" className="size-3.5" />
                              {label}
                            </Button>
                          </TableCell>
                        )}
                      </TableRow>
                    )
                  })}
                  {items.length === 0 && (
                    <TableRow>
                      <TableCell colSpan={onSelectSubject ? 10 : 9} data-stack="full" className="py-8 text-center text-muted-foreground">
                        No subjects found for this section.
                      </TableCell>
                    </TableRow>
                  )}
                </TableBody>
              </Table>
            </div>
          )}
        </div>

        <DialogFooter className="mt-2 flex-wrap items-center justify-between gap-2 border-t pt-4">
          <Button
            type="button"
            variant="outline"
            onClick={() => onOpenChange(false)}
          >
            Close
          </Button>
          {footerAction}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

