"use client"

import { History } from "lucide-react"

import type { AcademicTerm } from "@/features/schemas/reference-data-schema"
import { formatAcademicTerm } from "@/features/services/reference-data-service"

const termStatusLabels: Record<string, string> = {
  draft: "Draft",
  for_dean_approval: "For Dean Approval",
  semester_ongoing: "Current",
  semester_closed: "Closed",
  archived: "Archived",
}

interface AcademicTermSelectorProps {
  sortedTerms: readonly AcademicTerm[]
  term: AcademicTerm | null
  isCurrentTerm: boolean
  onSelectTerm: (id: number) => void
  /** True while the terms are still being fetched, so "none yet" is not read as "none exist". */
  isLoading?: boolean
  /** For viewers who cannot edit the schedule (a Student): the description must not promise edits. */
  readOnly?: boolean
}

function selectorDescription(
  hasTerms: boolean,
  term: AcademicTerm | null,
  isCurrentTerm: boolean,
  isLoading: boolean,
  readOnly: boolean,
): string {
  if (!hasTerms) {
    return isLoading
      ? "Loading school years and semesters…"
      : "No academic terms are available right now."
  }
  if (term === null) {
    return "Choose a school year and semester to view its schedule."
  }
  if (readOnly) {
    return isCurrentTerm
      ? "Viewing the current term."
      : "Viewing a past term. Choose another school year and semester to switch."
  }
  return isCurrentTerm
    ? "Viewing the current term. Assignments are editable."
    : "Viewing an archived schedule — read-only. Switch back to the current term to make changes."
}

export function AcademicTermSelector({
  sortedTerms,
  term,
  isCurrentTerm,
  onSelectTerm,
  isLoading = false,
  readOnly = false,
}: AcademicTermSelectorProps) {
  const hasTerms = sortedTerms.length > 0

  return (
    <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border bg-muted/30 p-4">
      <div className="flex min-w-0 items-center gap-2">
        <History className="size-4 shrink-0 text-primary" aria-hidden="true" />
        <div className="min-w-0">
          <p className="font-medium">School year and semester</p>
          <p className="text-sm text-muted-foreground">
            {selectorDescription(
              hasTerms,
              term,
              isCurrentTerm,
              isLoading,
              readOnly,
            )}
          </p>
        </div>
      </div>
      <label className="grid w-full min-w-0 gap-1 text-sm font-medium sm:w-auto">
        <span className="sr-only">Academic term</span>
        <select
          value={term?.id ?? ""}
          disabled={!hasTerms}
          aria-busy={isLoading && !hasTerms ? true : undefined}
          onChange={(event) => {
            const id = Number(event.target.value)
            if (Number.isInteger(id) && id > 0) onSelectTerm(id)
          }}
          className="h-9 w-full min-w-0 max-w-full rounded-md border bg-background px-2 disabled:cursor-not-allowed disabled:opacity-60"
        >
          {!hasTerms && (
            <option value="">
              {isLoading
                ? "Loading academic terms…"
                : "No academic terms available"}
            </option>
          )}
          {hasTerms && term === null && (
            <option value="" disabled>
              Select a school year and semester
            </option>
          )}
          {sortedTerms.map((candidate) => (
            <option key={candidate.id} value={candidate.id}>
              {formatAcademicTerm(candidate)}
              {candidate.status !== "semester_ongoing"
                ? ` (${termStatusLabels[candidate.status] ?? candidate.status})`
                : ""}
            </option>
          ))}
        </select>
      </label>
    </div>
  )
}
