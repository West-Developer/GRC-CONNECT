"use client"

import { useMemo, useState } from "react"
import { CalendarDays, ListIcon } from "lucide-react"

import { DataTable } from "@/features/components/portal/data-table"
import {
  SectionScheduleCalendar,
  type SectionScheduleItem,
} from "@/features/components/portal/section-schedule-calendar"
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
import { ToggleGroup, ToggleGroupItem } from "@/features/components/ui/toggle-group"
import { formatClockTime, formatTimeRange } from "@/features/lib/format-time"
import { formatYearLevel } from "@/features/lib/format-year-level"
import { groupPairedSubjects } from "@/features/lib/group-paired-subjects"
import type { EnrollmentBlock } from "@/features/schemas/enrollment-block-schema"

const MODALITY_LABEL: Record<string, string> = {
  online: "Online",
  hyflex_a: "HyFlex A",
  hyflex_b: "HyFlex B",
  f2f: "Face-to-face",
}

/**
 * The full weekly schedule for one section — opened from
 * `EnrollmentSectionTable`'s "View" action or calendar view.
 *
 * This only *stages* a choice: "Choose this section" hands the block code
 * back to the caller via `onChoose` and the dialog closes, but nothing is
 * submitted here — `EnrollmentWorkspace` still routes every submission
 * through its own existing confirm-submission `AlertDialog`.
 */
export function EnrollmentBlockDetailDialog({
  block,
  onOpenChange,
  onChoose,
  disabled = false,
}: {
  block: EnrollmentBlock | null
  onOpenChange: (open: boolean) => void
  onChoose: (blockCode: string) => void
  disabled?: boolean
}) {
  const [view, setView] = useState<"calendar" | "table">("table")
  const isSelectable = block !== null && block.is_selectable && !disabled

  const calendarItems: SectionScheduleItem[] = useMemo(() => {
    if (!block) return []
    return block.subjects.map((subject) => ({
      id: subject.section_id,
      subject_code: subject.code,
      subject_title: subject.title,
      units: subject.units,
      section_code: block.block_code,
      room: subject.room,
      professor_name: subject.professor_name,
      schedule_days: subject.schedule_days,
      starts_at_time: subject.starts_at_time,
      ends_at_time: subject.ends_at_time,
      modality: subject.modality ?? null,
      capacity: subject.capacity,
      enrolled_count: subject.enrolled_count,
    }))
  }, [block])

  return (
    <Dialog open={block !== null} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90dvh] max-w-6xl overflow-y-auto sm:max-w-6xl">
        {block && (
          <>
            <DialogHeader className="gap-1">
              <div className="flex flex-wrap items-center gap-2">
                <DialogTitle className="text-xl font-bold">
                  Section {block.block_code}
                </DialogTitle>
                <Badge variant="secondary">
                  {block.seats_remaining} seat
                  {block.seats_remaining === 1 ? "" : "s"} left
                </Badge>
                <Badge variant="outline">{block.total_units} units</Badge>
              </div>
              <DialogDescription>
                {formatYearLevel(block.year_level)} block section · {block.subjects.length}{" "}
                subject
                {block.subjects.length === 1 ? "" : "s"}
              </DialogDescription>
            </DialogHeader>

            {!block.is_selectable && (
              <Alert variant="destructive">
                <AlertDescription>
                  <ul className="grid gap-1">
                    {block.reasons.map((reason) => (
                      <li key={reason.code}>{reason.message}</li>
                    ))}
                  </ul>
                </AlertDescription>
              </Alert>
            )}

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
                  aria-label="Section schedule layout view"
                >
                  <ToggleGroupItem value="table" aria-label="Table view">
                    <ListIcon data-icon="inline-start" aria-hidden="true" />
                    Table
                  </ToggleGroupItem>
                  <ToggleGroupItem value="calendar" aria-label="Calendar view">
                    <CalendarDays data-icon="inline-start" aria-hidden="true" />
                    Calendar
                  </ToggleGroupItem>
                </ToggleGroup>
                <span className="text-xs text-muted-foreground">
                  {view === "calendar"
                    ? "Weekly class schedule across Monday to Saturday."
                    : "Tabular list of subjects in this section."}
                </span>
              </div>

              {view === "calendar" ? (
                <SectionScheduleCalendar
                  items={calendarItems}
                  disabled
                />
              ) : (
                <DataTable
                  caption={`${block.block_code} weekly schedule`}
                  rowKey={(subject) => subject.section_id}
                  rows={groupPairedSubjects(block.subjects)}
                  columns={[
                    {
                      key: "subject",
                      header: "Subject",
                      render: (subject) => `${subject.code} — ${subject.title}`,
                    },
                    {
                      key: "units",
                      header: "Units",
                      render: (subject) => subject.units,
                    },
                    {
                      key: "schedule",
                      header: "Day · Time",
                      render: (subject) =>
                        subject.schedule_days
                          ? `${subject.schedule_days} ${
                              subject.starts_at_time && subject.ends_at_time
                                ? formatTimeRange(
                                    subject.starts_at_time,
                                    subject.ends_at_time,
                                  )
                                : subject.starts_at_time
                                  ? formatClockTime(subject.starts_at_time)
                                  : ""
                            }`.trim()
                          : "Not yet scheduled",
                    },
                    {
                      key: "room",
                      header: "Room",
                      render: (subject) => subject.room ?? "—",
                    },
                    {
                      key: "professor",
                      header: "Professor",
                      render: (subject) =>
                        subject.professor_name ?? "To be announced",
                    },
                    {
                      key: "modality",
                      header: "Modality",
                      render: (subject) =>
                        subject.modality ? MODALITY_LABEL[subject.modality] : "—",
                    },
                  ]}
                />
              )}
            </div>

            <DialogFooter className="mt-2 flex-wrap items-center justify-between gap-2 border-t pt-4">
              <Button
                type="button"
                variant="outline"
                onClick={() => onOpenChange(false)}
              >
                Cancel
              </Button>
              <Button
                type="button"
                disabled={!isSelectable}
                onClick={() => onChoose(block.block_code)}
              >
                Choose this section
              </Button>
            </DialogFooter>
          </>
        )}
      </DialogContent>
    </Dialog>
  )
}
