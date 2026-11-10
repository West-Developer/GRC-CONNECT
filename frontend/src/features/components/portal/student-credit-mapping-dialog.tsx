"use client"

import { useState } from "react"
import {
  CheckCircle2,
  FilePlus2,
  Pencil,
  School,
  Send,
  Trash2,
} from "lucide-react"

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
  DialogTrigger,
} from "@/features/components/ui/dialog"
import { Field, FieldGroup, FieldLabel } from "@/features/components/ui/field"
import { Input } from "@/features/components/ui/input"
import { StudentTorUpload } from "@/features/components/portal/tor-documents-panel"
import {
  useDeclareNoCreditMutation,
  useMyNoCreditDeclarationQuery,
} from "@/features/hooks/use-no-credit-declaration"
import {
  useCreateTransfereeCreditMutation,
  useDeleteTransfereeCreditMutation,
  useSubmitTransfereeCreditsMutation,
  useTransfereeCreditsQuery,
  useUpdateTransfereeCreditMutation,
} from "@/features/hooks/use-transferee-credits"
import type { TransfereeCredit } from "@/features/schemas/transferee-credit-schema"

function creditBadgeVariant(
  status: TransfereeCredit["status"],
): "default" | "destructive" | "outline" | "secondary" {
  if (status === "approved") return "default"
  if (status === "rejected") return "destructive"
  if (status === "endorsed") return "secondary"
  return "outline"
}

/** Where the request is in its route: the student's own words, not the API's. */
function creditStatusLabel(credit: TransfereeCredit): string {
  if (credit.status === "draft") return "Not yet sent"
  if (credit.status === "pending") return "Awaiting Program Head"
  if (credit.status === "endorsed") return "Awaiting Registrar Head"
  return credit.status_label
}

function creditTitle(credit: TransfereeCredit): string {
  return credit.source_subject_code
    ? `${credit.source_subject_code} — ${credit.source_subject_title}`
    : credit.source_subject_title
}

function creditDetails(credit: TransfereeCredit): string {
  return [
    `${credit.source_institution} · ${credit.credited_units} units`,
    credit.source_school_year ? ` · ${credit.source_school_year}` : "",
    credit.source_semester ? ` (${credit.source_semester})` : "",
    credit.source_grade ? ` · Grade: ${credit.source_grade}` : "",
    credit.subject_code ? ` → Mapped to GRC ${credit.subject_code}` : "",
  ].join("")
}

/**
 * The student's own "credit mapping" list. It always acts for the signed-in
 * student (the API pins every request to them and ignores any student id), so it
 * takes no student. The student first saves the subjects they took at another
 * school as a private list (a draft: nobody else sees it), can edit or remove
 * them, and then sends the whole list to the Program Head in one step. Mapping
 * each subject to a GRC subject is the Program Head's decision, and the
 * Registrar Head approves it (ADR 0026, amended).
 */
export function StudentCreditMappingDialog() {
  const [open, setOpen] = useState(false)
  const [editingId, setEditingId] = useState<number | null>(null)
  const [sourceInstitution, setSourceInstitution] = useState("")
  const [sourceSubjectCode, setSourceSubjectCode] = useState("")
  const [sourceSubjectTitle, setSourceSubjectTitle] = useState("")
  const [sourceGrade, setSourceGrade] = useState("")
  const [creditedUnits, setCreditedUnits] = useState("3")
  const [sourceSchoolYear, setSourceSchoolYear] = useState("")
  const [sourceSemester, setSourceSemester] = useState("")
  const [error, setError] = useState("")
  const [successMessage, setSuccessMessage] = useState("")

  const creditsQuery = useTransfereeCreditsQuery(
    { page: 1, per_page: 50 },
    { enabled: open },
  )
  const createMutation = useCreateTransfereeCreditMutation()
  const updateMutation = useUpdateTransfereeCreditMutation()
  const deleteMutation = useDeleteTransfereeCreditMutation()
  const submitMutation = useSubmitTransfereeCreditsMutation()
  const declarationQuery = useMyNoCreditDeclarationQuery({ enabled: open })
  const declareMutation = useDeclareNoCreditMutation()
  const busy =
    createMutation.isPending ||
    updateMutation.isPending ||
    deleteMutation.isPending ||
    submitMutation.isPending ||
    declareMutation.isPending

  const resetSubjectFields = () => {
    setEditingId(null)
    setSourceSubjectCode("")
    setSourceSubjectTitle("")
    setSourceGrade("")
    setSourceSchoolYear("")
    setSourceSemester("")
  }

  const startEditing = (credit: TransfereeCredit) => {
    setError("")
    setSuccessMessage("")
    setEditingId(credit.id)
    setSourceInstitution(credit.source_institution)
    setSourceSubjectCode(credit.source_subject_code)
    setSourceSubjectTitle(credit.source_subject_title)
    setSourceGrade(credit.source_grade ?? "")
    setCreditedUnits(String(credit.credited_units))
    setSourceSchoolYear(credit.source_school_year ?? "")
    setSourceSemester(credit.source_semester ?? "")
  }

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    setError("")
    setSuccessMessage("")

    if (!sourceInstitution.trim()) {
      setError("Please specify the previous school or institution.")
      return
    }
    if (!sourceSubjectTitle.trim()) {
      setError("Please specify the previous subject title.")
      return
    }
    const units = Number.parseFloat(creditedUnits)
    if (Number.isNaN(units) || units <= 0 || units > 99.9) {
      setError("Units must be more than 0 (for example 3 or 1.5).")
      return
    }
    if (!sourceSchoolYear.trim()) {
      setError("Please enter the school year you took the subject.")
      return
    }
    if (!sourceSemester.trim()) {
      setError("Please enter the semester you took the subject.")
      return
    }

    try {
      if (editingId !== null) {
        await updateMutation.mutateAsync({
          id: editingId,
          input: {
            source_institution: sourceInstitution.trim(),
            source_subject_code: sourceSubjectCode.trim().toUpperCase(),
            source_subject_title: sourceSubjectTitle.trim(),
            source_grade: sourceGrade.trim() || null,
            credited_units: units,
            source_school_year: sourceSchoolYear.trim(),
            source_semester: sourceSemester.trim(),
          },
        })
        setSuccessMessage("Changes saved to your list.")
      } else {
        await createMutation.mutateAsync({
          source_institution: sourceInstitution.trim(),
          source_subject_code:
            sourceSubjectCode.trim().toUpperCase() || undefined,
          source_subject_title: sourceSubjectTitle.trim(),
          source_grade: sourceGrade.trim() || undefined,
          credited_units: units,
          source_school_year: sourceSchoolYear.trim(),
          source_semester: sourceSemester.trim(),
        })
        setSuccessMessage(
          "Saved to your list. It is not sent to your Program Head until you press Submit to Program Head.",
        )
      }
      resetSubjectFields()
    } catch (err) {
      setError(
        err instanceof Error
          ? err.message
          : "Failed to save the subject. Please try again.",
      )
    }
  }

  const handleRemove = async (credit: TransfereeCredit) => {
    setError("")
    setSuccessMessage("")
    try {
      await deleteMutation.mutateAsync(credit.id)
      if (editingId === credit.id) resetSubjectFields()
    } catch (err) {
      setError(
        err instanceof Error
          ? err.message
          : "Failed to remove the subject. Please try again.",
      )
    }
  }

  const handleSendList = async () => {
    setError("")
    setSuccessMessage("")
    try {
      await submitMutation.mutateAsync()
      resetSubjectFields()
      setSuccessMessage(
        "Your list was sent to your Program Head. They will map each subject to your curriculum, and the Registrar Head approves it after that.",
      )
    } catch (err) {
      setError(
        err instanceof Error
          ? err.message
          : "Failed to send your list. Please try again.",
      )
    }
  }

  const handleDeclareNoCredit = async () => {
    setError("")
    setSuccessMessage("")
    try {
      await declareMutation.mutateAsync()
      setSuccessMessage(
        "Your Program Head was told you have no subject to credit. Once they confirm it, you can go on to enroll.",
      )
    } catch (err) {
      setError(
        err instanceof Error
          ? err.message
          : "Failed to send this to your Program Head. Please try again.",
      )
    }
  }

  const allCredits = creditsQuery.data?.data ?? []
  const noCreditDeclaration = declarationQuery.data ?? null
  // Only a student with nothing left that counts can say they have none to credit: no subject in the list, or
  // every one of them rejected by the Program Head.
  const hasNothingLeft = allCredits.every(
    (credit) => credit.status === "rejected",
  )
  const canDeclareNoCredit = creditsQuery.isSuccess && hasNothingLeft
  const drafts = allCredits.filter((credit) => credit.status === "draft")
  const sentCredits = allCredits.filter((credit) => credit.status !== "draft")

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>
        <Button type="button" variant="outline" size="sm">
          <FilePlus2 className="mr-1.5 size-4" />
          Request Credit Mapping
        </Button>
      </DialogTrigger>
      <DialogContent className="max-h-[90dvh] max-w-2xl overflow-y-auto sm:max-w-2xl">
        <DialogHeader>
          <div className="flex items-center gap-2">
            <School className="size-5 text-primary" />
            <DialogTitle>Transferee Credit Mapping</DialogTitle>
          </div>
          <DialogDescription>
            List the subjects you completed at another college or university,
            then send the whole list to your Program Head. Your Program Head
            maps each one to a subject in your GRC curriculum, and the Registrar
            Head approves it.
          </DialogDescription>
        </DialogHeader>

        {error && (
          <Alert variant="destructive">
            <AlertDescription>{error}</AlertDescription>
          </Alert>
        )}

        {successMessage && (
          <Alert className="border-emerald-600/30 bg-emerald-50 text-emerald-900 dark:bg-emerald-950/20 dark:text-emerald-200">
            <CheckCircle2 className="size-4 text-emerald-600" />
            <AlertDescription>{successMessage}</AlertDescription>
          </Alert>
        )}

        {(canDeclareNoCredit || noCreditDeclaration !== null) &&
          hasNothingLeft && (
            <section
              aria-labelledby="no-credit-heading"
              className="grid gap-2 rounded-lg border p-3"
            >
              <h4
                id="no-credit-heading"
                className="text-sm font-semibold text-foreground"
              >
                Nothing to credit?
              </h4>
              {noCreditDeclaration === null ? (
                <>
                  <p className="text-sm text-muted-foreground">
                    If you have no subject from another school to credit, tell
                    your Program Head. They review it, and then you can go on to
                    enroll.
                  </p>
                  <div>
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      disabled={busy || declarationQuery.isPending}
                      onClick={() => void handleDeclareNoCredit()}
                    >
                      I have no subject to credit
                    </Button>
                  </div>
                </>
              ) : noCreditDeclaration.status === "pending" ? (
                <p className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                  <Badge variant="outline">Awaiting Program Head</Badge>
                  You told your Program Head you have no subject to credit. You
                  can enroll once they confirm it.
                </p>
              ) : (
                <p className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                  <Badge variant="secondary">Confirmed</Badge>
                  Your Program Head confirmed that you have no subject to
                  credit. You can go on to enroll.
                </p>
              )}
            </section>
          )}

        <StudentTorUpload enabled={open} />

        <form
          onSubmit={(event) => void handleSubmit(event)}
          className="grid gap-4 py-2"
        >
          <FieldGroup className="grid gap-3 sm:grid-cols-2">
            <Field className="sm:col-span-2">
              <FieldLabel htmlFor="source-institution">
                Previous School / Institution
              </FieldLabel>
              <Input
                id="source-institution"
                placeholder="e.g. University of the East, TIP, UST"
                value={sourceInstitution}
                onChange={(e) => setSourceInstitution(e.target.value)}
                disabled={busy}
              />
            </Field>

            <Field>
              <FieldLabel htmlFor="source-subject-code">
                Previous Subject Code (optional)
              </FieldLabel>
              <Input
                id="source-subject-code"
                placeholder="e.g. CS101, ENG01"
                value={sourceSubjectCode}
                onChange={(e) => setSourceSubjectCode(e.target.value)}
                disabled={busy}
              />
            </Field>

            <Field>
              <FieldLabel htmlFor="source-units">Units</FieldLabel>
              <Input
                id="source-units"
                type="number"
                min="0.5"
                max="99.9"
                step="0.5"
                value={creditedUnits}
                onChange={(e) => setCreditedUnits(e.target.value)}
                disabled={busy}
              />
            </Field>

            <Field className="sm:col-span-2">
              <FieldLabel htmlFor="source-subject-title">
                Subject Title / Description
              </FieldLabel>
              <Input
                id="source-subject-title"
                placeholder="e.g. Introduction to Computer Science"
                value={sourceSubjectTitle}
                onChange={(e) => setSourceSubjectTitle(e.target.value)}
                disabled={busy}
              />
            </Field>

            <Field>
              <FieldLabel htmlFor="source-school-year">School Year</FieldLabel>
              <Input
                id="source-school-year"
                placeholder="e.g. 2023-2024"
                value={sourceSchoolYear}
                onChange={(e) => setSourceSchoolYear(e.target.value)}
                disabled={busy}
              />
            </Field>

            <Field>
              <FieldLabel htmlFor="source-semester">Semester</FieldLabel>
              <Input
                id="source-semester"
                placeholder="e.g. 1st Semester, 2nd Semester"
                value={sourceSemester}
                onChange={(e) => setSourceSemester(e.target.value)}
                disabled={busy}
              />
            </Field>

            <Field>
              <FieldLabel htmlFor="source-grade">
                Grade Obtained (optional)
              </FieldLabel>
              <Input
                id="source-grade"
                placeholder="e.g. 1.25, 88%, A"
                value={sourceGrade}
                onChange={(e) => setSourceGrade(e.target.value)}
                disabled={busy}
              />
            </Field>
          </FieldGroup>

          <div className="mt-2 flex flex-wrap gap-2">
            <Button type="submit" disabled={busy}>
              {editingId !== null ? "Save Changes" : "Add Subject to My List"}
            </Button>
            {editingId !== null && (
              <Button
                type="button"
                variant="outline"
                disabled={busy}
                onClick={resetSubjectFields}
              >
                Cancel edit
              </Button>
            )}
          </div>
        </form>

        {drafts.length > 0 && (
          <div className="mt-4 border-t pt-4">
            <h4 className="text-sm font-semibold text-foreground mb-2">
              Your list (not yet sent) ({drafts.length})
            </h4>
            <div className="grid gap-2">
              {drafts.map((credit) => (
                <div
                  key={credit.id}
                  className="flex items-center justify-between gap-2 rounded-lg border p-2.5 text-xs"
                >
                  <div className="grid gap-0.5">
                    <span className="font-semibold text-foreground">
                      {creditTitle(credit)}
                    </span>
                    <span className="text-muted-foreground">
                      {creditDetails(credit)}
                    </span>
                  </div>
                  <div className="flex shrink-0 gap-1">
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      disabled={busy}
                      aria-label={`Edit ${credit.source_subject_title}`}
                      onClick={() => startEditing(credit)}
                    >
                      <Pencil className="size-3.5" />
                    </Button>
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      disabled={busy}
                      aria-label={`Remove ${credit.source_subject_title}`}
                      onClick={() => void handleRemove(credit)}
                    >
                      <Trash2 className="size-3.5" />
                    </Button>
                  </div>
                </div>
              ))}
            </div>
            <Button
              type="button"
              className="mt-3"
              disabled={busy}
              onClick={() => void handleSendList()}
            >
              <Send className="mr-1.5 size-4" />
              Submit to Program Head
            </Button>
          </div>
        )}

        {sentCredits.length > 0 && (
          <div className="mt-4 border-t pt-4">
            <h4 className="text-sm font-semibold text-foreground mb-2">
              Your Credited & Pending Subjects
            </h4>
            <div className="grid gap-2">
              {sentCredits.map((credit) => (
                <div
                  key={credit.id}
                  className="flex items-center justify-between rounded-lg border p-2.5 text-xs"
                >
                  <div className="grid gap-0.5">
                    <span className="font-semibold text-foreground">
                      {creditTitle(credit)}
                    </span>
                    <span className="text-muted-foreground">
                      {creditDetails(credit)}
                    </span>
                  </div>
                  <Badge variant={creditBadgeVariant(credit.status)}>
                    {creditStatusLabel(credit)}
                  </Badge>
                </div>
              ))}
            </div>
          </div>
        )}

        <DialogFooter className="mt-4 border-t pt-3">
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={() => setOpen(false)}
          >
            Close
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
