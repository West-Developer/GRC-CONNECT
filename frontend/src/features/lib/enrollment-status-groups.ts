import {
  CircleCheck,
  CircleDashed,
  CircleOff,
  Hourglass,
  type LucideIcon,
} from "lucide-react"

import {
  enrollmentStatusGroupValues,
  type EnrollmentStatusGroup,
  type EnrollmentStatusGroupCounts,
} from "@/features/schemas/dashboard-schema"

export interface EnrollmentGroupPresentation {
  label: string
  /** One line saying who is in the group, shown under its label. */
  description: string
  icon: LucideIcon
  /** A CSS color for the mark. Text never wears it; the icon and label carry meaning. */
  color: string
}

/**
 * The four groups a student can sit in for a term (ADR 0024). These are
 * states, so they wear the app's reserved status tokens (which already have a
 * dark-mode step) rather than the categorical ramp, and always pair the color
 * with an icon and a text label so meaning is never color-alone. "Not yet
 * done" is the large default bucket, so it takes the neutral gray and the
 * three groups that matter stand out against it.
 */
export const ENROLLMENT_GROUP_PRESENTATION: Record<
  EnrollmentStatusGroup,
  EnrollmentGroupPresentation
> = {
  enrolled: {
    label: "Enrolled",
    description: "Enrolled for the term",
    icon: CircleCheck,
    color: "var(--success)",
  },
  in_progress: {
    label: "Ongoing",
    description: "Submitted, waiting on approval or payment",
    icon: Hourglass,
    color: "var(--warning)",
  },
  not_yet_done: {
    label: "Not yet done",
    description: "Has not started enrolling this term",
    icon: CircleDashed,
    color: "var(--muted-foreground)",
  },
  not_enrolled: {
    label: "Not enrolled",
    description: "Rejected, cancelled or withdrawn",
    icon: CircleOff,
    color: "var(--destructive)",
  },
}

export const ENROLLMENT_GROUPS = enrollmentStatusGroupValues

/** The steps of the in-progress chart, in the order a student moves through them. */
export const ENROLLMENT_STEPS = [
  "draft",
  "pending_program_head_approval",
  "pending_student_review",
  "awaiting_first_payment",
  "pending_registrar_approval",
  "pending_payment",
  "enrolled",
] as const

export const ENROLLMENT_STEP_LABELS: Record<
  (typeof ENROLLMENT_STEPS)[number],
  string
> = {
  draft: "Draft",
  pending_program_head_approval: "Pending Program Head Approval",
  pending_student_review: "Awaiting Student Review",
  awaiting_first_payment: "Awaiting First Payment",
  pending_registrar_approval: "Pending Registrar Approval",
  pending_payment: "Pending Payment",
  enrolled: "Enrolled",
}

export function groupTotal(groups: EnrollmentStatusGroupCounts): number {
  return ENROLLMENT_GROUPS.reduce((sum, group) => sum + groups[group], 0)
}

/** A share of `total` as a whole percent, or one decimal below 1% (so 0.4% isn't shown as 0%). */
export function formatShare(count: number, total: number): string {
  if (total <= 0) return "0%"
  const share = (count / total) * 100
  if (count > 0 && share < 1) return `${share.toFixed(1)}%`
  return `${Math.round(share)}%`
}

export function formatCount(count: number): string {
  return count.toLocaleString("en-US")
}
