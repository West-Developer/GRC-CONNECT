"use client"

import { useMemo, useRef, useState } from "react"
import {
  ArrowLeft,
  BookOpenText,
  CalendarDays,
  CheckCircle2,
  ChevronRight,
  Clock3,
  Download,
  FolderOpen,
  History,
  Save,
  Send,
  ShieldCheck,
  Upload,
  UsersRound,
} from "lucide-react"
import { toast } from "sonner"

import { useAuth } from "@/features/auth/use-auth"
import { AsyncBoundary } from "@/features/components/portal/async-boundary"
import {
  DataTable,
  type DataTableColumn,
} from "@/features/components/portal/data-table"
import { WorkspacePage } from "@/features/components/portal/workspace-page"
import {
  Alert,
  AlertDescription,
  AlertTitle,
} from "@/features/components/ui/alert"
import {
  AlertDialog,
  AlertDialogAction,
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
  CardFooter,
  CardHeader,
  CardTitle,
} from "@/features/components/ui/card"
import { Input } from "@/features/components/ui/input"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/features/components/ui/dialog"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/features/components/ui/select"
import {
  useGradeSubmissionSectionsQuery,
  useSaveSectionGradeDraftsMutation,
  useSectionGradeSheetQuery,
  useSubmitSectionGradesMutation,
} from "@/features/hooks/use-section-grades"
import {
  allowedMarksForSubject,
  gradeMarkLabel,
  type GradeMarkValue,
} from "@/features/lib/grade-presentation"
import {
  csvToRows,
  parseGradesRows,
  type ParsedGradesImport,
} from "@/features/lib/grades-import"
import { readXlsxRows, XlsxReadError } from "@/features/lib/read-xlsx"
import { cn } from "@/features/lib/utils"
import { gradeMarkValues } from "@/features/schemas/academic-grade-schema"
import type {
  GradeSectionSummary,
  SectionGradeRow,
  SectionGradeSheet,
} from "@/features/schemas/section-grade-schema"

interface DraftGrade {
  mark: string
  remarks: string
}

function isGradeMark(value: string): value is GradeMarkValue {
  return (gradeMarkValues as readonly string[]).includes(value)
}

function formatClock(value: string): string {
  const [hours = "0", minutes = "00"] = value.split(":")
  const hour = Number(hours)
  const period = hour >= 12 ? "PM" : "AM"
  const twelveHour = hour % 12 || 12

  return `${String(twelveHour).padStart(2, "0")}:${minutes} ${period}`
}

function scheduleLabel(section: GradeSectionSummary): string {
  const {
    days,
    starts_at_time: startsAt,
    ends_at_time: endsAt,
  } = section.schedule

  if (!days || !startsAt || !endsAt) return "Schedule to be announced"
  return `${days} · ${formatClock(startsAt)}–${formatClock(endsAt)}`
}

const stateLabel: Record<GradeSectionSummary["state"], string> = {
  not_started: "Not started",
  in_progress: "In progress",
  ready: "Ready to submit",
  submitted: "Awaiting Registrar",
  locked: "Locked",
}

/** What the older CSV-only importer returned; the shared importer returns the same fields and more. */
export type ParsedCsvGradesResult = ParsedGradesImport

export function parseGradesCsv(
  csvText: string,
  sheet: SectionGradeSheet,
  allowedMarks: readonly GradeMarkValue[],
  existingDrafts: Record<number, DraftGrade> = {},
): ParsedCsvGradesResult {
  return parseGradesRows(csvToRows(csvText), sheet, allowedMarks, existingDrafts)
}

export function downloadCsvTemplate(
  sheet: SectionGradeSheet,
  drafts: Record<number, DraftGrade>,
) {
  const headers = ["Student Number", "Student Name", "Grade", "Remarks"]
  const rows = sheet.rows.map((row) => {
    const draft = drafts[row.student_id]
    const mark = draft?.mark || row.mark || ""
    const remarks = draft?.remarks || row.remarks || ""
    const escapeCsv = (val: string) => `"${val.replace(/"/g, '""')}"`
    return [
      escapeCsv(row.student_number),
      escapeCsv(row.student_name),
      escapeCsv(mark),
      escapeCsv(remarks),
    ].join(",")
  })

  const csvContent = [headers.join(","), ...rows].join("\r\n")
  const blob = new Blob([csvContent], { type: "text/csv;charset=utf-8;" })
  const url = URL.createObjectURL(blob)
  const link = document.createElement("a")
  link.setAttribute("href", url)
  const filename = `grades_${sheet.section.subject.code}_section_${sheet.section.section_code}.csv`
    .toLowerCase()
    .replace(/[^a-z0-9_.-]/g, "_")
  link.setAttribute("download", filename)
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
  URL.revokeObjectURL(url)
}

function GradeSectionCard({
  section,
  selected,
  onSelect,
}: {
  section: GradeSectionSummary
  selected: boolean
  onSelect: () => void
}) {
  const progress =
    section.enrolled_count === 0
      ? 0
      : Math.round((section.recorded_count / section.enrolled_count) * 100)

  return (
    <button
      type="button"
      aria-pressed={selected}
      onClick={onSelect}
      className={cn(
        "group relative overflow-hidden rounded-xl border bg-card p-4 text-left shadow-xs transition duration-200",
        "hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2",
        selected && "border-primary/60 ring-2 ring-primary/15",
      )}
    >
      <span
        aria-hidden="true"
        className={cn(
          "absolute inset-y-0 left-0 w-1 bg-muted transition-colors",
          selected || section.state === "ready"
            ? "bg-primary"
            : section.state === "locked"
              ? "bg-emerald-600"
              : "group-hover:bg-primary/50",
        )}
      />
      <span className="flex items-start justify-between gap-3 pl-1">
        <span className="min-w-0">
          <span className="flex items-center gap-2">
            <BookOpenText
              className="size-4 shrink-0 text-primary"
              aria-hidden
            />
            <span className="font-mono text-xs font-semibold tracking-[0.08em] text-primary">
              {section.subject.code}
            </span>
          </span>
          <span className="mt-1 block font-heading text-lg leading-tight font-semibold text-foreground">
            {section.subject.title}
          </span>
          <span className="mt-1 block text-xs font-medium text-muted-foreground">
            Section {section.section_code}
          </span>
        </span>
        <Badge
          variant={section.state === "locked" ? "default" : "outline"}
          className="shrink-0"
        >
          {stateLabel[section.state]}
        </Badge>
      </span>

      <span className="mt-4 grid gap-2 border-y py-3 text-xs text-muted-foreground sm:grid-cols-2">
        <span className="flex items-center gap-2">
          <CalendarDays className="size-3.5" aria-hidden />
          {section.academic_term.school_year} · {section.academic_term.semester}
        </span>
        <span className="flex items-center gap-2 sm:justify-end">
          <Clock3 className="size-3.5" aria-hidden />
          {scheduleLabel(section)}
        </span>
      </span>

      <span className="mt-3 block">
        <span className="flex items-center justify-between gap-3 text-xs">
          <span className="flex items-center gap-1.5 text-muted-foreground">
            <UsersRound className="size-3.5" aria-hidden />
            {section.enrolled_count} enrolled
          </span>
          <span className="flex items-center gap-1.5 font-semibold text-foreground">
            {section.recorded_count === section.enrolled_count &&
              section.enrolled_count > 0 && (
                <CheckCircle2
                  className="size-3.5 text-emerald-600"
                  aria-hidden
                />
              )}
            {section.recorded_count} / {section.enrolled_count} recorded
          </span>
        </span>
        <span
          role="progressbar"
          aria-label={`${section.subject.code} grading progress`}
          aria-valuemin={0}
          aria-valuemax={100}
          aria-valuenow={progress}
          className="mt-2 block h-1.5 overflow-hidden rounded-full bg-muted"
        >
          <span
            className="block h-full rounded-full bg-primary transition-[width] duration-300"
            style={{ width: `${progress}%` }}
          />
        </span>
      </span>
    </button>
  )
}

function rowBadgeVariant(
  status: SectionGradeRow["status"],
): "default" | "secondary" | "outline" {
  if (status === "locked") return "default"
  if (status === "submitted") return "secondary"
  return "outline"
}

export function SectionGradeSheetPanel({ sectionId }: { sectionId: number }) {
  const sheetQuery = useSectionGradeSheetQuery(sectionId)
  const saveMutation = useSaveSectionGradeDraftsMutation(sectionId)
  const submitMutation = useSubmitSectionGradesMutation(sectionId)
  const [drafts, setDrafts] = useState<Record<number, DraftGrade>>({})
  const [error, setError] = useState("")
  const [confirmationOpen, setConfirmationOpen] = useState(false)
  const fileInputRef = useRef<HTMLInputElement>(null)

  const importRows = (rows: string[][], loadedSheet: SectionGradeSheet) => {
    const result = parseGradesRows(rows, loadedSheet, allowedMarks, drafts)
    if (result.matchedCount > 0) {
      setDrafts(result.drafts)
      toast.success(
        `Imported grades for ${result.matchedCount} student(s). Click "Save draft" or "Submit final grades" when ready.`,
      )
    } else {
      toast.error(
        result.isGradingSheet
          ? "No final grades were found in this grading sheet. Fill in its Semester Numeric Grade (or Numeric Grade) column, and check that it is this class's sheet."
          : "No valid student grades could be matched from the file.",
      )
    }

    if (result.invalidCount > 0) {
      toast.warning(
        `${result.invalidCount} grade(s) had invalid marks (a Drop or DRP, a percentage, or a typo) and were skipped.`,
      )
    }
    if (result.unmatchedCount > 0) {
      toast.warning(
        `${result.unmatchedCount} row(s) are not in this class list and were skipped${
          result.unmatchedNames.length > 0
            ? `: ${result.unmatchedNames.join(", ")}${result.unmatchedCount > result.unmatchedNames.length ? "…" : ""}`
            : ""
        }.`,
      )
    }
    if (result.matchedByNameCount > 0) {
      toast.warning(
        `${result.matchedByNameCount} student(s) were matched by name because their ID did not match. Check them before you submit.`,
      )
    }
    if (result.sheetScheduleId !== null && result.sheetScheduleId !== sectionId) {
      toast.warning(
        `This sheet says Schedule ID ${result.sheetScheduleId}, but you opened section ${sectionId}. Make sure it is the right class.`,
      )
    }
  }

  const handleUploadGrades = (
    event: React.ChangeEvent<HTMLInputElement>,
    loadedSheet: SectionGradeSheet,
  ) => {
    const file = event.target.files?.[0]
    if (!file) return
    event.target.value = ""

    const name = file.name.toLowerCase()
    if (name.endsWith(".xls")) {
      toast.error(
        "That is an old .xls file. Save it as Excel Workbook (.xlsx) or CSV, then upload it again.",
      )
      return
    }

    const reader = new FileReader()
    if (name.endsWith(".xlsx") || file.type.includes("spreadsheetml")) {
      reader.onload = (e) => {
        const buffer = e.target?.result
        if (!buffer || typeof buffer === "string") return
        readXlsxRows(buffer)
          .then((rows) => importRows(rows, loadedSheet))
          .catch((error: unknown) =>
            toast.error(
              error instanceof XlsxReadError
                ? error.message
                : "The Excel file could not be read. Save the sheet as CSV and try again.",
            ),
          )
      }
      reader.readAsArrayBuffer(file)
    } else {
      reader.onload = (e) => {
        const text = e.target?.result
        if (typeof text !== "string" || !text) return
        importRows(csvToRows(text), loadedSheet)
      }
      reader.readAsText(file)
    }
  }

  const sheet = sheetQuery.data
  const readOnly =
    sheet?.section.state === "submitted" || sheet?.section.state === "locked"
  const allowedMarks = allowedMarksForSubject(
    sheet?.section.subject.is_completion_only ?? false,
  )

  const draftFor = (row: SectionGradeRow): DraftGrade =>
    drafts[row.student_id] ?? {
      mark: row.mark ?? "",
      remarks: row.remarks ?? "",
    }

  const setDraft = (row: SectionGradeRow, next: Partial<DraftGrade>) => {
    setDrafts((current) => ({
      ...current,
      [row.student_id]: { ...draftFor(row), ...next },
    }))
  }

  const changedRows = useMemo(() => {
    if (!sheet) return []

    return sheet.rows.filter((row) => {
      const draft = drafts[row.student_id]
      const isLockedInc = row.status === "locked" && row.mark === "INC"
      if (!draft) return false
      if (row.status === "submitted") return false
      if (row.status === "locked" && !isLockedInc) return false

      return (
        draft.mark !== (row.mark ?? "") ||
        draft.remarks.trim() !== (row.remarks ?? "")
      )
    })
  }, [drafts, sheet])

  const savableRows = changedRows.filter((row) =>
    isGradeMark(draftFor(row).mark),
  )
  const allComplete =
    (sheet?.rows.length ?? 0) > 0 &&
    (sheet?.rows.every((row) => {
      const mark = draftFor(row).mark
      return isGradeMark(mark) && allowedMarks.includes(mark)
    }) ??
      false)
  const pending = saveMutation.isPending || submitMutation.isPending

  const saveRows = async (rows: readonly SectionGradeRow[]) => {
    if (rows.length === 0) return

    await saveMutation.mutateAsync({
      grades: rows.map((row) => {
        const draft = draftFor(row)
        return {
          student_id: row.student_id,
          mark: draft.mark as GradeMarkValue,
          remarks: draft.remarks.trim() || null,
        }
      }),
    })
  }

  const saveDraft = async () => {
    setError("")
    try {
      await saveRows(savableRows)
      setDrafts({})
    } catch {
      setError(
        "The draft could not be saved. Your unsaved grades are still here; check the roster or connection and try again.",
      )
    }
  }

  const submitFinal = async () => {
    setError("")
    try {
      await saveRows(savableRows)
      await submitMutation.mutateAsync()
      setDrafts({})
    } catch {
      setError(
        "Final grades could not be submitted. Your unsaved grades are still here; review the roster and try again.",
      )
    }
  }

  const columns: DataTableColumn<SectionGradeRow>[] = [
    {
      key: "student_name",
      header: "Student",
      render: (row) => (
        <span className="font-medium text-foreground">{row.student_name}</span>
      ),
    },
    {
      key: "student_number",
      header: "Student number",
      render: (row) => (
        <span className="font-mono text-xs">{row.student_number}</span>
      ),
    },
    {
      key: "mark",
      header: "Grade",
      cellClassName: "min-w-48",
      render: (row) => {
        const draft = draftFor(row)
        const isLockedInc = row.status === "locked" && row.mark === "INC"
        const editable =
          (!readOnly && row.status !== "submitted" && row.status !== "locked") ||
          isLockedInc

        if (!editable) {
          return row.mark ? `${row.mark} — ${row.mark_label ?? ""}` : "—"
        }

        return (
          <Select
            value={draft.mark}
            disabled={pending}
            onValueChange={(mark) => setDraft(row, { mark })}
          >
            <SelectTrigger
              aria-label={`Grade for ${row.student_name}`}
              className="w-full"
            >
              <SelectValue placeholder="Select grade" />
            </SelectTrigger>
            <SelectContent>
              {allowedMarks.map((mark) => (
                <SelectItem key={mark} value={mark}>
                  {mark} — {gradeMarkLabel[mark]}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        )
      },
    },
    {
      key: "remarks",
      header: "Remarks",
      cellClassName: "min-w-56",
      render: (row) => {
        const isLockedInc = row.status === "locked" && row.mark === "INC"
        const editable =
          (!readOnly && row.status !== "submitted" && row.status !== "locked") ||
          isLockedInc

        if (!editable) return row.remarks ?? "—"

        return (
          <Input
            aria-label={`Remarks for ${row.student_name}`}
            value={draftFor(row).remarks}
            disabled={pending}
            placeholder={isLockedInc ? "e.g. Completed via exam" : "Optional"}
            onChange={(event) => setDraft(row, { remarks: event.target.value })}
          />
        )
      },
    },
    {
      key: "status",
      header: "Status",
      render: (row) => {
        if (row.status === "locked" && row.mark === "INC") {
          return (
            <Badge
              variant="outline"
              className="border-amber-300 bg-amber-50 text-amber-700 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-300"
            >
              INC · Awaiting completion
            </Badge>
          )
        }
        return <Badge variant={rowBadgeVariant(row.status)}>{row.status_label}</Badge>
      },
    },
  ]

  return (
    <Card className="border-primary/10">
      <AsyncBoundary
        query={sheetQuery}
        loadingLabel="Loading the section grade sheet…"
      >
        {(loadedSheet: SectionGradeSheet) => (
          <>
            <CardHeader className="border-b pb-4">
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                  <p className="font-mono text-xs font-semibold tracking-[0.08em] text-primary">
                    {loadedSheet.section.subject.code} · Section{" "}
                    {loadedSheet.section.section_code}
                  </p>
                  <CardTitle level={2} className="mt-1 text-xl">
                    {loadedSheet.section.subject.title}
                  </CardTitle>
                  <p className="mt-1 text-xs text-muted-foreground">
                    {loadedSheet.section.academic_term.school_year} ·{" "}
                    {loadedSheet.section.academic_term.semester} ·{" "}
                    {scheduleLabel(loadedSheet.section)}
                  </p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                  {!readOnly && loadedSheet.rows.length > 0 && (
                    <>
                      <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="h-8 gap-1.5 text-xs"
                        onClick={() => downloadCsvTemplate(loadedSheet, drafts)}
                      >
                        <Download className="size-3.5" aria-hidden />
                        Download CSV Template
                      </Button>
                      <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="h-8 gap-1.5 text-xs"
                        onClick={() => fileInputRef.current?.click()}
                      >
                        <Upload className="size-3.5" aria-hidden />
                        Upload Grades (Excel or CSV)
                      </Button>
                      <input
                        ref={fileInputRef}
                        type="file"
                        accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                        className="hidden"
                        aria-label="Upload grade sheet (Excel or CSV)"
                        onChange={(e) => handleUploadGrades(e, loadedSheet)}
                      />
                    </>
                  )}
                  <Badge
                    variant={
                      loadedSheet.section.state === "locked"
                        ? "default"
                        : "outline"
                    }
                  >
                    {stateLabel[loadedSheet.section.state]}
                  </Badge>
                </div>
              </div>
            </CardHeader>
            <CardContent className="grid gap-4">
              {error && (
                <Alert variant="destructive">
                  <AlertTitle>Grade sheet not saved</AlertTitle>
                  <AlertDescription>{error}</AlertDescription>
                </Alert>
              )}
              {readOnly && (
                <Alert>
                  <ShieldCheck aria-hidden />
                  <AlertTitle>
                    {loadedSheet.section.state === "locked"
                      ? "Official grades locked"
                      : "Final grades submitted"}
                  </AlertTitle>
                  <AlertDescription>
                    {loadedSheet.section.state === "locked"
                      ? "This grade sheet is part of the official academic record. Any students with an Incomplete (INC) grade remain editable to record completion grades."
                      : "This complete grade sheet is read-only while awaiting Registrar Head locking."}
                  </AlertDescription>
                </Alert>
              )}
              <DataTable
                caption="Section grade sheet"
                rows={loadedSheet.rows}
                rowKey={(row) => row.enrollment_subject_id}
                columns={columns}
                renderCard={(row) => (
                  <GradeSheetCard
                    row={row}
                    cell={(key) => columns.find((column) => column.key === key)?.render(row)}
                  />
                )}
                emptyMessage="No enrolled students are in this section yet."
              />
            </CardContent>
            {readOnly && savableRows.length > 0 && (
              <CardFooter className="flex-col items-stretch justify-between gap-3 border-t pt-4 sm:flex-row sm:items-center">
                <p className="text-xs text-muted-foreground">
                  {savableRows.length} completion grade(s) ready to be saved.
                </p>
                <div className="flex flex-col gap-2 sm:flex-row">
                  <Button
                    type="button"
                    disabled={pending}
                    onClick={() => void saveDraft()}
                  >
                    <Save aria-hidden />
                    {saveMutation.isPending ? "Saving completion…" : "Save completion grade"}
                  </Button>
                </div>
              </CardFooter>
            )}
            {!readOnly && loadedSheet.rows.length > 0 && (
              <CardFooter className="flex-col items-stretch justify-between gap-3 sm:flex-row sm:items-center">
                <p className="text-xs text-muted-foreground">
                  {allComplete
                    ? "Every enrolled student has a valid grade."
                    : `${loadedSheet.rows.filter((row) => !isGradeMark(draftFor(row).mark)).length} student(s) still need a grade.`}
                </p>
                <div className="flex flex-col gap-2 sm:flex-row">
                  <Button
                    type="button"
                    variant="outline"
                    disabled={pending || savableRows.length === 0}
                    onClick={() => void saveDraft()}
                  >
                    <Save aria-hidden />
                    {saveMutation.isPending ? "Saving…" : "Save draft"}
                  </Button>
                  <Button
                    type="button"
                    disabled={pending || !allComplete}
                    onClick={() => setConfirmationOpen(true)}
                  >
                    <Send aria-hidden />
                    Submit final grades
                  </Button>
                </div>
              </CardFooter>
            )}

            <AlertDialog
              open={confirmationOpen}
              onOpenChange={setConfirmationOpen}
            >
              <AlertDialogContent>
                <AlertDialogHeader>
                  <AlertDialogTitle>Submit final grades?</AlertDialogTitle>
                  <AlertDialogDescription>
                    This submits the entire enrolled roster. After submission,
                    you can no longer edit this grade sheet while it awaits
                    Registrar Head locking.
                  </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                  <AlertDialogCancel>Review again</AlertDialogCancel>
                  <AlertDialogAction onClick={() => void submitFinal()}>
                    Submit section
                  </AlertDialogAction>
                </AlertDialogFooter>
              </AlertDialogContent>
            </AlertDialog>
          </>
        )}
      </AsyncBoundary>
    </Card>
  )
}

interface AssignedClassesContentProps {
  sections: readonly GradeSectionSummary[]
  viewingHistory: boolean
  selectedHistoryTermId: number | null
  sectionId: number | null
  onOpenHistory: () => void
  onCloseHistory: () => void
  onSelectHistoryTerm: (id: number) => void
  onSelectSection: (id: number) => void
}

function AssignedClassesContent({
  sections,
  viewingHistory,
  selectedHistoryTermId,
  sectionId,
  onOpenHistory,
  onCloseHistory,
  onSelectHistoryTerm,
  onSelectSection,
}: AssignedClassesContentProps) {
  // Current term is determined by highest/latest school year and term ID
  const currentTermId = useMemo(() => {
    if (sections.length === 0) return null
    const sorted = [...sections].sort((a, b) => {
      const syCmp = b.academic_term.school_year.localeCompare(a.academic_term.school_year)
      if (syCmp !== 0) return syCmp
      return b.academic_term.id - a.academic_term.id
    })
    return sorted[0].academic_term.id
  }, [sections])

  const currentSections = useMemo(() => {
    if (currentTermId === null) return sections
    return sections.filter((s) => s.academic_term.id === currentTermId)
  }, [sections, currentTermId])

  const historySections = useMemo(() => {
    if (currentTermId === null) return []
    return sections.filter((s) => s.academic_term.id !== currentTermId)
  }, [sections, currentTermId])

  // Build a School Year → historical semesters map
  const historySchoolYearMap = useMemo(() => {
    const syMap = new Map<
      string,
      Map<number, { id: number; label: string; semester: string; count: number }>
    >()
    for (const s of historySections) {
      const term = s.academic_term
      const sy = term.school_year
      if (!syMap.has(sy)) syMap.set(sy, new Map())
      const semMap = syMap.get(sy)!
      const existing = semMap.get(term.id)
      if (existing) {
        existing.count += 1
      } else {
        semMap.set(term.id, {
          id: term.id,
          label: `${sy} · ${term.semester}`,
          semester: term.semester,
          count: 1,
        })
      }
    }
    return Array.from(syMap.entries()).sort(([a], [b]) => b.localeCompare(a))
  }, [historySections])

  const selectedHistoryTermLabel = useMemo(() => {
    if (selectedHistoryTermId === null) return null
    for (const [, semMap] of historySchoolYearMap) {
      const found = semMap.get(selectedHistoryTermId)
      if (found) return found.label
    }
    return null
  }, [selectedHistoryTermId, historySchoolYearMap])

  const filteredHistorySections =
    selectedHistoryTermId === null
      ? []
      : historySections.filter((s) => s.academic_term.id === selectedHistoryTermId)

  // === HISTORICAL SEMESTERS: FOLDER SELECTION ===
  if (viewingHistory && selectedHistoryTermId === null) {
    return (
      <div className="space-y-5">
        <div
          className="space-y-5"
          role="list"
          aria-label="Previous school years and semesters"
        >
          {historySchoolYearMap.map(([schoolYear, semMap]) => {
            const semesters = Array.from(semMap.values()).sort((a, b) =>
              a.semester.localeCompare(b.semester),
            )
            return (
              <div key={schoolYear} role="listitem">
                <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                  {schoolYear}
                </p>
                <div className="flex flex-col gap-2">
                  {semesters.map((term) => (
                    <button
                      key={term.id}
                      type="button"
                      className="group flex w-full items-center justify-between rounded-xl border bg-card px-4 py-3 text-left shadow-xs transition duration-200 hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                      onClick={() => onSelectHistoryTerm(term.id)}
                    >
                      <span className="flex items-center gap-3">
                        <FolderOpen
                          className="size-5 shrink-0 text-primary"
                          aria-hidden
                        />
                        <span>
                          <span className="block text-sm font-semibold text-foreground">
                            {term.semester} Semester
                          </span>
                          <span className="block text-xs text-muted-foreground">
                            {term.count}{" "}
                            {term.count === 1 ? "class" : "classes"}
                          </span>
                        </span>
                      </span>
                      <ChevronRight
                        className="size-4 shrink-0 text-muted-foreground transition-colors group-hover:text-primary"
                        aria-hidden
                      />
                    </button>
                  ))}
                </div>
              </div>
            )
          })}
        </div>

        {/* Lower Right Return Button */}
        <div className="flex justify-end pt-3">
          <Button
            type="button"
            variant="outline"
            className="portal-module-page__return"
            onClick={onCloseHistory}
          >
            <ArrowLeft className="size-4" data-icon="inline-start" aria-hidden="true" />
            Return to Current Semester
          </Button>
        </div>
      </div>
    )
  }

  // === HISTORICAL SEMESTERS: CLASSES LIST FOR SELECTED PAST SEMESTER ===
  if (viewingHistory && selectedHistoryTermId !== null) {
    return (
      <div className="space-y-4">
        {selectedHistoryTermLabel && (
          <div
            className="flex items-center gap-2 rounded-lg border bg-primary/5 px-3 py-2"
            aria-label="Filtered past semester"
            role="group"
          >
            <FolderOpen className="size-4 shrink-0 text-primary" aria-hidden />
            <span className="text-sm font-semibold text-primary">
              {selectedHistoryTermLabel}
            </span>
            <Badge
              variant="secondary"
              className="ml-auto px-1.5 py-0 text-[10px]"
            >
              {filteredHistorySections.length}
            </Badge>
          </div>
        )}

        {filteredHistorySections.length === 0 ? (
          <p className="text-sm text-muted-foreground">
            No classes found in this semester folder.
          </p>
        ) : (
          <div className="grid gap-3 xl:grid-cols-2">
            {filteredHistorySections.map((section) => (
              <GradeSectionCard
                key={section.section_id}
                section={section}
                selected={section.section_id === sectionId}
                onSelect={() => onSelectSection(section.section_id)}
              />
            ))}
          </div>
        )}

        {/* Lower Right Return Button */}
        <div className="flex justify-end pt-3">
          <Button
            type="button"
            variant="outline"
            className="portal-module-page__return"
            onClick={onCloseHistory}
          >
            <ArrowLeft className="size-4" data-icon="inline-start" aria-hidden="true" />
            Return to Current Semester
          </Button>
        </div>
      </div>
    )
  }

  // === DEFAULT: CURRENT SEMESTER ASSIGNED CLASSES ===
  const currentTermSummary = currentSections[0]?.academic_term
  const currentTermLabel = currentTermSummary
    ? `${currentTermSummary.school_year} · ${currentTermSummary.semester} Semester`
    : null

  return (
    <div className="space-y-4">
      {currentTermLabel && (
        <div className="flex items-center gap-2 text-xs text-muted-foreground">
          <span>Active semester:</span>
          <Badge variant="outline" className="font-medium text-foreground">
            {currentTermLabel}
          </Badge>
          <span className="ml-auto">
            {currentSections.length} assigned {currentSections.length === 1 ? "class" : "classes"}
          </span>
        </div>
      )}

      {currentSections.length === 0 ? (
        <p className="text-sm text-muted-foreground">
          No classes are currently assigned to your account for this semester.
        </p>
      ) : (
        <div className="grid gap-3 xl:grid-cols-2">
          {currentSections.map((section) => (
            <GradeSectionCard
              key={section.section_id}
              section={section}
              selected={section.section_id === sectionId}
              onSelect={() => onSelectSection(section.section_id)}
            />
          ))}
        </div>
      )}

      {/* Lower Right: Previous Semesters Button (styled like Return to GRC Connect) */}
      {historySections.length > 0 && (
        <div className="flex justify-end pt-3">
          <Button
            type="button"
            variant="outline"
            className="portal-module-page__return"
            onClick={onOpenHistory}
          >
            <History className="size-4" data-icon="inline-start" aria-hidden="true" />
            Previous Semesters
          </Button>
        </div>
      )}
    </div>
  )
}

export function GradeSubmissionWorkspace() {
  const { session } = useAuth()
  const authorized = session?.role === "faculty"
  const [sectionId, setSectionId] = useState<number | null>(null)
  const [viewingHistory, setViewingHistory] = useState(false)
  const [selectedHistoryTermId, setSelectedHistoryTermId] = useState<number | null>(null)

  const sectionsQuery = useGradeSubmissionSectionsQuery({
    enabled: authorized,
  })

  const handleOpenHistory = () => {
    setViewingHistory(true)
    setSelectedHistoryTermId(null)
    setSectionId(null)
  }

  const handleCloseHistory = () => {
    setViewingHistory(false)
    setSelectedHistoryTermId(null)
    setSectionId(null)
  }

  const handleSelectHistoryTerm = (id: number) => {
    setSelectedHistoryTermId(id)
    setSectionId(null)
  }

  const handleBackToHistoryFolders = () => {
    setSelectedHistoryTermId(null)
    setSectionId(null)
  }

  return (
    <WorkspacePage
      title="Grade submission"
      description="Open an assigned class, review enrolled students, save grades as a draft, and submit the section for Registrar review."
      unauthorized={!authorized}
      lastUpdated={sectionsQuery.dataUpdatedAt}
    >
      <Card>
        <CardHeader className="flex flex-row items-center justify-between gap-3 pb-3">
          <div className="flex items-center gap-2">
            {viewingHistory && selectedHistoryTermId !== null && (
              <Button
                type="button"
                variant="ghost"
                size="sm"
                className="h-8 gap-1.5 px-2 text-xs"
                aria-label="Back to semester selection"
                onClick={handleBackToHistoryFolders}
              >
                <ArrowLeft className="size-3.5" aria-hidden />
                Back
              </Button>
            )}
            {viewingHistory && selectedHistoryTermId === null && (
              <Button
                type="button"
                variant="ghost"
                size="sm"
                className="h-8 gap-1.5 px-2 text-xs"
                aria-label="Back to current classes"
                onClick={handleCloseHistory}
              >
                <ArrowLeft className="size-3.5" aria-hidden />
                Back
              </Button>
            )}
            <CardTitle level={2}>
              {viewingHistory
                ? selectedHistoryTermId === null
                  ? "Previous Semesters"
                  : "Historical Classes"
                : "Assigned classes"}
            </CardTitle>
          </div>
        </CardHeader>
        <CardContent>
          <AsyncBoundary
            query={sectionsQuery}
            isEmpty={(sections) => sections.length === 0}
            emptyMessage="No sections are currently assigned to your faculty account."
            loadingLabel="Loading your assigned classes…"
          >
            {(sections) => (
              <AssignedClassesContent
                sections={sections}
                viewingHistory={viewingHistory}
                selectedHistoryTermId={selectedHistoryTermId}
                sectionId={sectionId}
                onOpenHistory={handleOpenHistory}
                onCloseHistory={handleCloseHistory}
                onSelectHistoryTerm={handleSelectHistoryTerm}
                onSelectSection={setSectionId}
              />
            )}
          </AsyncBoundary>
        </CardContent>
      </Card>

      <Dialog
        open={sectionId !== null}
        onOpenChange={(open) => {
          if (!open) setSectionId(null)
        }}
      >
        <DialogContent className="max-h-[92dvh] max-w-6xl overflow-y-auto p-4 sm:max-w-6xl sm:p-6">
          <DialogHeader className="sr-only">
            <DialogTitle>Section Grade Sheet</DialogTitle>
            <DialogDescription>
              Input and submit grades for the selected section
            </DialogDescription>
          </DialogHeader>
          {sectionId !== null && (
            <SectionGradeSheetPanel key={sectionId} sectionId={sectionId} />
          )}
        </DialogContent>
      </Dialog>
    </WorkspacePage>
  )
}

/**
 * One student's row on a phone. The default narrow layout puts each label and
 * its value side by side in two cramped columns, which squeezes the grade
 * dropdown and the remarks box (the text no longer lines up while a professor
 * is entering grades). Here the student comes first and every field sits under
 * its own label at the full width of the card.
 */
function GradeSheetCard({
  row,
  cell,
}: {
  row: SectionGradeRow
  cell: (key: string) => React.ReactNode
}) {
  return (
    <Card>
      <CardHeader className="pb-2">
        <CardTitle level={3} className="text-base">
          {row.student_name}
        </CardTitle>
        <p className="font-mono text-xs text-muted-foreground">
          {row.student_number}
        </p>
      </CardHeader>
      <CardContent className="grid gap-3">
        <div className="grid min-w-0 gap-1.5">
          <span className="text-xs font-medium text-muted-foreground">Grade</span>
          <div className="min-w-0 text-sm">{cell("mark")}</div>
        </div>
        <div className="grid min-w-0 gap-1.5">
          <span className="text-xs font-medium text-muted-foreground">Remarks</span>
          <div className="min-w-0 text-sm">{cell("remarks")}</div>
        </div>
        <div className="flex items-center justify-between gap-2">
          <span className="text-xs font-medium text-muted-foreground">Status</span>
          {cell("status")}
        </div>
      </CardContent>
    </Card>
  )
}
