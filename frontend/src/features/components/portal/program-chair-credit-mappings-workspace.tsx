"use client"

import { useState } from "react"

import { useAuth } from "@/features/auth/use-auth"
import { AsyncBoundary } from "@/features/components/portal/async-boundary"
import { DataTable } from "@/features/components/portal/data-table"
import {
  StudentCreditMappingPanel,
  type CreditMappingStudent,
} from "@/features/components/portal/student-credit-mapping-panel"
import {
  StudentTorFiles,
  TorUploadsCard,
  type TorStudent,
} from "@/features/components/portal/tor-documents-panel"
import { ProgramChairEnrollmentTabs } from "@/features/components/portal/program-chair-enrollment-tabs"
import { WorkspacePage } from "@/features/components/portal/workspace-page"
import { Alert, AlertDescription } from "@/features/components/ui/alert"
import { Badge } from "@/features/components/ui/badge"
import { Button } from "@/features/components/ui/button"
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/features/components/ui/card"
import { Field, FieldGroup, FieldLabel } from "@/features/components/ui/field"
import { Input } from "@/features/components/ui/input"
import { useAcademicRecordStudentSearchQuery } from "@/features/hooks/use-academic-record"
import { useDebouncedValue } from "@/features/hooks/use-debounced-value"
import {
  useConfirmNoCreditDeclarationMutation,
  useNoCreditDeclarationsQuery,
} from "@/features/hooks/use-no-credit-declaration"
import {
  useAllTransfereeCreditsQuery,
  useCreateTransfereeCreditMutation,
  useTransfereeCreditsQuery,
} from "@/features/hooks/use-transferee-credits"
import { groupCreditsByStudent } from "@/features/lib/credit-mapping"
import type { AcademicRecordStudentLookup } from "@/features/schemas/academic-record-schema"
import type { NoCreditDeclaration } from "@/features/schemas/no-credit-declaration-schema"
import type { TransfereeCredit } from "@/features/schemas/transferee-credit-schema"
import { isApiClientError } from "@/features/services/api-client"

const MIN_SEARCH_LENGTH = 2

function studentLabel(credit: TransfereeCredit): string {
  return credit.student_name
    ? `${credit.student_name} (${credit.student_number})`
    : credit.student_number
}

function previousSubject(credit: TransfereeCredit): string {
  return credit.source_subject_code
    ? `${credit.source_subject_title} (${credit.source_subject_code})`
    : credit.source_subject_title
}

/**
 * Credit mapping is the Program Chair's work (ADR 0026, amended): a student
 * saves their subjects and sends the list, and the Chair maps each previous
 * subject to a subject of the student's curriculum on a side-by-side screen for
 * that one student (the system suggests, the Chair decides), then sends the
 * whole student to the Registrar Head, who approves. A student who walks in
 * without having asked can be recorded here too. A returnee from an old
 * curriculum is not handled here: the Curriculum Editor's migration already
 * does that.
 */
/** The part of a student the "record a credit" form needs. */
type CreditStudent = Pick<
  AcademicRecordStudentLookup,
  "student_id" | "name" | "student_number"
>

export function ProgramChairCreditMappingsWorkspace() {
  const { session } = useAuth()
  const authorized = session?.role === "program_chair"

  // The student whose side-by-side screen is open.
  const [reviewing, setReviewing] = useState<CreditMappingStudent | null>(null)
  const [sentNote, setSentNote] = useState("")
  // The student whose credits the chair is about to record, picked from a TOR upload.
  const [recordFor, setRecordFor] = useState<TorStudent | null>(null)

  // Every page: the queue is grouped by student, and a transferee with a full
  // transcript has dozens of rows, so one page of rows would hide students.
  const pendingQuery = useAllTransfereeCreditsQuery(
    { status: "pending" },
    { enabled: authorized },
  )
  const endorsedQuery = useTransfereeCreditsQuery(
    { status: "endorsed", page: 1, per_page: 20 },
    { enabled: authorized },
  )
  const waitingStudents = groupCreditsByStudent(pendingQuery.data ?? [])
  // Students who told the Chair they have nothing to credit: confirming it lets them enroll.
  const noCreditQuery = useNoCreditDeclarationsQuery({ enabled: authorized })
  const confirmNoCreditMutation = useConfirmNoCreditDeclarationMutation()
  const [noCreditError, setNoCreditError] = useState("")

  const confirmNoCredit = async (declaration: NoCreditDeclaration) => {
    setNoCreditError("")
    setSentNote("")
    try {
      await confirmNoCreditMutation.mutateAsync(declaration.id)
      setSentNote(
        `Confirmed that ${declaration.student_name || declaration.student_number} has no subject to credit. They can go on to enroll.`,
      )
    } catch (err) {
      setNoCreditError(
        err instanceof Error
          ? err.message
          : "This could not be confirmed. Please try again.",
      )
    }
  }

  return (
    <WorkspacePage
      title="Credit mappings"
      description="Review each student's list of subjects from another school, map every one side by side to a subject in their curriculum, and send the student to the Registrar Head."
      unauthorized={!authorized}
      lastUpdated={Math.max(
        pendingQuery.dataUpdatedAt,
        endorsedQuery.dataUpdatedAt,
      )}
    >
      <ProgramChairEnrollmentTabs current="credit-mappings" />
      {sentNote && (
        <Alert>
          <AlertDescription>{sentNote}</AlertDescription>
        </Alert>
      )}

      <Card>
        <CardHeader>
          <CardTitle level={2}>Students waiting for review</CardTitle>
          <CardDescription>
            Students in your college whose subjects still need a mapping. Open
            one to map all of their subjects side by side.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <AsyncBoundary
            query={{ ...pendingQuery, data: waitingStudents }}
            isEmpty={(rows) => rows.length === 0}
            emptyMessage="No student is waiting for a credit review."
            loadingLabel="Loading credit requests…"
          >
            {(students) => (
              <DataTable
                caption="Students waiting for review"
                rowKey={(student) => student.student_id}
                rows={students}
                columns={[
                  {
                    key: "student",
                    header: "Student",
                    render: (student) =>
                      student.student_name
                        ? `${student.student_name} (${student.student_number})`
                        : student.student_number,
                  },
                  {
                    key: "subjects",
                    header: "Subjects",
                    render: (student) =>
                      `${student.credits.length} ${student.credits.length === 1 ? "subject" : "subjects"}`,
                  },
                  {
                    key: "actions",
                    header: "Actions",
                    render: (student) => (
                      <Button
                        type="button"
                        size="sm"
                        aria-label={`Review ${student.student_name || student.student_number}`}
                        onClick={() => {
                          setSentNote("")
                          setReviewing({
                            student_id: student.student_id,
                            student_name: student.student_name,
                            student_number: student.student_number,
                          })
                        }}
                      >
                        Review
                      </Button>
                    ),
                  },
                ]}
              />
            )}
          </AsyncBoundary>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle level={2}>No subject to credit</CardTitle>
          <CardDescription>
            Transferees and Returnees who told you they have nothing to credit.
            Confirm it and they can go on to enroll; the Registrar Head has
            nothing to approve.
          </CardDescription>
        </CardHeader>
        <CardContent className="grid gap-3">
          {noCreditError && (
            <Alert variant="destructive">
              <AlertDescription>{noCreditError}</AlertDescription>
            </Alert>
          )}
          <AsyncBoundary
            query={noCreditQuery}
            isEmpty={(rows) => rows.length === 0}
            emptyMessage="No student is waiting for you to confirm that they have nothing to credit."
            loadingLabel="Loading students with no subject to credit…"
          >
            {(declarations) => (
              <DataTable
                caption="Students with no subject to credit"
                rowKey={(declaration) => declaration.id}
                rows={declarations}
                columns={[
                  {
                    key: "student",
                    header: "Student",
                    render: (declaration) =>
                      declaration.student_name
                        ? `${declaration.student_name} (${declaration.student_number})`
                        : declaration.student_number,
                  },
                  {
                    key: "sent",
                    header: "Sent",
                    render: (declaration) =>
                      new Date(declaration.declared_at).toLocaleDateString(
                        "en-PH",
                        { dateStyle: "medium" },
                      ),
                  },
                  {
                    key: "actions",
                    header: "Actions",
                    render: (declaration) => (
                      <Button
                        type="button"
                        size="sm"
                        disabled={confirmNoCreditMutation.isPending}
                        aria-label={`Confirm that ${declaration.student_name || declaration.student_number} has no subject to credit`}
                        onClick={() => void confirmNoCredit(declaration)}
                      >
                        Confirm
                      </Button>
                    ),
                  },
                ]}
              />
            )}
          </AsyncBoundary>
        </CardContent>
      </Card>

      {reviewing && (
        <StudentCreditMappingPanel
          key={reviewing.student_id}
          student={reviewing}
          onClose={() => setReviewing(null)}
          onSent={(count) => {
            setSentNote(
              `Sent ${count} ${count === 1 ? "credit" : "credits"} of ${reviewing.student_name || reviewing.student_number} to the Registrar Head.`,
            )
            setReviewing(null)
          }}
        />
      )}

      <Card>
        <CardHeader>
          <CardTitle level={2}>Waiting for the Registrar Head</CardTitle>
          <CardDescription>
            Credits you have sent. The Registrar Head approves, returns or
            rejects them.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <AsyncBoundary
            query={{ ...endorsedQuery, data: endorsedQuery.data?.data }}
            isEmpty={(rows) => rows.length === 0}
            emptyMessage="Nothing is waiting for the Registrar Head."
            loadingLabel="Loading endorsed credits…"
          >
            {(credits) => (
              <DataTable
                caption="Endorsed credits"
                rowKey={(credit) => credit.id}
                rows={credits}
                columns={[
                  {
                    key: "student",
                    header: "Student",
                    render: (credit) => studentLabel(credit),
                  },
                  {
                    key: "previous",
                    header: "Previous subject",
                    render: (credit) => previousSubject(credit),
                  },
                  {
                    key: "credited-as",
                    header: "Credited as",
                    render: (credit) =>
                      `${credit.subject_code ?? ""} — ${credit.subject_title ?? ""}`,
                  },
                  {
                    key: "status",
                    header: "Status",
                    render: (credit) => (
                      <Badge variant="secondary">{credit.status_label}</Badge>
                    ),
                  },
                ]}
              />
            )}
          </AsyncBoundary>
        </CardContent>
      </Card>

      <TorUploadsCard onRecordCredit={setRecordFor} />

      <RecordCreditCard
        key={recordFor?.student_id ?? "search"}
        presetStudent={recordFor}
      />
    </WorkspacePage>
  )
}

/**
 * For a student who came to the Program Chair without having asked online:
 * find the student (own college only), enter what they took, and it joins the
 * review list above to be mapped and endorsed like any other request.
 */
function RecordCreditCard({
  presetStudent = null,
}: {
  /** A student already chosen (from a TOR upload); the chair can still change it. */
  presetStudent?: TorStudent | null
}) {
  const [search, setSearch] = useState("")
  const [student, setStudent] = useState<CreditStudent | null>(
    presetStudent === null
      ? null
      : {
          student_id: presetStudent.student_id,
          name: presetStudent.student_name,
          student_number: presetStudent.student_number,
        },
  )
  const [institution, setInstitution] = useState("")
  const [code, setCode] = useState("")
  const [title, setTitle] = useState("")
  const [grade, setGrade] = useState("")
  const [units, setUnits] = useState("3")
  const [schoolYear, setSchoolYear] = useState("")
  const [semester, setSemester] = useState("")
  const [error, setError] = useState("")
  const [recorded, setRecorded] = useState("")

  // Look the student up 300 ms after typing stops, not on every keystroke.
  const trimmed = useDebouncedValue(search.trim(), 300)
  const lookupQuery = useAcademicRecordStudentSearchQuery(
    { search: trimmed, limit: 8 },
    { enabled: student === null && trimmed.length >= MIN_SEARCH_LENGTH },
  )
  const createMutation = useCreateTransfereeCreditMutation()

  const submit = async () => {
    setError("")
    setRecorded("")
    const parsedUnits = Number(units)

    if (student === null) {
      setError("Find and choose the student first.")
      return
    }
    if (!institution.trim() || !title.trim()) {
      setError("Enter the previous school and the subject title.")
      return
    }
    if (
      !Number.isFinite(parsedUnits) ||
      parsedUnits <= 0 ||
      parsedUnits > 99.9
    ) {
      setError("Units must be more than 0 (for example 3 or 1.5).")
      return
    }

    try {
      await createMutation.mutateAsync({
        student_id: student.student_id,
        source_institution: institution.trim(),
        source_subject_code: code.trim().toUpperCase() || undefined,
        source_subject_title: title.trim(),
        source_grade: grade.trim() || undefined,
        credited_units: parsedUnits,
        source_school_year: schoolYear.trim() || undefined,
        source_semester: semester.trim() || undefined,
      })
      setRecorded(
        `Recorded for ${student.name}. It is in the review list above, ready to map.`,
      )
      setStudent(null)
      setSearch("")
      setInstitution("")
      setCode("")
      setTitle("")
      setGrade("")
      setUnits("3")
      setSchoolYear("")
      setSemester("")
    } catch (createError) {
      const message = isApiClientError(createError)
        ? (Object.values(createError.fieldErrors ?? {})[0]?.[0] ?? "")
        : ""
      setError(
        message ||
          "The credit could not be recorded. Check the connection and try again.",
      )
    }
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle level={2}>Record a credit for a student</CardTitle>
        <CardDescription>
          For a student who did not ask online. It joins the review list above.
        </CardDescription>
      </CardHeader>
      <CardContent>
        {error && (
          <Alert variant="destructive" className="mb-4">
            <AlertDescription>{error}</AlertDescription>
          </Alert>
        )}
        {recorded && (
          <Alert className="mb-4">
            <AlertDescription>{recorded}</AlertDescription>
          </Alert>
        )}
        <FieldGroup>
          {student === null ? (
            <Field>
              <FieldLabel htmlFor="credit-student-search">
                Find student (name or student number)
              </FieldLabel>
              <Input
                id="credit-student-search"
                value={search}
                onChange={(event) => setSearch(event.target.value)}
                placeholder="e.g. Reyes or 2024-06-01298"
              />
              {trimmed.length >= MIN_SEARCH_LENGTH && lookupQuery.isSuccess && (
                <ul className="mt-2 grid gap-1" aria-label="Matching students">
                  {(lookupQuery.data ?? []).length === 0 && (
                    <li className="text-sm text-muted-foreground">
                      No student in your college matches.
                    </li>
                  )}
                  {(lookupQuery.data ?? []).map((match) => (
                    <li key={match.student_id}>
                      <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="w-full justify-between"
                        onClick={() => setStudent(match)}
                      >
                        <span>{match.name}</span>
                        <span className="font-mono text-xs">
                          {match.student_number}
                        </span>
                      </Button>
                    </li>
                  ))}
                </ul>
              )}
            </Field>
          ) : (
            <div className="flex flex-wrap items-center justify-between gap-2 rounded-lg border p-3 text-sm">
              <span>
                <span className="font-medium">{student.name}</span>{" "}
                <span className="font-mono text-xs">
                  {student.student_number}
                </span>
              </span>
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() => setStudent(null)}
              >
                Change student
              </Button>
            </div>
          )}
          {student !== null && (
            <StudentTorFiles studentId={student.student_id} />
          )}
          <Field>
            <FieldLabel htmlFor="credit-source-institution">
              Previous school
            </FieldLabel>
            <Input
              id="credit-source-institution"
              value={institution}
              onChange={(event) => setInstitution(event.target.value)}
              disabled={createMutation.isPending}
            />
          </Field>
          <Field>
            <FieldLabel htmlFor="credit-source-title">Subject title</FieldLabel>
            <Input
              id="credit-source-title"
              value={title}
              onChange={(event) => setTitle(event.target.value)}
              disabled={createMutation.isPending}
            />
          </Field>
          <Field>
            <FieldLabel htmlFor="credit-source-code">
              Subject code (optional)
            </FieldLabel>
            <Input
              id="credit-source-code"
              value={code}
              onChange={(event) => setCode(event.target.value)}
              disabled={createMutation.isPending}
            />
          </Field>
          <Field>
            <FieldLabel htmlFor="credit-source-units">Units</FieldLabel>
            <Input
              id="credit-source-units"
              inputMode="decimal"
              value={units}
              onChange={(event) => setUnits(event.target.value)}
              disabled={createMutation.isPending}
            />
          </Field>
          <Field>
            <FieldLabel htmlFor="credit-source-grade">
              Grade (optional)
            </FieldLabel>
            <Input
              id="credit-source-grade"
              value={grade}
              onChange={(event) => setGrade(event.target.value)}
              disabled={createMutation.isPending}
            />
          </Field>
          <Field>
            <FieldLabel htmlFor="credit-source-year">
              School year (optional)
            </FieldLabel>
            <Input
              id="credit-source-year"
              value={schoolYear}
              onChange={(event) => setSchoolYear(event.target.value)}
              disabled={createMutation.isPending}
            />
          </Field>
          <Field>
            <FieldLabel htmlFor="credit-source-semester">
              Semester (optional)
            </FieldLabel>
            <Input
              id="credit-source-semester"
              value={semester}
              onChange={(event) => setSemester(event.target.value)}
              disabled={createMutation.isPending}
            />
          </Field>
          <Button
            type="button"
            disabled={createMutation.isPending}
            onClick={() => void submit()}
          >
            {createMutation.isPending ? "Recording credit…" : "Record credit"}
          </Button>
        </FieldGroup>
      </CardContent>
    </Card>
  )
}
