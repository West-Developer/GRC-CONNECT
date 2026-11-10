"use client"

import { CalendarClock, CalendarX2, CircleCheck, Lock } from "lucide-react"

import { Alert, AlertDescription, AlertTitle } from "@/features/components/ui/alert"
import type { AudienceAvailability } from "@/features/schemas/enrollment-window-schema"

function formatDate(iso: string | null): string | null {
  if (!iso) return null

  return new Date(iso).toLocaleString(undefined, {
    year: "numeric",
    month: "long",
    day: "numeric",
    hour: "numeric",
    minute: "2-digit",
  })
}

/**
 * Presentational only — the caller supplies `viewer` (from
 * `GET .../enrollment-windows`'s student-only `viewer` block) so the same
 * fetch backs both the gating logic on the enrollment workspace and this
 * informational banner, rather than each place fetching its own copy.
 *
 * The audience name comes from the server's `label` ("1st Year" …
 * "Irregular Students"), so an irregular student never sees a year-level
 * phrasing that does not apply to them.
 */
export function EnrollmentAvailabilityBanner({
  viewer,
}: {
  viewer: AudienceAvailability | null | undefined
}) {
  if (!viewer) return null

  const audienceLabel = viewer.label

  if (viewer.is_open) {
    const closes = formatDate(viewer.closes_at)

    return (
      <Alert>
        <CircleCheck aria-hidden="true" />
        <AlertTitle>Enrollment is open</AlertTitle>
        <AlertDescription>
          Enrollment is open for {audienceLabel}
          {closes ? ` until ${closes}.` : "."}
        </AlertDescription>
      </Alert>
    )
  }

  if (viewer.reason === "before_window") {
    const opens = formatDate(viewer.opens_at)

    return (
      <Alert>
        <CalendarClock aria-hidden="true" />
        <AlertTitle>Enrollment has not opened yet</AlertTitle>
        <AlertDescription>
          Enrollment for {audienceLabel}
          {opens ? ` opens ${opens}.` : " has not opened yet."}
        </AlertDescription>
      </Alert>
    )
  }

  if (viewer.reason === "after_window") {
    return (
      <Alert variant="destructive">
        <CalendarX2 aria-hidden="true" />
        <AlertTitle>Enrollment window closed</AlertTitle>
        <AlertDescription>
          Enrollment for {audienceLabel} has closed for this term.
        </AlertDescription>
      </Alert>
    )
  }

  return (
    <Alert variant="destructive">
      <Lock aria-hidden="true" />
      <AlertTitle>Enrollment is not open</AlertTitle>
      <AlertDescription>
        {viewer.reason === "term_closed"
          ? "This term has closed."
          : "The Registrar has not opened enrollment for this term yet."}
      </AlertDescription>
    </Alert>
  )
}
