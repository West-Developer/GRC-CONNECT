"use client"

import { Info } from "lucide-react"

import { Alert, AlertDescription, AlertTitle } from "@/features/components/ui/alert"
import type { AudienceAvailability } from "@/features/schemas/enrollment-window-schema"

/**
 * Explains why the student is seeing the block flow or the per-subject flow
 * this term — the underlying rule (`ClassifyEnrollmentStanding`) is
 * re-derived live every time the student's enrollment page loads (and
 * eagerly on every grade lock too), so this is always describing live
 * status, never a manual label. Presentational only, same
 * shape as `EnrollmentAvailabilityBanner`: the caller supplies `viewer` from
 * the shared `enrollment-windows` fetch, and a null/undefined viewer (a
 * non-student session, or one still loading) renders nothing.
 */
export function EnrollmentCategoryExplanation({
  viewer,
}: {
  viewer: AudienceAvailability | null | undefined
}) {
  if (!viewer) return null

  const isIrregular = viewer.audience === "irregular"

  return (
    <Alert>
      <Info aria-hidden="true" />
      <AlertTitle>
        {isIrregular
          ? "You're Irregular this semester"
          : "You're Regular this semester"}
      </AlertTitle>
      <AlertDescription>
        {isIrregular
          ? "You'll enroll by picking a section per subject instead of a whole block. This happens when a subject from a semester you've already completed still needs to be resolved — failed, marked Incomplete, Not Complete, Dropped, or never taken, shown as “Backlog” below — when a subject of your block is already credited from your previous school or curriculum, or when a prerequisite is not met yet. A subject you are already credited for is not offered again. Once none of these applies and your grades are finalized, you'll return to Regular automatically."
          : "Your current category is Regular, so you'll enroll by picking one section for your whole block. Each subject must still meet its prerequisites. If an earlier subject is missing or unresolved, ask the Registrar to review your academic record; your category may change to Irregular."}
      </AlertDescription>
    </Alert>
  )
}
