"use client"

import { useMemo, useState } from "react"

import {
  CalendarDays,
  CheckCircle2,
  ListIcon,
  Trash2,
  TriangleAlert,
} from "lucide-react"

import {
  DataTable,
  type DataTableColumn,
} from "@/features/components/portal/data-table"
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
  DialogHeader,
  DialogTitle,
} from "@/features/components/ui/dialog"
import { SearchableCombobox } from "@/features/components/ui/searchable-combobox"
import { Skeleton } from "@/features/components/ui/skeleton"
import { Textarea } from "@/features/components/ui/textarea"
import {
  ToggleGroup,
  ToggleGroupItem,
} from "@/features/components/ui/toggle-group"
import {
  useEligibleSubjectsQuery,
  useReviseEnrollmentSubjectsMutation,
} from "@/features/hooks/use-enrollment"
import { formatClockTime, formatTimeRange } from "@/features/lib/format-time"
import { findConflictingIds } from "@/features/lib/room-calendar"
import { isApiClientError } from "@/features/services/api-client"
import type { Enrollment } from "@/features/schemas/enrollment-schema"

interface ProposedRow {
  section_id: number
  subject_code: string
  subject_title: string
  section_code: string | null
  units: number | null
  schedule_days: string | null
  starts_at_time: string | null
  ends_at_time: string | null
  room: string | null
  professor_name: string | null
}

interface SectionChoice extends ProposedRow {
  subject_id: number
  curriculum_subject_id: number
  college: string | null
  paired_subject_id: number | null
  remaining_seats: number
  is_held: boolean
}

function displayTimeRange(
  startsAt: string | null,
  endsAt: string | null,
): string {
  if (!startsAt || !endsAt) return "Not assigned"
  return formatTimeRange(startsAt, endsAt)
}

export function StudentProposeSubjectsDialog({
  enrollment,
  open,
  onOpenChange,
  onProposed,
}: {
  enrollment: Enrollment | null
  open: boolean
  onOpenChange: (open: boolean) => void
  onProposed?: (enrollment: Enrollment) => void
}) {
  const eligibleQuery = useEligibleSubjectsQuery(
    open ? (enrollment?.academic_term_id ?? null) : null,
    enrollment?.id ?? null,
  )
  const isLoading = eligibleQuery.isPending
  const choices = useMemo<SectionChoice[]>(() => {
    const held = (enrollment?.subjects ?? []).filter(
      (item) => item.status !== "dropped",
    )
    const available = (eligibleQuery.data ?? [])
      .filter((entry) => entry.is_eligible)
      .flatMap((entry) =>
        entry.available_sections.map((section) => ({
          section_id: section.id,
          subject_id: section.subject_id,
          curriculum_subject_id: entry.subject_id,
          college: section.college,
          paired_subject_id: entry.paired_subject_id,
          subject_code: section.subject_code,
          subject_title: section.subject_title,
          units: entry.units,
          section_code: section.section_code,
          schedule_days: section.schedule_days,
          starts_at_time: section.starts_at_time,
          ends_at_time: section.ends_at_time,
          room: section.room,
          professor_name: section.professor_name ?? null,
          remaining_seats: section.remaining_seats,
          is_held: held.some((item) => item.section_id === section.id),
        })),
      )
    return [
      ...available,
      ...held
        .filter(
          (item) =>
            !available.some((choice) => choice.section_id === item.section_id),
        )
        .map((item) => {
          const entry = (eligibleQuery.data ?? []).find(
            (candidate) =>
              candidate.code === item.subject_code &&
              candidate.units === item.units,
          )
          return {
            section_id: item.section_id,
            subject_id: item.subject_id,
            curriculum_subject_id: entry?.subject_id ?? item.subject_id,
            paired_subject_id:
              entry?.paired_subject_id ?? item.paired_subject_id,
            college: null,
            subject_code: item.subject_code,
            subject_title: item.subject_title ?? "—",
            units: item.units ?? null,
            section_code: item.section_code ?? null,
            schedule_days: item.schedule_days ?? null,
            starts_at_time: item.starts_at_time ?? null,
            ends_at_time: item.ends_at_time ?? null,
            room: item.room ?? null,
            professor_name: item.professor_name ?? null,
            remaining_seats: 0,
            is_held: true,
          }
        }),
    ]
  }, [eligibleQuery.data, enrollment])
  const [choiceSnapshots, setChoiceSnapshots] = useState<
    Record<number, SectionChoice>
  >({})

  const originalSectionIds = useMemo(
    () =>
      (enrollment?.subjects ?? [])
        .filter((s) => s.status !== "dropped")
        .map((s) => s.section_id),
    [enrollment],
  )

  const [pendingSectionIds, setPendingSectionIds] = useState<number[] | null>(
    null,
  )
  const [initializedFor, setInitializedFor] = useState<number | null>(null)
  const [proposalNote, setProposalNote] = useState("")

  if (enrollment && initializedFor !== enrollment.id) {
    setInitializedFor(enrollment.id)
    setPendingSectionIds(null)
    setProposalNote("")
    setChoiceSnapshots({})
  }

  const currentSectionIds = pendingSectionIds ?? originalSectionIds
  const reviseMutation = useReviseEnrollmentSubjectsMutation()
  const [proposalError, setProposalError] = useState<string | null>(null)
  const [view, setView] = useState<"table" | "calendar">("table")

  const rows: ProposedRow[] = useMemo(() => {
    if (!enrollment) return []
    return currentSectionIds.map((sectionId) => {
      const choice =
        choices.find((item) => item.section_id === sectionId) ??
        choiceSnapshots[sectionId]
      const original = enrollment.subjects.find(
        (s) => s.section_id === sectionId,
      )
      const source = choice ?? original

      return {
        section_id: sectionId,
        subject_code: source?.subject_code ?? `#${sectionId}`,
        subject_title: source?.subject_title ?? "—",
        section_code: source?.section_code ?? null,
        units: source?.units ?? null,
        schedule_days: source?.schedule_days ?? null,
        starts_at_time: source?.starts_at_time ?? null,
        ends_at_time: source?.ends_at_time ?? null,
        room: source?.room ?? null,
        professor_name: source?.professor_name ?? null,
      }
    })
  }, [enrollment, choices, choiceSnapshots, currentSectionIds])

  const addableOptions = useMemo(() => {
    if (!enrollment) return []
    return choices
      .filter((s) => !currentSectionIds.includes(s.section_id))
      .filter((s) => s.is_held || s.remaining_seats > 0)
      .map((s) => {
        const schedule =
          s.schedule_days && s.starts_at_time && s.ends_at_time
            ? ` · ${s.schedule_days} ${formatTimeRange(s.starts_at_time, s.ends_at_time)}`
            : s.schedule_days && s.starts_at_time
              ? ` · ${s.schedule_days} ${formatClockTime(s.starts_at_time)}`
              : ""

        return {
          value: String(s.section_id),
          label: `${s.subject_code} — Section ${s.section_code}${schedule} (${s.is_held ? "your reserved seat" : `${s.remaining_seats} seats`})`,
        }
      })
  }, [enrollment, choices, currentSectionIds])

  const totalUnits = rows.reduce((sum, row) => sum + (row.units ?? 0), 0)
  const hasUnknownUnits = rows.some((row) => row.units === null)
  const regularMax = enrollment?.regular_unit_maximum ?? null
  const overloadMax = enrollment?.unit_maximum ?? null
  const isOverload = regularMax !== null && totalUnits > regularMax
  const isBeyondHardCap = overloadMax !== null && totalUnits > overloadMax
  const hasIncompleteSchedule = rows.some(
    (row) => !row.schedule_days || !row.starts_at_time || !row.ends_at_time,
  )

  const calendarItems: SectionScheduleItem[] = useMemo(() => {
    return rows.map((row) => ({
      id: row.section_id,
      subject_code: row.subject_code,
      subject_title: row.subject_title,
      units: row.units,
      section_code: row.section_code,
      room: row.room,
      professor_name: row.professor_name,
      schedule_days: row.schedule_days,
      starts_at_time: row.starts_at_time,
      ends_at_time: row.ends_at_time,
      modality: null,
    }))
  }, [rows])

  const conflictingIds = useMemo(
    () => findConflictingIds(calendarItems, (item) => item.id),
    [calendarItems],
  )

  function handleAddSection(value: string) {
    const sectionId = Number(value)
    const choice = choices.find((item) => item.section_id === sectionId)
    if (!choice) return
    const pair =
      choice.paired_subject_id === null
        ? undefined
        : choices.find(
            (item) =>
              item.curriculum_subject_id === choice.paired_subject_id &&
              item.section_code === choice.section_code &&
              item.college === choice.college,
          )
    if (choice.paired_subject_id !== null && !pair) {
      setProposalError(
        "The matching lecture or laboratory section is unavailable. Ask your Program Chair for help.",
      )
      return
    }
    const added = pair ? [choice, pair] : [choice]
    const courses = new Set(
      added.map((item) => `${item.subject_code}|${item.units}`),
    )
    const kept = currentSectionIds.filter((id) => {
      const row = rows.find((item) => item.section_id === id)
      return (
        row === undefined || !courses.has(`${row.subject_code}|${row.units}`)
      )
    })
    setChoiceSnapshots((previous) => ({
      ...previous,
      ...Object.fromEntries(added.map((item) => [item.section_id, item])),
    }))
    setPendingSectionIds([...kept, ...added.map((item) => item.section_id)])
    setProposalError(null)
  }

  function handleRemoveSection(sectionId: number) {
    if (currentSectionIds.length <= 1) return
    const original = enrollment?.subjects.find(
      (item) => item.section_id === sectionId,
    )
    const selected =
      choices.find((item) => item.section_id === sectionId) ??
      choiceSnapshots[sectionId]
    const pairedId = selected?.paired_subject_id ?? original?.paired_subject_id
    const kept = currentSectionIds.filter((id) => {
      if (id === sectionId) return false
      const choice =
        choices.find((item) => item.section_id === id) ?? choiceSnapshots[id]
      const subjectId =
        choice?.curriculum_subject_id ??
        enrollment?.subjects.find((item) => item.section_id === id)?.subject_id
      return pairedId == null || subjectId !== pairedId
    })
    if (kept.length === 0) {
      setProposalError(
        "Keep at least one subject or lecture/laboratory pair in your proposal.",
      )
      return
    }
    setPendingSectionIds(kept)
    setProposalError(null)
  }

  function handleSendProposal() {
    if (!enrollment) return
    if (proposalNote.trim() === "") {
      setProposalError(
        "Tell your Program Chair why you are proposing these subjects.",
      )
      return
    }
    if (currentSectionIds.length === 0) {
      setProposalError("Select at least one subject to propose.")
      return
    }
    if (isBeyondHardCap) {
      setProposalError(
        `Total units (${totalUnits}) exceeds the maximum allowed load of ${overloadMax} units.`,
      )
      return
    }
    if (conflictingIds.size > 0) {
      setProposalError(
        "Some of the selected subjects have overlapping class hours. Please resolve schedule conflicts.",
      )
      return
    }

    setProposalError(null)
    reviseMutation.mutate(
      {
        id: enrollment.id,
        sectionIds: currentSectionIds,
        note: proposalNote.trim(),
        overloadAcknowledged: isOverload,
      },
      {
        onSuccess: (updated) => {
          onProposed?.(updated)
          onOpenChange(false)
        },
        onError: (err: unknown) => {
          const fieldErrors = isApiClientError(err)
            ? Object.values(err.fieldErrors ?? {}).flat()
            : []
          setProposalError(
            fieldErrors[0] ??
              (err instanceof Error
                ? err.message
                : "Could not submit your subject proposal. Please try again."),
          )
        },
      },
    )
  }

  const columns: DataTableColumn<ProposedRow>[] = [
    {
      key: "code",
      header: "Subject code",
      render: (row) => (
        <span className="font-mono font-semibold">{row.subject_code}</span>
      ),
    },
    {
      key: "description",
      header: "Description",
      render: (row) => row.subject_title,
    },
    {
      key: "units",
      header: "Units",
      render: (row) => row.units ?? "—",
    },
    {
      key: "section",
      header: "Section",
      render: (row) => row.section_code ?? "—",
    },
    {
      key: "day",
      header: "Day",
      render: (row) => row.schedule_days ?? "Not assigned",
    },
    {
      key: "time",
      header: "Time",
      render: (row) => displayTimeRange(row.starts_at_time, row.ends_at_time),
    },
    {
      key: "room",
      header: "Room",
      render: (row) => row.room ?? "Not assigned",
    },
    {
      key: "actions",
      header: "",
      render: (row) => (
        <Button
          type="button"
          variant="ghost"
          size="icon-sm"
          disabled={currentSectionIds.length <= 1}
          onClick={() => handleRemoveSection(row.section_id)}
          className="text-destructive hover:bg-destructive/10 hover:text-destructive"
        >
          <Trash2 className="size-4" />
          <span className="sr-only">Remove {row.subject_code}</span>
        </Button>
      ),
    },
  ]

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        if (!reviseMutation.isPending) onOpenChange(next)
      }}
    >
      <DialogContent
        className="max-h-[90vh] overflow-y-auto sm:max-w-6xl"
        aria-describedby="propose-subjects-description"
      >
        <DialogHeader>
          <DialogTitle>Propose different subjects</DialogTitle>
          <DialogDescription id="propose-subjects-description">
            Customize the subjects and sections you want to take and submit them
            to your Program Chair for review.
          </DialogDescription>
        </DialogHeader>

        {isLoading ? (
          <div className="space-y-4 py-4">
            <Skeleton className="h-20 w-full" />
            <Skeleton className="h-48 w-full" />
          </div>
        ) : eligibleQuery.isError ? (
          <div className="space-y-3 py-4">
            <Alert variant="destructive" role="alert">
              <AlertDescription>
                Could not load available subjects and sections.
              </AlertDescription>
            </Alert>
            <Button
              type="button"
              variant="outline"
              onClick={() => {
                void eligibleQuery.refetch()
              }}
            >
              Try again
            </Button>
          </div>
        ) : (
          <div className="space-y-4 py-2">
            {/* Academic Load & Status Bar */}
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
              <div className="rounded-lg border bg-muted/20 p-3">
                <span className="text-xs text-muted-foreground">
                  Proposed Load
                </span>
                <div className="flex items-baseline gap-1 mt-1">
                  <span className="text-xl font-bold">
                    {hasUnknownUnits ? "Not available" : totalUnits}
                  </span>
                  <span className="text-xs text-muted-foreground">
                    units ({rows.length} subjects)
                  </span>
                </div>
                <span className="text-xs text-muted-foreground block mt-1">
                  {regularMax === null ? (
                    "Regular unit limit not configured"
                  ) : (
                    <>
                      Allowed regular:{" "}
                      <strong className="font-semibold">
                        {regularMax} units
                      </strong>
                    </>
                  )}
                  {overloadMax !== null ? ` · max ${overloadMax}` : ""}
                </span>
              </div>

              <div className="rounded-lg border bg-muted/20 p-3">
                <span className="text-xs text-muted-foreground">
                  Unit Overload Status
                </span>
                <div className="mt-1">
                  {hasUnknownUnits ? (
                    <Badge variant="outline">Unit total unavailable</Badge>
                  ) : isBeyondHardCap ? (
                    <Badge variant="destructive">Exceeds Maximum Cap</Badge>
                  ) : isOverload ? (
                    <Badge
                      variant="secondary"
                      className="bg-amber-100 text-amber-900 border-amber-300"
                    >
                      Overload (Requires Chair Approval)
                    </Badge>
                  ) : regularMax === null ? (
                    <Badge variant="outline">Regular limit unavailable</Badge>
                  ) : (
                    <Badge
                      variant="outline"
                      className="border-emerald-500/40 text-emerald-700 bg-emerald-50"
                    >
                      Within Regular Load
                    </Badge>
                  )}
                </div>
                <span className="text-xs text-muted-foreground block mt-1">
                  {hasUnknownUnits
                    ? "Unit information is incomplete"
                    : isOverload
                      ? "Requires Program Chair review"
                      : regularMax === null
                        ? "Ask your Program Chair about the limit"
                        : "Standard academic load"}
                </span>
              </div>

              <div className="rounded-lg border bg-muted/20 p-3">
                <span className="text-xs text-muted-foreground">
                  Schedule Conflict Check
                </span>
                <div className="mt-1 flex items-center gap-1.5">
                  {conflictingIds.size > 0 ? (
                    <>
                      <TriangleAlert className="size-4 text-destructive" />
                      <span className="text-sm font-semibold text-destructive">
                        {conflictingIds.size} sections have conflicts
                      </span>
                    </>
                  ) : hasIncompleteSchedule ? (
                    <span className="text-sm font-semibold">
                      Schedule information incomplete
                    </span>
                  ) : (
                    <>
                      <CheckCircle2 className="size-4 text-emerald-600" />
                      <span className="text-sm font-semibold text-emerald-700">
                        Zero Schedule Conflicts
                      </span>
                    </>
                  )}
                </div>
                <span className="text-xs text-muted-foreground block mt-1">
                  {conflictingIds.size > 0
                    ? "Resolve overlapping hours"
                    : hasIncompleteSchedule
                      ? "Some class days or times are not assigned"
                      : "All days & times compatible"}
                </span>
              </div>
            </div>

            {/* Subject Selector Combobox */}
            {(eligibleQuery.data ?? []).some((entry) => !entry.is_eligible) && (
              <details className="rounded-lg border p-3 text-sm">
                <summary className="cursor-pointer font-medium">
                  Why some subjects are unavailable
                </summary>
                <ul className="mt-2 space-y-2">
                  {(eligibleQuery.data ?? [])
                    .filter((entry) => !entry.is_eligible)
                    .map((entry) => (
                      <li key={entry.subject_id}>
                        <strong>{entry.code}</strong>:{" "}
                        {entry.reasons
                          .map((reason) => reason.message)
                          .join(" ")}
                      </li>
                    ))}
                </ul>
              </details>
            )}
            <div className="space-y-1.5">
              <SearchableCombobox
                id="search-add-subject"
                label="Add a subject or section"
                options={addableOptions}
                value=""
                onValueChange={handleAddSection}
                placeholder="Search an available subject or section to add…"
                emptyMessage="No more open sections available for this term."
              />
            </div>

            {/* View Switcher and Subject List */}
            <div className="space-y-2">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <span className="text-sm font-semibold">
                  Your Proposed Schedule
                </span>
                <ToggleGroup
                  type="single"
                  value={view}
                  onValueChange={(val) => {
                    if (val === "table" || val === "calendar") setView(val)
                  }}
                  variant="outline"
                  size="sm"
                  aria-label="Proposed schedule view"
                >
                  <ToggleGroupItem value="table" aria-label="Table view">
                    <ListIcon className="size-4 mr-1.5" />
                    Table view
                  </ToggleGroupItem>
                  <ToggleGroupItem
                    value="calendar"
                    aria-label="Calendar timetable"
                  >
                    <CalendarDays className="size-4 mr-1.5" />
                    Calendar timetable
                  </ToggleGroupItem>
                </ToggleGroup>
              </div>

              {view === "table" ? (
                <div className="rounded-lg border overflow-hidden">
                  <DataTable
                    caption="Proposed subject schedule"
                    columns={columns}
                    rowKey={(row) => row.section_id}
                    rows={rows}
                  />
                </div>
              ) : (
                <div className="rounded-lg border bg-card p-2">
                  <SectionScheduleCalendar
                    items={calendarItems}
                    disabled={true}
                    emptyMessage="No timetable slots found for proposed subjects."
                  />
                </div>
              )}
            </div>

            {/* Reason / Note Textarea */}
            <div className="space-y-1.5 pt-2 border-t">
              <label
                htmlFor="proposal-note"
                className="text-xs font-medium text-foreground"
              >
                Why are you proposing these subjects?{" "}
                <span className="text-destructive">*</span>
              </label>
              <Textarea
                id="proposal-note"
                placeholder="Explain to your Program Chair why you are proposing these subjects (e.g. work schedule availability or preferred course path)…"
                value={proposalNote}
                maxLength={2000}
                onChange={(e) => {
                  setProposalNote(e.target.value)
                  if (proposalError) setProposalError(null)
                }}
                rows={3}
              />
            </div>

            {proposalError && (
              <Alert variant="destructive">
                <AlertDescription>{proposalError}</AlertDescription>
              </Alert>
            )}

            {/* Actions */}
            <div className="flex flex-wrap items-center justify-end gap-2 pt-2 border-t">
              <Button
                type="button"
                variant="outline"
                disabled={reviseMutation.isPending}
                onClick={() => onOpenChange(false)}
              >
                Cancel
              </Button>
              <Button
                type="button"
                disabled={
                  reviseMutation.isPending ||
                  rows.length === 0 ||
                  hasUnknownUnits ||
                  isBeyondHardCap ||
                  conflictingIds.size > 0
                }
                onClick={handleSendProposal}
              >
                {reviseMutation.isPending
                  ? "Submitting proposal…"
                  : "Submit proposal to Program Chair"}
              </Button>
            </div>
          </div>
        )}
      </DialogContent>
    </Dialog>
  )
}
