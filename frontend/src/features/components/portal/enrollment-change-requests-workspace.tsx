"use client"

import { useAuth } from "@/features/auth/use-auth"
import { EnrollmentChangeRequestsPanel } from "@/features/components/portal/enrollment-change-requests-panel"
import { WorkspacePage } from "@/features/components/portal/workspace-page"

/**
 * The standalone add/drop/change-section page. The Registrar's navigation now
 * points at the "Enrollment requests" hub instead (which reuses the same
 * panel with one tab per request type); this page stays registered for direct
 * links. Registrar Head decides, Registrar Staff reads only.
 */
export function EnrollmentChangeRequestsWorkspace() {
  const { session } = useAuth()
  const authorized =
    session?.role === "registrar_head" || session?.role === "registrar_staff"
  const canDecide = session?.role === "registrar_head"

  return (
    <WorkspacePage
      title="Add/Drop requests"
      description={
        canDecide
          ? "Approve or reject student add/drop/change-section requests."
          : "View every student add/drop/change-section request."
      }
      unauthorized={!authorized}
    >
      <EnrollmentChangeRequestsPanel />
    </WorkspacePage>
  )
}
