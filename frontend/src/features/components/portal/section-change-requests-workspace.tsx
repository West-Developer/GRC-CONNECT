"use client"

import { useAuth } from "@/features/auth/use-auth"
import { SectionChangeRequestsPanel } from "@/features/components/portal/section-change-requests-panel"
import { WorkspacePage } from "@/features/components/portal/workspace-page"

/**
 * The Registrar Head's inbox for schedule changes to published sections
 * (stakeholder Doc 14, ADR 0032).
 */
export function SectionChangeRequestsWorkspace() {
  const { session } = useAuth()
  const authorized = session?.role === "registrar_head"

  return (
    <WorkspacePage
      title="Schedule change requests"
      description="Program Heads cannot edit a published schedule themselves. Review what they ask to change, compare it with what is published, then approve or reject."
      unauthorized={!authorized}
    >
      {authorized && <SectionChangeRequestsPanel mode="decider" />}
    </WorkspacePage>
  )
}
