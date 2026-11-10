"use client"

import { ArrowRight } from "lucide-react"
import { useState, type FormEvent } from "react"

import { AsyncBoundary } from "@/features/components/portal/async-boundary"
import { Alert, AlertDescription } from "@/features/components/ui/alert"
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
import { SearchableCombobox } from "@/features/components/ui/searchable-combobox"
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/features/components/ui/table"
import {
  useEnrollmentMovementsQuery,
  useRecordProgramShiftMutation,
} from "@/features/hooks/use-enrollment-movements"
import { useProgramsQuery } from "@/features/hooks/use-reference-data"
import { apiErrorMessages } from "@/features/lib/api-error-messages"
import type { EnrollmentMovementType } from "@/features/schemas/enrollment-movement-schema"

const COPY: Record<
  EnrollmentMovementType,
  { title: string; description: string; empty: string; groupHeader: string }
> = {
  drops: {
    title: "Subject drops",
    description:
      "Approved requests to drop a subject this term, counted per student and course of study.",
    empty: "No drop has been approved for this term.",
    groupHeader: "Course",
  },
  withdrawals: {
    title: "Withdrawals",
    description:
      "Approved withdrawals from the school this term, counted per student and course of study.",
    empty: "No withdrawal has been approved for this term.",
    groupHeader: "Course",
  },
  shifts: {
    title: "Course shifts",
    description:
      "Students who moved from one course to another this term, as recorded by the Registrar.",
    empty: "No course shift has been recorded for this term.",
    groupHeader: "From → to",
  },
}

/**
 * One movement view of Enrollment Analytics (ADR 0034): a total, the split by
 * department, and the counts behind it. Counts only, no student is named. On
 * the Course Shifts view the Registrar can also record a shift.
 */
export function EnrollmentMovementsPanel({
  type,
  termId,
  college,
  canRecord,
}: {
  type: EnrollmentMovementType
  termId: number
  /** The department filter, for the roles that can choose one. */
  college: string | null
  canRecord: boolean
}) {
  const movementsQuery = useEnrollmentMovementsQuery(termId, type, college)
  const copy = COPY[type]

  return (
    <div className="grid gap-4">
      <AsyncBoundary
        query={movementsQuery}
        loadingLabel="Loading enrollment movements…"
      >
        {(data) => (
          <>
            <div className="grid gap-3 sm:grid-cols-[auto_1fr]">
              <Card>
                <CardHeader>
                  <CardTitle level={2}>{copy.title}</CardTitle>
                  <CardDescription>{copy.description}</CardDescription>
                </CardHeader>
                <CardContent>
                  <p className="text-3xl font-semibold tabular-nums">
                    {data.total}
                  </p>
                  <p className="text-xs text-muted-foreground">
                    {type === "shifts" ? "students shifted" : "students"} this
                    term
                  </p>
                </CardContent>
              </Card>

              <Card>
                <CardHeader>
                  <CardTitle level={2}>By department</CardTitle>
                </CardHeader>
                <CardContent>
                  <ul className="grid gap-1.5 text-sm">
                    {data.by_department.map((department) => (
                      <li
                        key={department.college}
                        className="flex items-center justify-between gap-3"
                      >
                        <span>{department.label}</span>
                        <span className="font-semibold tabular-nums">
                          {department.count}
                        </span>
                      </li>
                    ))}
                  </ul>
                </CardContent>
              </Card>
            </div>

            <Card>
              <CardHeader>
                <CardTitle level={2}>{copy.title} by course</CardTitle>
              </CardHeader>
              <CardContent>
                {data.groups.length === 0 ? (
                  <p className="rounded-lg border border-dashed p-5 text-sm text-muted-foreground">
                    {copy.empty}
                  </p>
                ) : (
                  <Table>
                    <TableHeader>
                      <TableRow>
                        <TableHead>{copy.groupHeader}</TableHead>
                        <TableHead className="text-right">Students</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {data.groups.map((group) => (
                        <TableRow key={group.label}>
                          <TableCell>
                            {group.from_program_code !== null &&
                            group.to_program_code !== null ? (
                              <span className="inline-flex items-center gap-2">
                                {group.from_program_code}
                                <ArrowRight
                                  className="size-4 text-muted-foreground"
                                  aria-label="to"
                                />
                                {group.to_program_code}
                              </span>
                            ) : (
                              group.label
                            )}
                          </TableCell>
                          <TableCell className="text-right tabular-nums">
                            {group.count}
                          </TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                )}
              </CardContent>
            </Card>
          </>
        )}
      </AsyncBoundary>

      {type === "shifts" && canRecord && <RecordShiftCard termId={termId} />}
    </div>
  )
}

function RecordShiftCard({ termId }: { termId: number }) {
  const programsQuery = useProgramsQuery()
  const record = useRecordProgramShiftMutation()
  const [studentNumber, setStudentNumber] = useState("")
  const [toProgramId, setToProgramId] = useState("")
  const [reason, setReason] = useState("")
  const [saved, setSaved] = useState(false)

  const valid =
    studentNumber.trim() !== "" &&
    toProgramId !== "" &&
    reason.trim().length >= 3

  const submit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    if (!valid) return
    setSaved(false)
    record.mutate(
      {
        student_number: studentNumber,
        to_program_id: Number(toProgramId),
        academic_term_id: termId,
        reason,
      },
      {
        onSuccess: () => {
          setSaved(true)
          setStudentNumber("")
          setToProgramId("")
          setReason("")
        },
      },
    )
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle level={2}>Record a course shift</CardTitle>
        <CardDescription>
          Records that a student moved to another course so it shows up above.
          It does not change the student&apos;s program or curriculum.
        </CardDescription>
      </CardHeader>
      <CardContent>
        <form onSubmit={submit} className="grid gap-4">
          <FieldGroup className="grid gap-3 sm:grid-cols-2">
            <Field>
              <FieldLabel htmlFor="shift-student-number">
                Student number
              </FieldLabel>
              <Input
                id="shift-student-number"
                value={studentNumber}
                onChange={(event) => setStudentNumber(event.target.value)}
                disabled={record.isPending}
              />
            </Field>
            <Field>
              <FieldLabel htmlFor="shift-to-program">New course</FieldLabel>
              <SearchableCombobox
                id="shift-to-program"
                label="New course"
                options={(programsQuery.data ?? []).map((program) => ({
                  value: String(program.id),
                  label: `${program.code} — ${program.name}`,
                }))}
                value={toProgramId}
                onValueChange={setToProgramId}
                placeholder="Search course"
                emptyMessage="No matching course."
              />
            </Field>
            <Field className="sm:col-span-2">
              <FieldLabel htmlFor="shift-reason">Reason</FieldLabel>
              <Input
                id="shift-reason"
                value={reason}
                onChange={(event) => setReason(event.target.value)}
                disabled={record.isPending}
              />
            </Field>
          </FieldGroup>

          {record.error !== null && (
            <Alert variant="destructive">
              <AlertDescription>
                {apiErrorMessages(
                  record.error,
                  "The course shift could not be recorded. Try again.",
                ).map((message) => (
                  <p key={message}>{message}</p>
                ))}
              </AlertDescription>
            </Alert>
          )}
          {saved && (
            <Alert>
              <AlertDescription>Course shift recorded.</AlertDescription>
            </Alert>
          )}

          <div>
            <Button type="submit" disabled={!valid || record.isPending}>
              {record.isPending ? "Recording…" : "Record course shift"}
            </Button>
          </div>
        </form>
      </CardContent>
    </Card>
  )
}
