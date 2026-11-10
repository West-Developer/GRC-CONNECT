"use client"

import { useAuth } from "@/features/auth/use-auth"
import { AcademicRecordView } from "@/features/components/portal/academic-record-view"
import { WorkspacePage } from "@/features/components/portal/workspace-page"

/**
 * The student's own Grades module — separate from the COR (see
 * `StudentDigitalComWorkspace`) since the two are unrelated records that
 * used to share one page and one failure boundary.
 */
export function StudentGradesWorkspace() {
  const { session } = useAuth()
  const authorized = session?.role === "student"

  return (
    <WorkspacePage
      title="Grades"
      description="Browse your recorded grades by school year and semester, and view your full prospectus."
      unauthorized={!authorized}
    >
      <AcademicRecordView />
    </WorkspacePage>
  )
}
