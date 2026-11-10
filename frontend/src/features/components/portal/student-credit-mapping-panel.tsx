"use client"

import { useState } from "react"

import {
  CreditEntryCell,
  creditEntryTitle,
} from "@/features/components/portal/credit-entry-cell"
import { StudentTorFiles } from "@/features/components/portal/tor-documents-panel"
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
import { Input } from "@/features/components/ui/input"
import { SearchableCombobox } from "@/features/components/ui/searchable-combobox"
import { Textarea } from "@/features/components/ui/textarea"
import {
  useCreditMappingWorkspaceQuery,
  useSubmitCreditsToRegistrarHeadMutation,
  useAllTransfereeCreditsQuery,
  useTransfereeCreditActionMutation,
  useUpdateTransfereeCreditMutation,
} from "@/features/hooks/use-transferee-credits"
import { preselectSuggestions } from "@/features/lib/credit-mapping"
import { isApiClientError } from "@/features/services/api-client"
import type { TransfereeCredit } from "@/features/schemas/transferee-credit-schema"

export interface CreditMappingStudent {
  student_id: number
  student_name: string
  student_number: string
}

/** The API's own sentence for a refused step when it gave one, else `fallback`. */
function apiMessage(error: unknown, fallback: string): string {
  if (isApiClientError(error)) {
    const first = Object.values(error.fieldErrors ?? {})[0]?.[0]
    if (first) return first
  }
  return fallback
}

function without<T>(record: Record<number, T>, key: number): Record<number, T> {
  return Object.fromEntries(
    Object.entries(record).filter(([entry]) => Number(entry) !== key),
  )
}

function validUnits(text: string): number | null {
  const value = Number(text)
  return Number.isFinite(value) && value > 0 && value <= 99.9 ? value : null
}

/**
 * The Program Chair's side-by-side screen for ONE student (ADR 0026, amended):
 * on the left what the student entered, on the right the subject of the
 * student's own curriculum it counts as, chosen in a searchable dropdown.
 *
 * The system's best suggestion is pre-selected on each row and marked
 * "Suggested", but it is only a pre-selection: nothing is saved until the Chair
 * clicks Confirm (or "Confirm all suggested"), so a reload shows the rows
 * unconfirmed again. Two rows never pre-select the same subject. A request with
 * no equivalent is declined with a reason. When every pending row has a saved
 * subject, "Submit to Registrar Head" sends the whole student in one step.
 */
export function StudentCreditMappingPanel({
  student,
  onClose,
  onSent,
}: {
  student: CreditMappingStudent
  onClose: () => void
  /** Called with how many credits were sent to the Registrar Head. */
  onSent: (count: number) => void
}) {
  const [choices, setChoices] = useState<Record<number, string>>({})
  const [unitsText, setUnitsText] = useState<Record<number, string>>({})
  const [declining, setDeclining] = useState<number | null>(null)
  const [reason, setReason] = useState("")
  const [error, setError] = useState("")
  const [note, setNote] = useState("")

  const creditsQuery = useAllTransfereeCreditsQuery({
    student_id: student.student_id,
  })
  const workspaceQuery = useCreditMappingWorkspaceQuery(student.student_id)
  const updateMutation = useUpdateTransfereeCreditMutation()
  const actionMutation = useTransfereeCreditActionMutation()
  const submitMutation = useSubmitCreditsToRegistrarHeadMutation()
  const busy =
    updateMutation.isPending ||
    actionMutation.isPending ||
    submitMutation.isPending

  const credits = [...(creditsQuery.data ?? [])].sort((a, b) => a.id - b.id)
  const pending = credits.filter((credit) => credit.status === "pending")
  const workspace = workspaceQuery.data
  const preselected = preselectSuggestions(pending, workspace?.credits ?? [])

  const subjectOptions = (workspace?.curriculum_subjects ?? []).map(
    (subject) => ({
      value: String(subject.subject_id),
      label: `${subject.subject_code} — ${subject.subject_title}`,
    }),
  )

  // A saved subject that is not on the curriculum list (older data) still has to show.
  const optionsFor = (credit: TransfereeCredit) =>
    credit.subject_id !== null &&
    credit.subject_code !== null &&
    !subjectOptions.some((option) => option.value === String(credit.subject_id))
      ? [
          ...subjectOptions,
          {
            value: String(credit.subject_id),
            label: `${credit.subject_code} — ${credit.subject_title ?? ""}`,
          },
        ]
      : subjectOptions

  /** What the dropdown shows: the Chair's own pick, else the saved subject, else the suggestion. */
  const chosenFor = (credit: TransfereeCredit): string => {
    const own = choices[credit.id]
    if (own !== undefined) return own
    if (credit.subject_id !== null) return String(credit.subject_id)
    const suggested = preselected[credit.id]
    return suggested === undefined ? "" : String(suggested)
  }
  const unitsFor = (credit: TransfereeCredit): string =>
    unitsText[credit.id] ?? String(credit.credited_units)

  const isSuggestedOnly = (credit: TransfereeCredit): boolean =>
    credit.subject_id === null &&
    choices[credit.id] === undefined &&
    preselected[credit.id] !== undefined
  const isConfirmed = (credit: TransfereeCredit): boolean =>
    credit.subject_id !== null &&
    chosenFor(credit) === String(credit.subject_id) &&
    validUnits(unitsFor(credit)) === credit.credited_units

  const suggestedRows = pending.filter(isSuggestedOnly)
  const stillToConfirm = pending.filter(
    (credit) => credit.subject_id === null,
  ).length
  const canSubmit = pending.length > 0 && stillToConfirm === 0 && !busy

  const confirmRow = async (credit: TransfereeCredit) => {
    const subjectId = chosenFor(credit)
    const units = validUnits(unitsFor(credit))
    if (subjectId === "" || units === null) return
    setError("")
    setNote("")
    try {
      await updateMutation.mutateAsync({
        id: credit.id,
        input: { subject_id: Number(subjectId), credited_units: units },
      })
      setChoices((current) => without(current, credit.id))
      setUnitsText((current) => without(current, credit.id))
    } catch (confirmError) {
      setError(
        apiMessage(
          confirmError,
          "The mapping could not be saved. Check the connection and try again.",
        ),
      )
    }
  }

  const confirmAllSuggested = async () => {
    setError("")
    setNote("")
    let confirmed = 0
    for (const credit of suggestedRows) {
      const subjectId = preselected[credit.id]
      const units = validUnits(unitsFor(credit)) ?? credit.credited_units
      try {
        await updateMutation.mutateAsync({
          id: credit.id,
          input: { subject_id: subjectId, credited_units: units },
        })
        confirmed += 1
      } catch (confirmError) {
        setError(
          apiMessage(
            confirmError,
            "A mapping could not be saved. Check the connection and try again.",
          ),
        )
        return
      }
    }
    setNote(
      `Confirmed ${confirmed} suggested ${confirmed === 1 ? "mapping" : "mappings"}.`,
    )
  }

  const decline = async (credit: TransfereeCredit) => {
    if (!reason.trim()) return
    setError("")
    setNote("")
    try {
      await actionMutation.mutateAsync({
        id: credit.id,
        input: { action: "decline", reason: reason.trim() },
      })
      setDeclining(null)
      setReason("")
    } catch (declineError) {
      setError(
        apiMessage(
          declineError,
          "The request could not be declined. Check the connection and try again.",
        ),
      )
    }
  }

  const submit = async () => {
    setError("")
    setNote("")
    try {
      const sent = await submitMutation.mutateAsync(student.student_id)
      onSent(sent.length)
    } catch (submitError) {
      setError(
        apiMessage(
          submitError,
          "The student could not be sent to the Registrar Head. Check the connection and try again.",
        ),
      )
    }
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle level={2}>
          Map credits for {student.student_name || student.student_number}
        </CardTitle>
        <CardDescription>
          {student.student_number}. The best match is selected for you, but
          nothing is saved until you press Confirm.
        </CardDescription>
      </CardHeader>
      <CardContent className="grid gap-4">
        <StudentTorFiles studentId={student.student_id} headingLevel={3} />

        {error && (
          <Alert variant="destructive">
            <AlertDescription>{error}</AlertDescription>
          </Alert>
        )}
        {note && (
          <Alert>
            <AlertDescription>{note}</AlertDescription>
          </Alert>
        )}

        {creditsQuery.isSuccess && credits.length === 0 && (
          <p className="text-sm text-muted-foreground">
            This student has no credit request to map.
          </p>
        )}

        {credits.length > 0 && (
          <>
            <div
              aria-hidden="true"
              className="hidden gap-4 px-3 text-xs font-medium text-muted-foreground md:grid md:grid-cols-[1fr_1.4fr]"
            >
              <span>Student&apos;s entry</span>
              <span>GRC subject</span>
            </div>
            <ul aria-label="Credits to map" className="grid gap-3">
              {credits.map((credit) => (
                <li
                  key={credit.id}
                  className="grid gap-3 rounded-lg border p-3 md:grid-cols-[1fr_1.4fr]"
                >
                  <div className="grid content-start gap-2">
                    <CreditEntryCell credit={credit} />
                    {credit.status === "pending" && credit.return_reason && (
                      <p className="rounded-md bg-amber-500/10 p-2 text-xs text-amber-900 dark:text-amber-200">
                        Returned by the Registrar Head: {credit.return_reason}
                      </p>
                    )}
                  </div>

                  {credit.status === "pending" ? (
                    <div className="grid gap-2">
                      <div className="grid gap-2 sm:grid-cols-[1fr_6rem]">
                        <SearchableCombobox
                          id={`credit-subject-${credit.id}`}
                          label={`Subject for ${creditEntryTitle(credit)}`}
                          options={optionsFor(credit)}
                          value={chosenFor(credit)}
                          onValueChange={(value) => {
                            setChoices((current) => ({
                              ...current,
                              [credit.id]: value,
                            }))
                            setNote("")
                          }}
                          placeholder="Search by subject code or title"
                          emptyMessage="No subject matches."
                          disabled={busy}
                        />
                        <Input
                          id={`credit-units-${credit.id}`}
                          aria-label={`Credited units for ${creditEntryTitle(credit)}`}
                          inputMode="decimal"
                          value={unitsFor(credit)}
                          aria-invalid={validUnits(unitsFor(credit)) === null}
                          onChange={(event) =>
                            setUnitsText((current) => ({
                              ...current,
                              [credit.id]: event.target.value,
                            }))
                          }
                          disabled={busy}
                        />
                      </div>
                      <div className="flex flex-wrap items-center gap-2">
                        {isSuggestedOnly(credit) && (
                          <Badge variant="outline">Suggested</Badge>
                        )}
                        {isConfirmed(credit) && (
                          <Badge variant="secondary">Confirmed</Badge>
                        )}
                        <Button
                          type="button"
                          size="sm"
                          disabled={
                            busy ||
                            chosenFor(credit) === "" ||
                            validUnits(unitsFor(credit)) === null ||
                            isConfirmed(credit)
                          }
                          aria-label={`Confirm ${creditEntryTitle(credit)}`}
                          onClick={() => void confirmRow(credit)}
                        >
                          Confirm
                        </Button>
                        <Button
                          type="button"
                          size="sm"
                          variant="outline"
                          disabled={busy}
                          aria-label={`No equivalent for ${creditEntryTitle(credit)}`}
                          onClick={() => {
                            setDeclining(credit.id)
                            setReason("")
                            setError("")
                          }}
                        >
                          No equivalent
                        </Button>
                      </div>
                      {declining === credit.id && (
                        <div className="grid gap-2">
                          <Textarea
                            id={`credit-decline-${credit.id}`}
                            aria-label={`Reason for ${creditEntryTitle(credit)}`}
                            placeholder="Why there is no equivalent. The student is told this reason."
                            value={reason}
                            onChange={(event) => setReason(event.target.value)}
                            disabled={busy}
                          />
                          <div className="flex gap-2">
                            <Button
                              type="button"
                              size="sm"
                              variant="destructive"
                              disabled={busy || !reason.trim()}
                              onClick={() => void decline(credit)}
                            >
                              Confirm decline
                            </Button>
                            <Button
                              type="button"
                              size="sm"
                              variant="outline"
                              disabled={busy}
                              onClick={() => setDeclining(null)}
                            >
                              Cancel
                            </Button>
                          </div>
                        </div>
                      )}
                    </div>
                  ) : (
                    <div className="flex flex-wrap items-center gap-2 text-sm">
                      <span>
                        {credit.subject_code
                          ? `${credit.subject_code} — ${credit.subject_title ?? ""}`
                          : "No equivalent"}
                      </span>
                      <Badge variant="secondary">{credit.status_label}</Badge>
                    </div>
                  )}
                </li>
              ))}
            </ul>
          </>
        )}

        <div className="flex flex-wrap items-center gap-2">
          <Button
            type="button"
            variant="outline"
            disabled={busy || suggestedRows.length === 0}
            onClick={() => void confirmAllSuggested()}
          >
            Confirm all suggested
          </Button>
          <Button
            type="button"
            disabled={!canSubmit}
            onClick={() => void submit()}
          >
            {submitMutation.isPending ? "Sending…" : "Submit to Registrar Head"}
          </Button>
          {pending.length > 0 && stillToConfirm > 0 && (
            <span className="text-sm text-muted-foreground">
              {stillToConfirm} still to confirm
            </span>
          )}
          <Button
            type="button"
            variant="outline"
            disabled={busy}
            onClick={onClose}
          >
            Close
          </Button>
        </div>
      </CardContent>
    </Card>
  )
}
