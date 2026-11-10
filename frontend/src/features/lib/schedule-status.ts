import {
  BadgeCheck,
  CircleDashed,
  Clock,
  type LucideIcon,
  Megaphone,
  PackageCheck,
  UndoDot,
} from "lucide-react"

import type { ScheduleProposal } from "@/features/schemas/scheduling-schema"

export type ScheduleTone =
  | "pending"
  | "approved"
  | "returned"
  | "published"
  | "closed"

export type ScheduleBadgeVariant =
  | "secondary"
  | "warning"
  | "success"
  | "destructive"
  | "default"

export interface ScheduleStatusPresentation {
  tone: ScheduleTone
  badgeVariant: ScheduleBadgeVariant
  label: string
  title: string
  description: string
  icon: LucideIcon
}

type PresentedProposal = Pick<
  ScheduleProposal,
  | "status"
  | "is_submitted"
  | "is_returned"
  | "returned_by_role"
  | "decided_by_name"
>

const REVIEWER_LABEL: Record<"dean" | "executive_director", string> = {
  dean: "the Dean",
  executive_director: "the Executive Director",
}

/**
 * Single source of truth for how a schedule proposal's lifecycle state
 * reads across every screen (Program Chair, Dean, Executive Director,
 * Registrar). There is no dedicated "returned" status on the backend — a
 * return resolves to `draft` (see `ScheduleProposalTransitionRules`) — so
 * `is_returned` is checked first and short-circuits the rest of the status
 * switch. Every consumer should call this instead of re-deriving its own
 * badge/label so a status always reads the same way everywhere.
 */
export function scheduleProposalPresentation(
  proposal: PresentedProposal,
): ScheduleStatusPresentation {
  if (proposal.is_returned) {
    const reviewer = proposal.returned_by_role
      ? REVIEWER_LABEL[proposal.returned_by_role]
      : "a reviewer"

    return {
      tone: "returned",
      badgeVariant: "destructive",
      label: "Returned for correction",
      title: "Returned for correction",
      description: `Sent back by ${reviewer}. Fix the flagged schedules, then resubmit.`,
      icon: UndoDot,
    }
  }

  switch (proposal.status) {
    case "draft":
      return proposal.is_submitted
        ? {
            tone: "pending",
            badgeVariant: "warning",
            label: "Pending Dean",
            title: "Submitted to Dean",
            description:
              "Waiting for Dean review. The Executive Director review follows after Dean approval.",
            icon: Clock,
          }
        : {
            tone: "pending",
            badgeVariant: "secondary",
            label: "Draft",
            title: "Draft",
            description: "Not yet submitted for review.",
            icon: CircleDashed,
          }
    case "dean_approved":
      return {
        tone: "approved",
        badgeVariant: "success",
        label: "Pending Executive Director",
        title: "Approved by Dean",
        description: "Waiting for Executive Director review.",
        icon: BadgeCheck,
      }
    case "executive_approved":
      return {
        tone: "approved",
        badgeVariant: "success",
        label: "Approved",
        title: "Approved by Executive Director",
        description:
          "The enrollment plan is approved and is waiting for schedule publication.",
        icon: BadgeCheck,
      }
    case "published":
      return {
        tone: "published",
        badgeVariant: "default",
        label: "Published",
        title: "Approved and published",
        description: "The approved sections and schedules are now published.",
        icon: Megaphone,
      }
    case "closed":
      return {
        tone: "closed",
        badgeVariant: "secondary",
        label: "Closed",
        title: "Schedule closed",
        description: "This schedule proposal has completed its lifecycle.",
        icon: PackageCheck,
      }
  }
}
