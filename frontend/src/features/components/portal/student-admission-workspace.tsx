"use client"

import { useAuth } from "@/features/auth/use-auth"
import { AdmissionRequirementsChecklist } from "@/features/components/portal/admission-requirements-checklist"
import { WorkspacePage } from "@/features/components/portal/workspace-page"
import { Badge } from "@/features/components/ui/badge"
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/features/components/ui/card"
import { useOwnStudentProfileQuery } from "@/features/hooks/use-student-records"

/** A Student's read-only view of their admission status and which requirements were handed in (ADR 0037). */
export function StudentAdmissionWorkspace() {
  const { session } = useAuth()
  const profile = useOwnStudentProfileQuery()

  return (
    <WorkspacePage
      title="Admission"
      description="Your admission status and the documents Admission has recorded as submitted."
      unauthorized={session?.role !== "student"}
    >
      <Card>
        <CardHeader className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
          <div>
            <CardTitle level={2}>Requirements</CardTitle>
            <CardDescription>
              Bring anything still missing to the Admission office.
            </CardDescription>
          </div>
          {profile.data && (
            <Badge variant="secondary">
              Admission status: {profile.data.admission_status_label}
            </Badge>
          )}
        </CardHeader>
        <CardContent>
          <AdmissionRequirementsChecklist studentId={null} />
        </CardContent>
      </Card>
    </WorkspacePage>
  )
}
