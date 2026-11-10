"use client"

import { useMutation, useQueryClient } from "@tanstack/react-query"
import { useState } from "react"

import { useAuth } from "@/features/auth/use-auth"
import { AsyncBoundary } from "@/features/components/portal/async-boundary"
import { WorkspacePage } from "@/features/components/portal/workspace-page"
import { Alert, AlertDescription } from "@/features/components/ui/alert"
import { Button } from "@/features/components/ui/button"
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/features/components/ui/card"
import { Field, FieldLabel } from "@/features/components/ui/field"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/features/components/ui/select"
import { useFacultyDirectoryQuery } from "@/features/hooks/use-faculty-directory"
import {
  useFacultyAvailabilitiesQuery,
  useFacultySubjectPreferencesQuery,
} from "@/features/hooks/use-faculty-input"
import { formatTimeRange } from "@/features/lib/format-time"
import {
  useAcademicTermsQuery,
  useSectionsQuery,
  useSubjectsQuery,
  sectionsQueryKey,
} from "@/features/hooks/use-reference-data"
import { applyApiFieldErrors } from "@/features/lib/api-form-errors"
import {
  replaceSection,
  toSectionReplacement,
} from "@/features/services/scheduling-service"
import {
  formatAcademicTerm,
  getActiveAcademicTerm,
} from "@/features/services/reference-data-service"

const weekday = [
  "",
  "Monday",
  "Tuesday",
  "Wednesday",
  "Thursday",
  "Friday",
  "Saturday",
  "Sunday",
]

export function FacultyAssignmentWorkspace() {
  const { session } = useAuth()
  const queryClient = useQueryClient()
  const termsQuery = useAcademicTermsQuery()
  const subjectsQuery = useSubjectsQuery()
  const sectionsQuery = useSectionsQuery()
  const directoryQuery = useFacultyDirectoryQuery()
  const availabilitiesQuery = useFacultyAvailabilitiesQuery()
  const preferencesQuery = useFacultySubjectPreferencesQuery()
  const [termId, setTermId] = useState(0)
  const [sectionId, setSectionId] = useState(0)
  const [professorId, setProfessorId] = useState(0)
  const [requestError, setRequestError] = useState("")
  const activeTerm = getActiveAcademicTerm(termsQuery.data)
  const selectedTermId = termId === 0 ? (activeTerm?.id ?? 0) : termId
  const unassigned = (sectionsQuery.data ?? []).filter(
    (section) =>
      section.academic_term_id === selectedTermId &&
      section.professor_id === null,
  )
  const section = unassigned.find((item) => item.id === sectionId) ?? null
  const faculty =
    (directoryQuery.data ?? []).find((member) => member.id === professorId) ??
    null
  const availability = (availabilitiesQuery.data ?? []).filter(
    (item) => item.professor_id === professorId,
  )
  const preferences = (preferencesQuery.data ?? []).filter(
    (item) =>
      item.professor_id === professorId &&
      item.academic_term_id === selectedTermId &&
      item.subject_id === section?.subject_id,
  )
  const mutation = useMutation({
    mutationFn: () => {
      if (!section || !faculty)
        throw new Error(
          "Select an unassigned section and an active faculty member.",
        )
      return replaceSection(
        section.id,
        toSectionReplacement(section, { professor_id: faculty.id }),
      )
    },
    onSuccess: () =>
      queryClient.invalidateQueries({
        queryKey: sectionsQueryKey(session?.userId ?? null),
        exact: true,
      }),
  })
  const save = async () => {
    setRequestError("")
    try {
      await mutation.mutateAsync()
      setSectionId(0)
      setProfessorId(0)
    } catch (error) {
      const messages: string[] = []
      applyApiFieldErrors(error, (_field, detail) => {
        if (detail.message) messages.push(detail.message)
      })
      setRequestError(
        messages[0] ??
          (error instanceof Error
            ? error.message
            : "The faculty assignment could not be saved. Try again."),
      )
    }
  }
  const retryReferences = () => {
    void Promise.all([
      termsQuery.refetch(),
      subjectsQuery.refetch(),
      sectionsQuery.refetch(),
      directoryQuery.refetch(),
      availabilitiesQuery.refetch(),
      preferencesQuery.refetch(),
    ])
  }
  const referenceDataQuery = {
    isPending:
      termsQuery.isPending ||
      subjectsQuery.isPending ||
      sectionsQuery.isPending ||
      directoryQuery.isPending ||
      availabilitiesQuery.isPending ||
      preferencesQuery.isPending,
    isError:
      termsQuery.isError ||
      subjectsQuery.isError ||
      sectionsQuery.isError ||
      directoryQuery.isError ||
      availabilitiesQuery.isError ||
      preferencesQuery.isError,
    error:
      termsQuery.error ??
      subjectsQuery.error ??
      sectionsQuery.error ??
      directoryQuery.error ??
      availabilitiesQuery.error ??
      preferencesQuery.error,
    data: true as const,
    refetch: retryReferences,
  }
  const subject = (subjectsQuery.data ?? []).find(
    (item) => item.id === section?.subject_id,
  )
  return (
    <WorkspacePage
      title="Faculty assignment"
      description="Assign active faculty to unassigned planned sections, with their declared constraints in view."
      lastUpdated={sectionsQuery.dataUpdatedAt}
    >
      {requestError && (
        <Alert variant="destructive">
          <AlertDescription>{requestError}</AlertDescription>
        </Alert>
      )}
      <AsyncBoundary
        query={referenceDataQuery}
        loadingLabel="Loading faculty assignment data…"
      >
        {() => (
          <Card>
            <CardHeader>
              <CardTitle level={2}>Assign faculty</CardTitle>
              <CardDescription>
                The API remains authoritative for faculty schedule conflicts;
                this view provides declared availability and subject-preference
                context.
              </CardDescription>
            </CardHeader>
            <CardContent className="grid gap-4">
              <Field>
                <FieldLabel htmlFor="assignment-term">Academic term</FieldLabel>
                <Select
                  value={selectedTermId > 0 ? String(selectedTermId) : ""}
                  onValueChange={(value) => {
                    setTermId(Number(value))
                    setSectionId(0)
                    setProfessorId(0)
                  }}
                >
                  <SelectTrigger id="assignment-term" className="w-full">
                    <SelectValue placeholder="Select an academic term" />
                  </SelectTrigger>
                  <SelectContent>
                    {(termsQuery.data ?? []).map((term) => (
                      <SelectItem key={term.id} value={String(term.id)}>
                        {formatAcademicTerm(term)}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </Field>
              <Field>
                <FieldLabel htmlFor="assignment-section">
                  Unassigned section
                </FieldLabel>
                <Select
                  value={sectionId > 0 ? String(sectionId) : ""}
                  onValueChange={(value) => setSectionId(Number(value))}
                >
                  <SelectTrigger id="assignment-section" className="w-full">
                    <SelectValue placeholder="Select an unassigned section" />
                  </SelectTrigger>
                  <SelectContent>
                    {unassigned.map((item) => (
                      <SelectItem key={item.id} value={String(item.id)}>
                        {(subjectsQuery.data ?? []).find(
                          (subject) => subject.id === item.subject_id,
                        )?.code ?? "Subject"}{" "}
                        · {item.section_code}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </Field>
              {selectedTermId > 0 && unassigned.length === 0 && (
                <p>
                  No unassigned sections are available for this academic term.
                </p>
              )}
              <Field>
                <FieldLabel htmlFor="assignment-faculty">
                  Faculty member
                </FieldLabel>
                <Select
                  value={professorId > 0 ? String(professorId) : ""}
                  onValueChange={(value) => setProfessorId(Number(value))}
                  disabled={!section}
                >
                  <SelectTrigger id="assignment-faculty" className="w-full">
                    <SelectValue placeholder="Select an active faculty member" />
                  </SelectTrigger>
                  <SelectContent>
                    {(directoryQuery.data ?? []).map((member) => (
                      <SelectItem key={member.id} value={String(member.id)}>
                        {member.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </Field>
              {faculty && (
                <aside
                  aria-label="Faculty constraint context"
                  className="rounded-md border p-3"
                >
                  <h3>{faculty.name}’s declared context</h3>
                  <p>
                    {availability.length
                      ? availability
                          .map(
                            (item) =>
                              `${weekday[item.day_of_week]} ${formatTimeRange(item.starts_at_time, item.ends_at_time)}`,
                          )
                          .join(", ")
                      : "No availability declared for this term."}
                  </p>
                  <p>
                    {preferences.length
                      ? preferences
                          .map(
                            (item) =>
                              `Preference #${item.rank} for ${subject?.code ?? "this subject"}`,
                          )
                          .join(", ")
                      : `No preference declared for ${subject?.code ?? "this subject"}.`}
                  </p>
                </aside>
              )}
              <Button
                type="button"
                onClick={() => void save()}
                disabled={!section || !faculty || mutation.isPending}
              >
                {mutation.isPending
                  ? "Saving faculty assignment"
                  : "Save faculty assignment"}
              </Button>
            </CardContent>
          </Card>
        )}
      </AsyncBoundary>
    </WorkspacePage>
  )
}
