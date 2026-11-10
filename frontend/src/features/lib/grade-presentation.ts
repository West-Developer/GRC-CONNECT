import {
  academicMarkValues,
  completionOnlyMarkValues,
  gradeMarkValues,
  type AcademicGrade,
} from "@/features/schemas/academic-grade-schema"

export type GradeMarkValue = (typeof gradeMarkValues)[number]

export function gradeBadgeVariant(
  status: AcademicGrade["status"],
): "default" | "secondary" | "outline" {
  if (status === "locked") return "default"
  if (status === "submitted") return "secondary"
  return "outline"
}

/**
 * Mirrors App\Domain\Academic\GradeMark::label() — used only for the
 * encode-time dropdown's option text before a server response exists. Once
 * a grade has a server-assigned `mark`, its own `mark_label` renders
 * instead.
 */
export const gradeMarkLabel: Record<GradeMarkValue, string> = {
  "1.00": "Excellent",
  "1.25": "High Distinction",
  "1.50": "with Distinction",
  "1.75": "Very Good",
  "2.00": "Good",
  "2.25": "Very Satisfactory",
  "2.50": "Satisfactory",
  "2.75": "Fair",
  "3.00": "Passed",
  "5.00": "Failed",
  C: "Complete",
  NC: "Not Complete",
  INC: "Incomplete",
  DRP: "Dropped",
  W: "Withdrawn",
}

/**
 * Which marks a subject may take — Leadership subjects (completion-only)
 * are Complete or Incomplete only, never a numeric grade.
 */
export function allowedMarksForSubject(
  isCompletionOnly: boolean,
): readonly GradeMarkValue[] {
  return isCompletionOnly ? completionOnlyMarkValues : academicMarkValues
}

/**
 * A mark's pass/fail standing, independent of `AcademicGrade["status"]`
 * (which tracks draft/submitted/locked workflow state, not the mark
 * itself). Drives color coding on the prospectus and grade slip so a
 * student can spot a problem mark or an unattempted subject at a glance.
 */
export type MarkTone =
  | "passed"
  | "failed"
  | "incomplete"
  | "withdrawn"
  | "not-taken"

export function markTone(mark: GradeMarkValue | null): MarkTone {
  if (mark === null) return "not-taken"
  if (mark === "5.00") return "failed"
  if (mark === "W") return "withdrawn"
  if (mark === "NC" || mark === "INC" || mark === "DRP") return "incomplete"
  return "passed"
}

/** Maps a mark's tone onto the shared `Badge` component's `variant` prop. */
export function markToneBadgeVariant(
  tone: MarkTone,
): "success" | "destructive" | "warning" | "secondary" | "outline" {
  if (tone === "failed") return "destructive"
  if (tone === "incomplete") return "warning"
  if (tone === "withdrawn") return "secondary"
  if (tone === "not-taken") return "outline"
  return "success"
}

/**
 * Screen-only row tint — print output stays black-on-white (`.print-document`
 * in globals.css overrides colors unconditionally), so this must never be
 * the only signal a tone is communicated with; pair it with text/badges.
 */
export function markToneRowClass(tone: MarkTone): string {
  if (tone === "failed") return "bg-destructive/5"
  if (tone === "incomplete") return "bg-warning/5"
  if (tone === "withdrawn") return "bg-muted/60"
  if (tone === "not-taken") return "bg-muted/40"
  return ""
}
