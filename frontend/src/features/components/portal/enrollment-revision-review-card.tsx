"use client"

import { useMemo, useState } from "react"
import { CalendarDays, ListIcon } from "lucide-react"

import {
  DataTable,
  type DataTableColumn,
} from "@/features/components/portal/data-table"
import { EnrollmentRevisionHistory } from "@/features/components/portal/enrollment-revision-history"
import { StudentProposeSubjectsDialog } from "@/features/components/portal/student-propose-subjects-dialog"
import {
  SectionScheduleCalendar,
  type SectionScheduleItem,
} from "@/features/components/portal/section-schedule-calendar"
import { Alert, AlertDescription } from "@/features/components/ui/alert"
import {
  AlertDialog,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/features/components/ui/alert-dialog"
import { Badge } from "@/features/components/ui/badge"
import { Button } from "@/features/components/ui/button"
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/features/components/ui/card"
import { Field, FieldError, FieldLabel } from "@/features/components/ui/field"
import { Textarea } from "@/features/components/ui/textarea"
import {
  ToggleGroup,
  ToggleGroupItem,
} from "@/features/components/ui/toggle-group"
import { useUpdateEnrollmentMutation } from "@/features/hooks/use-enrollment"
import { formatTimeRange } from "@/features/lib/format-time"
import { compareBySchedule } from "@/features/lib/schedule-order"
import type { Enrollment } from "@/features/schemas/enrollment-schema"
import { isApiClientError } from "@/features/services/api-client"

/**
 * The student's answer to the Program Chair's changes to their subjects
 * (ADR 0040). Shown while the enrollment is `pending_student_review`: the
 * Chair's reason and what changed, the resulting revised schedule in table or
 * calendar view, then two choices. Accepting sends the enrollment straight to
 * the Registrar. Not accepting needs a reason and sends it back to the Program
 * Chair, who sees that reason.
 */
export function EnrollmentRevisionReviewCard({
  enrollment,
}: {
  enrollment: Enrollment
}) {
  const [declining, setDeclining] = useState(false)
  const [proposing, setProposing] = useState(false)
  const [reason, setReason] = useState("")
  const [error, setError] = useState("")
  const [scheduleView, setScheduleView] = useState<"table" | "calendar">(
    "table",
  )
  const mutation = useUpdateEnrollmentMutation()

  const activeSubjects = useMemo(() => {
    return (enrollment.subjects ?? []).filter((s) => s.status !== "dropped")
  }, [enrollment.subjects])

  const sortedSubjects = useMemo(() => {
    return [...activeSubjects].sort((a, b) =>
      compareBySchedule(
        {
          schedule_days: a.schedule_days ?? null,
          starts_at_time: a.starts_at_time ?? null,
          ends_at_time: a.ends_at_time ?? null,
        },
        {
          schedule_days: b.schedule_days ?? null,
          starts_at_time: b.starts_at_time ?? null,
          ends_at_time: b.ends_at_time ?? null,
        },
      ),
    )
  }, [activeSubjects])

  const calendarItems: SectionScheduleItem[] = useMemo(() => {
    return activeSubjects.map((subj) => ({
      id: subj.section_id,
      subject_code: subj.subject_code,
      subject_title: subj.subject_title,
      units: subj.units ?? null,
      section_code: subj.section_code ?? null,
      room: subj.room ?? null,
      professor_name: subj.professor_name ?? null,
      schedule_days: subj.schedule_days ?? null,
      starts_at_time: subj.starts_at_time ?? null,
      ends_at_time: subj.ends_at_time ?? null,
      modality: subj.modality ?? null,
    }))
  }, [activeSubjects])

  const scheduleColumns: DataTableColumn<Enrollment["subjects"][number]>[] = [
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
    {
      key: "units",
      header: "Units",
      render: (row) => row.units ?? "—",
    },
    {
      key: "section",
      header: "Section",
      render: (row) => row.section_code ?? `Section #${row.section_id}`,
    },
    {
      key: "day",
      header: "Day",
      render: (row) => row.schedule_days ?? "Not assigned",
    },
    {
      key: "time",
      header: "Time",
      render: (row) =>
        row.starts_at_time && row.ends_at_time
          ? formatTimeRange(row.starts_at_time, row.ends_at_time)
          : "Not assigned",
    },
    {
      key: "room",
      header: "Room",
      render: (row) => row.room ?? "Not assigned",
    },
    {
      key: "professor",
      header: "Professor",
      render: (row) => row.professor_name ?? "To be announced",
    },
  ]

  if (enrollment.status !== "pending_student_review") return null

  const reasonMissing = reason.trim() === ""

  async function answer(
    action: "student_accept_revision" | "student_decline_revision",
  ) {
    setError("")
    try {
      await mutation.mutateAsync({
        id: enrollment.id,
        action,
        reason:
          action === "student_decline_revision" ? reason.trim() : undefined,
      })
      setDeclining(false)
      setReason("")
    } catch (caught) {
      setError(
        isApiClientError(caught) && caught.message
          ? caught.message
          : "Your answer could not be saved. Check the connection and try again.",
      )
    }
  }

  return (
    <>
      <Card
        role="region"
        aria-label="Review your Program Chair's changes"
        className="border-amber-500/50"
      >
        <CardHeader>
          <div className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
            <div>
              <CardTitle level={2}>
                Your Program Chair changed your subjects
              </CardTitle>
              <CardDescription>
                Read why, review your revised schedule below, then accept the
                changes or tell your Program Chair why you cannot. If you
                accept, your enrollment goes straight to the Registrar for
                approval.
              </CardDescription>
            </div>
            <Badge
              variant="outline"
              className="border-amber-500/60 text-amber-700 dark:text-amber-400 shrink-0 w-fit"
            >
              Waiting for your answer
            </Badge>
          </div>
        </CardHeader>
        <CardContent className="grid gap-6">
          {/* Changes history breakdown */}
          <EnrollmentRevisionHistory
            revisions={enrollment.revisions}
            audience="student"
          />

          {/* Revised schedule: table or calendar view */}
          {activeSubjects.length > 0 && (
            <section
              aria-label="Revised subject schedule"
              className="grid gap-3 pt-3 border-t"
            >
              <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div>
                  <h3 className="text-sm font-semibold text-foreground">
                    Your revised schedule
                  </h3>
                  <p className="text-xs text-muted-foreground">
                    {activeSubjects.length} subjects · {enrollment.total_units}{" "}
                    units total
                  </p>
                </div>
                <ToggleGroup
                  type="single"
                  value={scheduleView}
                  onValueChange={(val) => {
                    if (val === "table" || val === "calendar")
                      setScheduleView(val)
                  }}
                  variant="outline"
                  size="sm"
                  aria-label="Revised schedule view"
                >
                  <ToggleGroupItem value="table" aria-label="Table view">
                    <ListIcon data-icon="inline-start" aria-hidden="true" />
                    Table view
                  </ToggleGroupItem>
                  <ToggleGroupItem
                    value="calendar"
                    aria-label="Calendar timetable"
                  >
                    <CalendarDays data-icon="inline-start" aria-hidden="true" />
                    Calendar timetable
                  </ToggleGroupItem>
                </ToggleGroup>
              </div>

              {scheduleView === "table" ? (
                <DataTable
                  caption="Revised enrollment schedule"
                  columns={scheduleColumns}
                  rowKey={(row) => row.section_id}
                  rows={sortedSubjects}
                />
              ) : (
                <div className="rounded-lg border bg-card p-2">
                  <SectionScheduleCalendar
                    items={calendarItems}
                    disabled={true}
                    emptyMessage="No timetable slots found for revised subjects."
                  />
                </div>
              )}
            </section>
          )}

          {error && (
            <Alert variant="destructive">
              <AlertDescription>{error}</AlertDescription>
            </Alert>
          )}

          <div className="flex flex-wrap gap-2 pt-2 border-t">
            <Button
              type="button"
              disabled={mutation.isPending}
              onClick={() => void answer("student_accept_revision")}
            >
              {mutation.isPending && !declining
                ? "Accepting…"
                : "Accept the changes"}
            </Button>
            {enrollment.is_irregular && (
              <Button
                type="button"
                variant="secondary"
                disabled={mutation.isPending}
                onClick={() => setProposing(true)}
              >
                Propose different subjects
              </Button>
            )}
            <Button
              type="button"
              variant="outline"
              disabled={mutation.isPending}
              onClick={() => {
                setError("")
                setDeclining(true)
              }}
            >
              I do not accept
            </Button>
          </div>
        </CardContent>
      </Card>

      {proposing && (
        <StudentProposeSubjectsDialog
          enrollment={enrollment}
          open={proposing}
          onOpenChange={setProposing}
        />
      )}

      <AlertDialog
        open={declining}
        onOpenChange={(next) => {
          if (!mutation.isPending) setDeclining(next)
        }}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>
              Why can you not accept the changes?
            </AlertDialogTitle>
            <AlertDialogDescription>
              Your enrollment goes back to your Program Chair together with your
              reason, so they can change it again or talk to you.
            </AlertDialogDescription>
          </AlertDialogHeader>
          {enrollment.is_irregular && (
            <div className="rounded-md border bg-muted/40 p-2.5 text-xs text-muted-foreground flex items-center justify-between gap-2">
              <span>
                Want to choose your own subjects instead of declining with a
                note?
              </span>
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="h-7 text-xs font-medium shrink-0"
                onClick={() => {
                  setDeclining(false)
                  setProposing(true)
                }}
              >
                Propose subjects
              </Button>
            </div>
          )}
          <Field data-invalid={reasonMissing && reason !== ""}>
            <FieldLabel htmlFor="student-decline-reason">
              Your reason
            </FieldLabel>
            <Textarea
              id="student-decline-reason"
              value={reason}
              onChange={(event) => setReason(event.target.value)}
              placeholder="e.g. CS102 meets on Tuesdays and I work on Tuesdays."
              disabled={mutation.isPending}
              maxLength={2000}
              required
            />
            {reasonMissing && reason !== "" && (
              <FieldError>A reason is required.</FieldError>
            )}
          </Field>
          {error && (
            <Alert variant="destructive">
              <AlertDescription>{error}</AlertDescription>
            </Alert>
          )}
          <AlertDialogFooter>
            <AlertDialogCancel disabled={mutation.isPending}>
              Back
            </AlertDialogCancel>
            <Button
              type="button"
              variant="destructive"
              disabled={mutation.isPending || reasonMissing}
              onClick={() => void answer("student_decline_revision")}
            >
              {mutation.isPending ? "Sending…" : "Send to my Program Chair"}
            </Button>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  )
}
