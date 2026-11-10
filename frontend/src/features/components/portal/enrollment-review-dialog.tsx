"use client"

import { useMemo, useState } from "react"

import {
  CalendarDays,
  CheckCircle2,
  ListIcon,
  Plus,
  Trash2,
  TriangleAlert,
} from "lucide-react"

import {
  DataTable,
  type DataTableColumn,
} from "@/features/components/portal/data-table"
import { EnrollmentRevisionHistory } from "@/features/components/portal/enrollment-revision-history"
import { ProspectusDocument } from "@/features/components/portal/prospectus-document"
import {
  SectionScheduleCalendar,
  type SectionScheduleItem,
} from "@/features/components/portal/section-schedule-calendar"
import { Alert, AlertDescription } from "@/features/components/ui/alert"
import { Badge } from "@/features/components/ui/badge"
import { Button } from "@/features/components/ui/button"
import { Checkbox } from "@/features/components/ui/checkbox"
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
import { useReviseEnrollmentSubjectsMutation } from "@/features/hooks/use-enrollment"
import {
  useSectionsQuery,
  useSubjectsQuery,
} from "@/features/hooks/use-reference-data"
import { formatYearLevelOrdinal } from "@/features/lib/curriculum-ordinal"
import { formatClockTime, formatTimeRange } from "@/features/lib/format-time"
import { findConflictingIds } from "@/features/lib/room-calendar"
import { isApiClientError } from "@/features/services/api-client"
import type { Enrollment } from "@/features/schemas/enrollment-schema"

interface EnrollmentReviewRow {
  section_id: number
  subject_code: string
  subject_title: string
  section_code: string | null
  units: number | null
  schedule_days: string | null
  starts_at_time: string | null
  ends_at_time: string | null
  room: string | null
}

function displayTimeRange(
  startsAt: string | null,
  endsAt: string | null,
): string {
  if (!startsAt || !endsAt) return "Not assigned"

  return formatTimeRange(startsAt, endsAt)
}

function scheduleColumns(options?: {
  onRemove: (sectionId: number) => void
  canRemove: boolean
}): DataTableColumn<EnrollmentReviewRow>[] {
  return [
    {
      key: "subject-code",
      header: "Subject code",
      render: (row) => row.subject_code,
    },
    {
      key: "description",
      header: "Description",
      render: (row) => row.subject_title,
    },
    { key: "units", header: "Units", render: (row) => row.units ?? "—" },
    {
      key: "section-id",
      header: "Section ID",
      render: (row) => row.section_id,
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
    ...(options
      ? [
          {
            key: "revise-action",
            header: "",
            render: (row: EnrollmentReviewRow) => (
              <Button
                type="button"
                variant="ghost"
                size="icon-sm"
                disabled={!options.canRemove}
                onClick={() => options.onRemove(row.section_id)}
                className="text-destructive hover:bg-destructive/10 hover:text-destructive"
              >
                <Trash2 className="size-4" />
                <span className="sr-only">Remove {row.subject_code}</span>
              </Button>
            ),
          } satisfies DataTableColumn<EnrollmentReviewRow>,
        ]
      : []),
  ]
}

/**
 * Lets Registrar Staff see exactly which subjects and sections a student
 * selected — with each section's schedule and unit count — before deciding
 * on the enrollment. Sections/subjects are reference data every role may
 * already read (`SectionPolicy`/`SubjectPolicy` `viewAny`); this joins them
 * client-side against the enrollment's own `subjects[].section_id`, the
 * same pattern `MasterScheduleWorkspace` already uses for published
 * sections.
 */
export function EnrollmentReviewDialog({
  enrollment,
  onOpenChange,
  editable = false,
  onRevised,
}: {
  enrollment: Enrollment | null
  onOpenChange: (open: boolean) => void
  /** Program Head only: lets the reviewer add or remove whole subjects
      before deciding on the enrollment. */
  editable?: boolean
  /** Called with the freshly revised enrollment once a subject change saves. */
  onRevised?: (enrollment: Enrollment) => void
}) {
  const [prospectusOpen, setProspectusOpen] = useState(false)
  const sectionsQuery = useSectionsQuery({ enabled: enrollment !== null })
  const subjectsQuery = useSubjectsQuery({ enabled: enrollment !== null })
  const isLoading = sectionsQuery.isPending || subjectsQuery.isPending

  const originalSectionIds = useMemo(
    () =>
      (enrollment?.subjects ?? [])
        .filter((s) => s.status !== "dropped")
        .map((s) => s.section_id),
    [enrollment],
  )
  // Adjusted during render (not an effect) when a different enrollment opens
  // — the same pattern `FeeSettingsWorkspace` uses to (re)seed local edit
  // state from freshly-arrived data.
  const [pendingSectionIds, setPendingSectionIds] = useState<number[] | null>(
    null,
  )
  const [initializedFor, setInitializedFor] = useState<number | null>(null)
  const [revisionNote, setRevisionNote] = useState("")
  const [overloadAcknowledged, setOverloadAcknowledged] = useState(false)
  if (enrollment && initializedFor !== enrollment.id) {
    setInitializedFor(enrollment.id)
    setPendingSectionIds(null)
    setRevisionNote("")
    setOverloadAcknowledged(false)
  }
  const currentSectionIds = pendingSectionIds ?? originalSectionIds

  const reviseMutation = useReviseEnrollmentSubjectsMutation()
  const [reviseError, setReviseError] = useState<string | null>(null)
  const [overloadRequired, setOverloadRequired] = useState(false)

  const rows: EnrollmentReviewRow[] = useMemo(() => {
    if (!enrollment) return []
    const sections = sectionsQuery.data ?? []
    const subjects = subjectsQuery.data ?? []

    return currentSectionIds.map((sectionId) => {
      const section = sections.find((item) => item.id === sectionId)
      const subject = section
        ? subjects.find((item) => item.id === section.subject_id)
        : undefined
      const original = enrollment.subjects.find(
        (s) => s.section_id === sectionId,
      )

      return {
        section_id: sectionId,
        subject_code:
          subject?.code ?? original?.subject_code ?? `#${sectionId}`,
        subject_title: subject?.title ?? original?.subject_title ?? "—",
        section_code: section?.section_code ?? original?.section_code ?? null,
        units: subject?.units ?? original?.units ?? null,
        schedule_days:
          section?.schedule_days ?? original?.schedule_days ?? null,
        starts_at_time:
          section?.starts_at_time ?? original?.starts_at_time ?? null,
        ends_at_time: section?.ends_at_time ?? original?.ends_at_time ?? null,
        room: section?.room ?? original?.room ?? null,
      }
    })
  }, [enrollment, sectionsQuery.data, subjectsQuery.data, currentSectionIds])

  const addableOptions = useMemo(() => {
    if (!enrollment) return []
    const subjects = subjectsQuery.data ?? []

    return (sectionsQuery.data ?? [])
      .filter((s) => s.academic_term_id === enrollment.academic_term_id)
      .filter((s) => !currentSectionIds.includes(s.id))
      .filter((s) => s.remaining_seats > 0)
      .map((s) => {
        const subject = subjects.find((subj) => subj.id === s.subject_id)
        const schedule =
          s.schedule_days && s.starts_at_time && s.ends_at_time
            ? ` · ${s.schedule_days} ${formatTimeRange(s.starts_at_time, s.ends_at_time)}`
            : s.schedule_days && s.starts_at_time
              ? ` · ${s.schedule_days} ${formatClockTime(s.starts_at_time)}`
              : ""

        return {
          value: String(s.id),
          label: `${subject?.code ?? "Subject"} — Section ${s.section_code}${schedule}`,
        }
      })
  }, [enrollment, sectionsQuery.data, subjectsQuery.data, currentSectionIds])

  const hasPendingChanges =
    pendingSectionIds !== null &&
    JSON.stringify([...pendingSectionIds].sort((a, b) => a - b)) !==
      JSON.stringify([...originalSectionIds].sort((a, b) => a - b))

  function handleAddSection(value: string) {
    const sectionId = Number(value)
    if (!Number.isInteger(sectionId)) return
    setPendingSectionIds([...currentSectionIds, sectionId])
    setReviseError(null)
  }

  function handleRemoveSection(sectionId: number) {
    if (currentSectionIds.length <= 1) return
    setPendingSectionIds(currentSectionIds.filter((id) => id !== sectionId))
    setReviseError(null)
  }

  // A load that needs overload approval is acknowledged here, because sending
  // the changes to the student replaces the Approve step that used to ask.
  const showOverloadAcknowledgement =
    Boolean(enrollment?.requires_overload_approval) || overloadRequired

  function handleSaveRevision() {
    if (!enrollment) return
    if (revisionNote.trim() === "") {
      setReviseError("Tell the student why you are changing their subjects.")
      return
    }
    setReviseError(null)
    reviseMutation.mutate(
      {
        id: enrollment.id,
        sectionIds: currentSectionIds,
        note: revisionNote.trim(),
        overloadAcknowledged,
      },
      {
        onSuccess: (updated) => {
          setPendingSectionIds(null)
          setRevisionNote("")
          setOverloadAcknowledged(false)
          setOverloadRequired(false)
          onRevised?.(updated)
        },
        onError: (err: unknown) => {
          if (isApiClientError(err) && err.fieldErrors?.overload_acknowledged) {
            setOverloadRequired(true)
          }
          const fieldErrors = isApiClientError(err)
            ? Object.values(err.fieldErrors ?? {}).flat()
            : []
          setReviseError(
            fieldErrors[0] ??
              (err instanceof Error
                ? err.message
                : "The revision could not be saved. Try again."),
          )
        },
      },
    )
  }

  const [view, setView] = useState<"table" | "calendar">("table")
  const totalUnits = rows.reduce((sum, row) => sum + (row.units ?? 0), 0)
  const regularMax = enrollment?.regular_unit_maximum ?? null
  const overloadMax = enrollment?.unit_maximum ?? null
  const isOverload =
    (regularMax !== null && totalUnits > regularMax) ||
    Boolean(enrollment?.requires_overload_approval) ||
    overloadRequired
  const isBeyondHardCap = overloadMax !== null && totalUnits > overloadMax
  const diffUnits =
    regularMax === null ? 0 : Math.round((totalUnits - regularMax) * 10) / 10

  const calendarItems: SectionScheduleItem[] = useMemo(() => {
    return rows.map((row) => ({
      id: row.section_id,
      subject_code: row.subject_code,
      subject_title: row.subject_title,
      units: row.units,
      section_code: row.section_code ?? `Section #${row.section_id}`,
      room: row.room,
      professor_name: null,
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

  return (
    <>
      <Dialog
        open={enrollment !== null}
        onOpenChange={(open) => {
          if (!open) {
            setPendingSectionIds(null)
            setReviseError(null)
            setRevisionNote("")
            setOverloadAcknowledged(false)
            setOverloadRequired(false)
          }
          onOpenChange(open)
        }}
      >
        <DialogContent className="max-h-[85dvh] w-[calc(100vw-2rem)] overflow-y-auto sm:max-w-6xl">
          <DialogHeader className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <DialogTitle>
                Review enrollment{enrollment ? ` #${enrollment.id}` : ""}
              </DialogTitle>
              <DialogDescription>
                {enrollment ? `${totalUnits} total units` : ""}
              </DialogDescription>
            </div>
            <div className="flex flex-wrap items-center gap-2">
              {enrollment?.student_id && (
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  onClick={() => setProspectusOpen(true)}
                >
                  View Prospectus
                </Button>
              )}
            </div>
          </DialogHeader>

          {/* Normalized Student Overview & Pre-Flight Checks */}
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div className="grid gap-1 rounded-lg border bg-muted/20 p-3 text-sm">
              <span className="text-xs text-muted-foreground">Student</span>
              <span className="font-semibold text-foreground truncate">
                {enrollment?.student_name ?? "—"}
              </span>
              <div className="flex items-center gap-1 text-xs text-muted-foreground">
                <span>{enrollment?.student_number ?? "—"}</span>
                <span>·</span>
                <span>
                  {formatYearLevelOrdinal(enrollment?.student_year_level)}
                </span>
              </div>
            </div>

            <div className="grid gap-1 rounded-lg border bg-muted/20 p-3 text-sm">
              <span className="text-xs text-muted-foreground">
                Academic Load
              </span>
              <div className="flex items-center gap-1.5">
                <span className="text-base font-bold text-foreground">
                  {rows.length}
                </span>
                <span className="text-xs text-muted-foreground">subjects</span>
                <span className="text-muted-foreground/60">·</span>
                <span className="text-base font-bold text-foreground">
                  {totalUnits}
                </span>
                <span className="text-xs text-muted-foreground">units</span>
              </div>
              <span className="text-xs text-muted-foreground">
                {regularMax === null ? (
                  "Regular unit limit not configured"
                ) : (
                  <>
                    Allowed load:{" "}
                    <strong className="font-semibold text-foreground">
                      {regularMax} units regular
                    </strong>
                  </>
                )}
                {overloadMax ? ` (max ${overloadMax})` : ""}
              </span>
            </div>

            <div className="grid gap-1 rounded-lg border bg-muted/20 p-3 text-sm">
              <span className="text-xs text-muted-foreground">
                Unit Overload Status
              </span>
              <div className="flex items-center gap-1.5 mt-0.5">
                {isBeyondHardCap ? (
                  <Badge
                    variant="destructive"
                    className="font-semibold text-xs"
                  >
                    Exceeds Limit ({totalUnits} / {overloadMax})
                  </Badge>
                ) : isOverload ? (
                  <Badge
                    variant="destructive"
                    className="font-semibold text-xs"
                  >
                    Overload (
                    {diffUnits > 0
                      ? `+${diffUnits} units`
                      : "Requires Sign-off"}
                    )
                  </Badge>
                ) : regularMax === null ? (
                  <Badge variant="outline">Regular limit unavailable</Badge>
                ) : (
                  <Badge
                    variant="secondary"
                    className="border-emerald-500/30 bg-emerald-50 text-emerald-700 dark:bg-emerald-950/20 dark:text-emerald-400 font-semibold text-xs"
                  >
                    Within Regular Load ({totalUnits} / {regularMax})
                  </Badge>
                )}
              </div>
              <span className="text-[11px] text-muted-foreground">
                {isBeyondHardCap
                  ? `Exceeds the ${overloadMax} unit maximum cap for this program`
                  : regularMax === null
                    ? "Review the configured load policy before deciding"
                    : isOverload
                      ? `Exceeds regular load of ${regularMax} units (max ${overloadMax ?? regularMax})`
                      : `Allowed up to ${regularMax} regular units without overload`}
              </span>
            </div>

            <div className="grid gap-1 rounded-lg border bg-muted/20 p-3 text-sm">
              <span className="text-xs text-muted-foreground">
                Timetable Conflict Check
              </span>
              <div className="flex items-center gap-1.5 mt-0.5">
                {conflictingIds.size > 0 ? (
                  <Badge
                    variant="destructive"
                    className="flex items-center gap-1 text-xs font-semibold"
                  >
                    <TriangleAlert className="size-3.5" aria-hidden="true" />
                    {conflictingIds.size} Conflict(s) Detected
                  </Badge>
                ) : (
                  <Badge
                    variant="secondary"
                    className="flex items-center gap-1 text-xs font-semibold border-emerald-500/30 bg-emerald-50 text-emerald-700 dark:bg-emerald-950/20 dark:text-emerald-400"
                  >
                    <CheckCircle2 className="size-3.5" aria-hidden="true" />
                    Zero Schedule Conflicts
                  </Badge>
                )}
              </div>
              <span className="text-[11px] text-muted-foreground">
                {conflictingIds.size > 0
                  ? "Requires section re-selection"
                  : "All days & times compatible"}
              </span>
            </div>
          </div>

          {enrollment?.status === "pending_student_review" && (
            <Alert>
              <AlertDescription>
                Waiting for the student to accept or decline your changes. You
                can decide on this enrollment once they answer.
              </AlertDescription>
            </Alert>
          )}

          {enrollment && enrollment.revisions.length > 0 && (
            <EnrollmentRevisionHistory
              revisions={enrollment.revisions}
              audience="staff"
            />
          )}

          {editable && (
            <div className="grid gap-3 rounded-lg border bg-muted/10 p-3">
              {reviseError && (
                <Alert variant="destructive">
                  <AlertDescription>{reviseError}</AlertDescription>
                </Alert>
              )}
              <p className="text-xs text-muted-foreground">
                If you change the subjects, they go back to the student to
                accept first. Once the student accepts, the enrollment goes
                straight to the Registrar.
              </p>
              <div className="flex flex-wrap items-end gap-2">
                <div className="min-w-[16rem] flex-1">
                  <SearchableCombobox
                    id="add-subject-picker"
                    label="Add a subject"
                    options={addableOptions}
                    value=""
                    onValueChange={handleAddSection}
                    placeholder="Search a subject or section to add…"
                    emptyMessage="No other open sections in this term."
                  />
                </div>
              </div>
              {hasPendingChanges && (
                <div className="grid gap-3">
                  <div className="grid gap-1.5">
                    <label
                      htmlFor="revision-note"
                      className="text-sm font-medium"
                    >
                      Why are you changing these subjects?
                    </label>
                    <Textarea
                      id="revision-note"
                      value={revisionNote}
                      onChange={(event) => setRevisionNote(event.target.value)}
                      placeholder="The student reads this before accepting, e.g. CS101 clashes with another class, so CS102 replaces it."
                      maxLength={2000}
                      disabled={reviseMutation.isPending}
                    />
                  </div>
                  {showOverloadAcknowledgement && (
                    <div className="flex items-start gap-2 text-sm">
                      <Checkbox
                        id="revision-overload-ack"
                        checked={overloadAcknowledged}
                        onCheckedChange={(checked) =>
                          setOverloadAcknowledged(checked === true)
                        }
                        disabled={reviseMutation.isPending}
                      />
                      <label htmlFor="revision-overload-ack">
                        I acknowledge this schedule exceeds the regular unit
                        load.
                      </label>
                    </div>
                  )}
                  <div className="flex flex-wrap items-center gap-2">
                    <Button
                      type="button"
                      variant="outline"
                      disabled={reviseMutation.isPending}
                      onClick={() => {
                        setPendingSectionIds(null)
                        setReviseError(null)
                        setRevisionNote("")
                      }}
                    >
                      Discard changes
                    </Button>
                    <Button
                      type="button"
                      disabled={reviseMutation.isPending}
                      onClick={handleSaveRevision}
                      className="gap-1.5"
                    >
                      <Plus className="size-4" aria-hidden="true" />
                      {reviseMutation.isPending
                        ? "Sending to the student…"
                        : "Send changes to the student"}
                    </Button>
                  </div>
                </div>
              )}
            </div>
          )}

          {/* View Switcher Controls */}
          <div className="flex items-center justify-between border-b pb-2 pt-1">
            <h3 className="text-sm font-semibold text-foreground">
              Selected Subject Schedule
            </h3>
            <ToggleGroup
              type="single"
              value={view}
              onValueChange={(val) => {
                if (val === "table" || val === "calendar") setView(val)
              }}
              variant="outline"
              size="sm"
              aria-label="Schedule layout view"
            >
              <ToggleGroupItem value="table" aria-label="Table view">
                <ListIcon data-icon="inline-start" aria-hidden="true" />
                Table view
              </ToggleGroupItem>
              <ToggleGroupItem value="calendar" aria-label="Calendar view">
                <CalendarDays data-icon="inline-start" aria-hidden="true" />
                Calendar timetable
              </ToggleGroupItem>
            </ToggleGroup>
          </div>

          {isLoading ? (
            <div
              className="grid gap-3"
              role="status"
              aria-label="Loading subjects and schedule"
            >
              <Skeleton className="h-20" />
              <Skeleton className="h-20" />
            </div>
          ) : rows.length === 0 ? (
            <p className="text-sm text-muted-foreground">
              This enrollment has no subjects on record.
            </p>
          ) : view === "table" ? (
            <>
              <DataTable
                caption={`Enrollment #${enrollment?.id ?? "—"} schedule`}
                columns={scheduleColumns(
                  editable
                    ? {
                        onRemove: handleRemoveSection,
                        canRemove: currentSectionIds.length > 1,
                      }
                    : undefined,
                )}
                rowKey={(row) => row.section_id}
                rows={rows}
              />
              <p className="text-xs text-muted-foreground text-right">
                Showing {rows.length} subjects · {totalUnits} units total
              </p>
            </>
          ) : (
            <div className="rounded-xl border bg-card p-3">
              <SectionScheduleCalendar
                items={calendarItems}
                disabled={true}
                emptyMessage="No weekly timetable slots scheduled."
              />
            </div>
          )}
        </DialogContent>
      </Dialog>
      <Dialog open={prospectusOpen} onOpenChange={setProspectusOpen}>
        <DialogContent className="max-h-[85dvh] w-[calc(100vw-2rem)] overflow-y-auto sm:max-w-5xl">
          <DialogHeader>
            <DialogTitle>
              Student Prospectus —{" "}
              {enrollment?.student_name ?? enrollment?.student_number}
            </DialogTitle>
          </DialogHeader>
          {enrollment?.student_id && (
            <ProspectusDocument studentId={enrollment.student_id} />
          )}
        </DialogContent>
      </Dialog>
    </>
  )
}
