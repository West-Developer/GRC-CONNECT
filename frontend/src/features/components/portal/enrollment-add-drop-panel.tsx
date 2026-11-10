"use client"

import { useState } from "react"

import { AccordionCard } from "@/features/components/portal/accordion-card"
import { AsyncBoundary } from "@/features/components/portal/async-boundary"
import { DataTable } from "@/features/components/portal/data-table"
import {
  AlertDialog,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/features/components/ui/alert-dialog"
import { Alert, AlertDescription } from "@/features/components/ui/alert"
import { Badge } from "@/features/components/ui/badge"
import { Button } from "@/features/components/ui/button"
import { Field, FieldLabel } from "@/features/components/ui/field"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/features/components/ui/select"
import { SearchableCombobox } from "@/features/components/ui/searchable-combobox"
import { Textarea } from "@/features/components/ui/textarea"
import { useProspectusQuery } from "@/features/hooks/use-academic-record"
import {
  useCreateEnrollmentChangeRequestMutation,
  useEnrollmentChangeRequestsQuery,
} from "@/features/hooks/use-enrollment-change-requests"
import {
  useSectionsQuery,
  useSubjectsQuery,
} from "@/features/hooks/use-reference-data"
import { markTone } from "@/features/lib/grade-presentation"
import type { Enrollment } from "@/features/schemas/enrollment-schema"
import { isApiClientError } from "@/features/services/api-client"

function firstApiErrorMessage(error: unknown): string | undefined {
  if (!isApiClientError(error)) return undefined
  return Object.values(error.fieldErrors ?? {})[0]?.[0]
}

/** The bits of a section every section picker in this panel needs. */
interface SectionChoice {
  id: number
  section_code: string
  remaining_seats: number
}

type PendingDecision =
  | { kind: "drop"; sectionId: number; subjectLabel: string }
  | {
      kind: "change_section"
      fromSectionId: number
      subjectId: number
      subjectLabel: string
    }

function statusBadgeVariant(
  status: "pending" | "approved" | "rejected",
): "default" | "destructive" | "outline" {
  if (status === "approved") return "default"
  if (status === "rejected") return "destructive"
  return "outline"
}

/**
 * The Add/Drop stage of the enrollment process, embedded directly in
 * `EnrollmentWorkspace` once a student's enrollment reaches `enrolled` — no
 * longer a standalone portal module; it only ever appears as a stage of the
 * one enrollment flow, and only opens once `windowOpen` is true.
 */
export function EnrollmentAddDropPanel({
  enrollment,
  windowOpen,
  windowMessage,
  windowClosesAt,
  defaultOpen = true,
}: {
  enrollment: Enrollment
  windowOpen: boolean
  windowMessage: string
  windowClosesAt: string | null
  defaultOpen?: boolean
}) {
  const subjectsQuery = useSubjectsQuery({ enabled: windowOpen })
  const sectionsQuery = useSectionsQuery({ enabled: windowOpen })
  // The picker's source of truth is the student's own prospectus (their curriculum), the same
  // list they see under Grades. TanStack dedupes it with the Grades page's fetch.
  const prospectusQuery = useProspectusQuery(undefined, { enabled: windowOpen })
  const requestsQuery = useEnrollmentChangeRequestsQuery(
    { page: 1, per_page: 20 },
    { enabled: windowOpen },
  )
  const createMutation = useCreateEnrollmentChangeRequestMutation()

  const [pending, setPending] = useState<PendingDecision | null>(null)
  const [reason, setReason] = useState("")
  const [toSectionId, setToSectionId] = useState<number | null>(null)
  const [changeSubjectId, setChangeSubjectId] = useState<number | null>(null)
  const [error, setError] = useState("")

  const [addSubjectId, setAddSubjectId] = useState<number | null>(null)
  const [addSectionId, setAddSectionId] = useState<number | null>(null)
  const [addReason, setAddReason] = useState("")
  const [addError, setAddError] = useState("")

  const sections = sectionsQuery.data ?? []
  const heldSubjectCodes = new Set(
    enrollment.subjects
      .filter((subject) => subject.status !== "dropped")
      .map((subject) => subject.subject_code),
  )
  // Searchable candidates: every subject of the student's prospectus they have not passed yet and do
  // not already hold on this enrollment. The server accepts any subject of the student's own
  // curriculum that has a published, conflict-free section with a seat in this term (it does not
  // re-run the prerequisite pool), so the picker no longer hides subjects behind that stricter pool.
  const termSections = sections.filter(
    (section) =>
      section.academic_term_id === enrollment.academic_term_id &&
      section.status === "published" &&
      section.remaining_seats > 0,
  )
  const prospectusSubjects = new Map<
    number,
    { subject_id: number; code: string; title: string }
  >()
  for (const semester of prospectusQuery.data?.semesters ?? []) {
    for (const entry of semester.entries) {
      if (
        markTone(entry.mark) !== "passed" &&
        !heldSubjectCodes.has(entry.code) &&
        !prospectusSubjects.has(entry.subject_id)
      ) {
        prospectusSubjects.set(entry.subject_id, entry)
      }
    }
  }
  const remainingProspectusSubjects = [...prospectusSubjects.values()].map(
    (entry) => ({
      subject_id: entry.subject_id,
      code: entry.code,
      title: entry.title,
      available_sections: termSections.filter(
        (section) => section.subject_id === entry.subject_id,
      ),
    }),
  )
  const addableEntries = remainingProspectusSubjects.filter(
    (entry) => entry.available_sections.length > 0,
  )
  // Said out loud under the picker so a missing subject is explained, not mysterious.
  const withoutOpenSectionCount =
    remainingProspectusSubjects.length - addableEntries.length
  const addSubjectOptions = addableEntries.map((entry) => ({
    value: String(entry.subject_id),
    label: `${entry.code} — ${entry.title}`,
  }))
  const sectionsForAddSubject: readonly SectionChoice[] =
    addableEntries.find((entry) => entry.subject_id === addSubjectId)
      ?.available_sections ?? []
  // `changeSubjectId` starts as the held subject when Change subject is opened
  // and becomes null when the student clears the search box. There is
  // deliberately no fallback to the held subject here: falling back made the
  // combobox re-fill its own label the moment it was cleared, so nothing could
  // ever be searched.
  const effectiveChangeSubjectId =
    pending?.kind === "change_section" ? changeSubjectId : null

  const subjectsLoading = prospectusQuery.isPending || sectionsQuery.isPending
  const subjectsFailed = prospectusQuery.isError || sectionsQuery.isError
  const subjectPickerEmptyMessage = subjectsLoading
    ? "Loading your prospectus subjects…"
    : subjectsFailed
      ? "Your prospectus subjects could not be loaded."
      : addableEntries.length === 0
        ? "None of your remaining prospectus subjects has an open section this term."
        : "No subject in your prospectus matches that search."

  let changeSubjectOptions: { value: string; label: string }[] = []
  let sectionsForChange: readonly SectionChoice[] = []
  if (pending?.kind === "change_section") {
    const currentSubject = (subjectsQuery.data ?? []).find(
      (s) => s.id === pending.subjectId,
    )
    changeSubjectOptions = [
      {
        value: String(pending.subjectId),
        label: currentSubject
          ? `${currentSubject.code} — ${currentSubject.title} (Same subject / change section)`
          : "Current subject",
      },
      ...addSubjectOptions.filter(
        (option) => option.value !== String(pending.subjectId),
      ),
    ]

    if (effectiveChangeSubjectId === pending.subjectId) {
      // Same subject, different section: any other published section with seats.
      sectionsForChange = sections.filter(
        (section) =>
          section.subject_id === pending.subjectId &&
          section.id !== pending.fromSectionId &&
          section.status === "published" &&
          section.remaining_seats > 0,
      )
    } else if (effectiveChangeSubjectId !== null) {
      // A different subject: only from the student's own curriculum.
      sectionsForChange =
        addableEntries.find(
          (entry) => entry.subject_id === effectiveChangeSubjectId,
        )?.available_sections ?? []
    }
  }

  const reasonRequired = pending !== null && !reason.trim()
  const sectionRequired =
    pending?.kind === "change_section" && toSectionId === null

  const confirmPending = async () => {
    if (!pending) return
    if (reasonRequired || sectionRequired) return
    setError("")
    try {
      await createMutation.mutateAsync({
        enrollmentId: enrollment.id,
        input:
          pending.kind === "drop"
            ? {
                type: "drop",
                from_section_id: pending.sectionId,
                reason: reason.trim(),
              }
            : {
                type: "change_section",
                from_section_id: pending.fromSectionId,
                to_section_id: toSectionId!,
                reason: reason.trim(),
              },
      })
      setPending(null)
      setReason("")
      setToSectionId(null)
    } catch (submitError) {
      setError(
        firstApiErrorMessage(submitError) ??
          "The request could not be submitted. Check the connection and try again.",
      )
    }
  }

  const submitAdd = async () => {
    setAddError("")
    if (addSectionId === null || !addReason.trim()) {
      setAddError("Choose a subject, a section, and state a reason.")
      return
    }
    try {
      await createMutation.mutateAsync({
        enrollmentId: enrollment.id,
        input: {
          type: "add",
          to_section_id: addSectionId,
          reason: addReason.trim(),
        },
      })
      setAddSubjectId(null)
      setAddSectionId(null)
      setAddReason("")
    } catch (submitError) {
      setAddError(
        firstApiErrorMessage(submitError) ??
          "The request could not be submitted. Check the connection and try again.",
      )
    }
  }

  if (!windowOpen) {
    return (
      <AccordionCard
        id="add-drop-requests"
        title="Add/Drop requests"
        defaultOpen={defaultOpen}
      >
        <Alert>
          <AlertDescription>{windowMessage}</AlertDescription>
        </Alert>
      </AccordionCard>
    )
  }

  return (
    <>
      {windowClosesAt && (
        <Alert>
          <AlertDescription>
            The add/drop window closes{" "}
            {new Date(windowClosesAt).toLocaleString()}.
          </AlertDescription>
        </Alert>
      )}

      {error && (
        <Alert variant="destructive">
          <AlertDescription>{error}</AlertDescription>
        </Alert>
      )}

      <AccordionCard
        id="add-drop-subjects"
        title="Your subjects"
        defaultOpen={defaultOpen}
      >
        {enrollment.subjects.filter((s) => s.status !== "dropped").length ===
        0 ? (
          <p>No active subjects on this enrollment.</p>
        ) : (
          <DataTable
            caption="Your subjects"
            rowKey={(subject) => subject.section_id}
            rows={enrollment.subjects.filter((s) => s.status !== "dropped")}
            columns={[
              {
                key: "subject",
                header: "Subject",
                render: (subject) => subject.subject_title,
              },
              {
                key: "section",
                header: "Section",
                render: (subject) =>
                  sections.find((s) => s.id === subject.section_id)
                    ?.section_code ?? `#${subject.section_id}`,
              },
              {
                key: "status",
                header: "Status",
                render: (subject) => (
                  <Badge variant="outline">{subject.status_label}</Badge>
                ),
              },
              {
                key: "actions",
                header: "Actions",
                render: (subject) => {
                  const currentSection = sections.find(
                    (s) => s.id === subject.section_id,
                  )
                  const subjectId =
                    currentSection?.subject_id ??
                    subjectsQuery.data?.find(
                      (candidate) => candidate.code === subject.subject_code,
                    )?.id
                  return (
                    <div className="flex flex-wrap gap-2">
                      <Button
                        type="button"
                        size="sm"
                        variant="destructive"
                        onClick={() => {
                          setPending({
                            kind: "drop",
                            sectionId: subject.section_id,
                            subjectLabel: subject.subject_title,
                          })
                          setReason("")
                          setToSectionId(null)
                          setError("")
                        }}
                      >
                        Drop subject
                      </Button>
                      {subjectId !== undefined && (
                        <Button
                          type="button"
                          size="sm"
                          variant="outline"
                          onClick={() => {
                            setPending({
                              kind: "change_section",
                              fromSectionId: subject.section_id,
                              subjectId,
                              subjectLabel: subject.subject_title,
                            })
                            setChangeSubjectId(subjectId)
                            setReason("")
                            setToSectionId(null)
                            setError("")
                          }}
                        >
                          Change subject
                        </Button>
                      )}
                    </div>
                  )
                },
              },
            ]}
          />
        )}
      </AccordionCard>

      <AccordionCard
        id="add-drop-add-subject"
        title="Add subject"
        defaultOpen={defaultOpen}
        contentClassName="gap-3"
      >
        {addError && (
          <Alert variant="destructive">
            <AlertDescription>{addError}</AlertDescription>
          </Alert>
        )}
        {subjectsFailed && (
          <Alert variant="destructive">
            <AlertDescription>
              The subjects in your prospectus could not be loaded. Try again in
              a moment.
            </AlertDescription>
          </Alert>
        )}
        <Field>
          <FieldLabel htmlFor="add-drop-subject">Subject</FieldLabel>
          <SearchableCombobox
            id="add-drop-subject"
            label="Subject"
            options={addSubjectOptions}
            value={addSubjectId !== null ? String(addSubjectId) : ""}
            onValueChange={(value) => {
              setAddSubjectId(Number(value) || null)
              setAddSectionId(null)
            }}
            placeholder={
              subjectsLoading
                ? "Loading your prospectus subjects…"
                : "Search or choose a subject"
            }
            emptyMessage={subjectPickerEmptyMessage}
          />
          {!subjectsLoading && !subjectsFailed && withoutOpenSectionCount > 0 && (
            <p className="text-xs text-muted-foreground">
              {withoutOpenSectionCount === 1
                ? "1 more subject from your prospectus has no open section this term."
                : `${withoutOpenSectionCount} more subjects from your prospectus have no open section this term.`}
            </p>
          )}
        </Field>
        {addSubjectId !== null && (
          <Field>
            <FieldLabel htmlFor="add-drop-section">Section</FieldLabel>
            <Select
              value={addSectionId !== null ? String(addSectionId) : ""}
              onValueChange={(value) => setAddSectionId(Number(value) || null)}
            >
              <SelectTrigger id="add-drop-section" className="w-full">
                <SelectValue placeholder="Choose a section" />
              </SelectTrigger>
              <SelectContent>
                {sectionsForAddSubject.map((section) => (
                  <SelectItem key={section.id} value={String(section.id)}>
                    Section {section.section_code} ({section.remaining_seats}{" "}
                    seats open)
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </Field>
        )}
        <Field>
          <FieldLabel htmlFor="add-drop-reason">Reason</FieldLabel>
          <Textarea
            id="add-drop-reason"
            value={addReason}
            onChange={(event) => setAddReason(event.target.value)}
            disabled={createMutation.isPending}
          />
        </Field>
        <Button
          type="button"
          disabled={createMutation.isPending}
          onClick={() => void submitAdd()}
        >
          {createMutation.isPending ? "Submitting" : "Submit request"}
        </Button>
      </AccordionCard>

      <AccordionCard
        id="add-drop-request-history"
        title="Your add/drop requests"
        defaultOpen={defaultOpen}
      >
        <AsyncBoundary
          query={{ ...requestsQuery, data: requestsQuery.data?.data }}
          isEmpty={(rows) => rows.length === 0}
          emptyMessage="You have not submitted any add/drop requests yet."
          loadingLabel="Loading your requests…"
        >
          {(requests) => (
            <DataTable
              caption="Your add/drop requests"
              rowKey={(request) => request.id}
              rows={requests}
              columns={[
                {
                  key: "type",
                  header: "Type",
                  render: (request) =>
                    request.request_type === "change_section"
                      ? "Change subject"
                      : request.request_type_label,
                },
                {
                  key: "subject",
                  header: "Subject",
                  render: (request) => request.subject_code,
                },
                {
                  key: "sections",
                  header: "Sections",
                  render: (request) =>
                    [request.from_section_code, request.to_section_code]
                      .filter(Boolean)
                      .join(" → ") || "—",
                },
                {
                  key: "status",
                  header: "Status",
                  render: (request) => (
                    <Badge variant={statusBadgeVariant(request.status)}>
                      {request.status_label}
                    </Badge>
                  ),
                },
                {
                  key: "decision_reason",
                  header: "Decision reason",
                  render: (request) => request.decision_reason ?? "—",
                },
              ]}
            />
          )}
        </AsyncBoundary>
      </AccordionCard>

      <AlertDialog
        open={pending !== null}
        onOpenChange={(open) => {
          if (!open && !createMutation.isPending) setPending(null)
        }}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>
              {pending?.kind === "drop"
                ? `Drop ${pending.subjectLabel}?`
                : `Change subject for ${pending?.subjectLabel}?`}
            </AlertDialogTitle>
            <AlertDialogDescription>
              This submits a request for Registrar Head approval — it does not
              take effect immediately.
            </AlertDialogDescription>
          </AlertDialogHeader>
          {pending?.kind === "change_section" && (
            <>
              <Field>
                <FieldLabel htmlFor="change-subject-target">
                  New subject
                </FieldLabel>
                <SearchableCombobox
                  id="change-subject-target"
                  label="New subject"
                  options={changeSubjectOptions}
                  value={
                    effectiveChangeSubjectId !== null
                      ? String(effectiveChangeSubjectId)
                      : ""
                  }
                  onValueChange={(value) => {
                    setChangeSubjectId(Number(value) || null)
                    setToSectionId(null)
                  }}
                  placeholder="Search or choose a subject"
                  emptyMessage={subjectPickerEmptyMessage}
                />
              </Field>
              <Field data-invalid={sectionRequired}>
                <FieldLabel htmlFor="change-section-target">
                  New section
                </FieldLabel>
                <Select
                  value={toSectionId !== null ? String(toSectionId) : ""}
                  onValueChange={(value) =>
                    setToSectionId(Number(value) || null)
                  }
                >
                  <SelectTrigger id="change-section-target" className="w-full">
                    <SelectValue placeholder="Choose a section" />
                  </SelectTrigger>
                  <SelectContent>
                    {sectionsForChange.length === 0 ? (
                      <SelectItem value="none" disabled>
                        {effectiveChangeSubjectId === null
                          ? "Choose a subject first"
                          : "No open sections available"}
                      </SelectItem>
                    ) : (
                      sectionsForChange.map((section) => (
                        <SelectItem key={section.id} value={String(section.id)}>
                          Section {section.section_code} (
                          {section.remaining_seats} seats open)
                        </SelectItem>
                      ))
                    )}
                  </SelectContent>
                </Select>
              </Field>
            </>
          )}
          <Field data-invalid={reasonRequired}>
            <FieldLabel htmlFor="add-drop-decision-reason">Reason</FieldLabel>
            <Textarea
              id="add-drop-decision-reason"
              value={reason}
              onChange={(event) => setReason(event.target.value)}
              disabled={createMutation.isPending}
              aria-describedby={
                reasonRequired ? "add-drop-decision-reason-error" : undefined
              }
            />
            {reasonRequired && (
              <p
                id="add-drop-decision-reason-error"
                className="text-sm text-destructive"
              >
                Reason is required.
              </p>
            )}
          </Field>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={createMutation.isPending}>
              Cancel
            </AlertDialogCancel>
            <Button
              type="button"
              disabled={createMutation.isPending}
              onClick={() => void confirmPending()}
            >
              {createMutation.isPending ? "Submitting" : "Submit request"}
            </Button>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  )
}
