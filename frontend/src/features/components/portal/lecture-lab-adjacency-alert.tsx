"use client"

import { TriangleAlert } from "lucide-react"

import {
  Alert,
  AlertDescription,
  AlertTitle,
} from "@/features/components/ui/alert"
import { useLectureLabAdjacencyQuery } from "@/features/hooks/use-lecture-lab-adjacency"

const SHOWN = 5

/**
 * Warns the Program Head when a lecture and its laboratory are scheduled
 * apart (stakeholder Doc 14: they must sit back-to-back). It is review
 * information only: it never blocks saving or submitting, and it says nothing
 * while the check is loading, fails, or finds no problem, so it cannot get in
 * the way of the schedule itself.
 */
export function LectureLabAdjacencyAlert({
  termId,
}: {
  termId: number | null
}) {
  const query = useLectureLabAdjacencyQuery(termId)
  const violations = query.data ?? []

  if (violations.length === 0) {
    return null
  }

  const extra = violations.length - SHOWN

  return (
    <Alert role="status" aria-label="Lecture and laboratory check">
      <TriangleAlert aria-hidden="true" />
      <AlertTitle>
        {violations.length === 1
          ? "1 lecture and laboratory pair is not back-to-back"
          : `${violations.length} lecture and laboratory pairs are not back-to-back`}
      </AlertTitle>
      <AlertDescription>
        <ul className="grid gap-1">
          {violations.slice(0, SHOWN).map((violation) => (
            <li
              key={`${violation.first.section_id}-${violation.second.section_id}`}
            >
              {violation.message}
            </li>
          ))}
        </ul>
        {extra > 0 && <p className="mt-1">…and {extra} more.</p>}
        <p className="mt-1 text-xs text-muted-foreground">
          Move one of the two so they meet one after the other. This does not
          stop you from saving or submitting.
        </p>
      </AlertDescription>
    </Alert>
  )
}
