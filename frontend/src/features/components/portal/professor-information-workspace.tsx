"use client"

import { useMemo } from "react"

import { useAuth } from "@/features/auth/use-auth"
import { AsyncBoundary } from "@/features/components/portal/async-boundary"
import { WorkspacePage } from "@/features/components/portal/workspace-page"
import { Badge } from "@/features/components/ui/badge"
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
} from "@/features/components/ui/card"
import { useFacultyDirectoryQuery } from "@/features/hooks/use-faculty-directory"

const collegeLabels: Record<string, string> = {
  ccs: "College of Computer Studies",
  coe: "College of Education",
  coa: "College of Accountancy",
  cbae: "College of Business Administration and Entrepreneurship",
}

const employmentTypeLabels: Record<string, string> = {
  full_time: "Full-time",
  part_time: "Part-time",
}

export function ProfessorInformationWorkspace() {
  const { session } = useAuth()
  const isFaculty = session?.role === "faculty"
  const directoryQuery = useFacultyDirectoryQuery(true)

  const ownRecord = useMemo(() => {
    if (!directoryQuery.data || !session) return null
    const facultyId = Number(session.userId)
    return (
      directoryQuery.data.find(
        (member) =>
          member.id === facultyId ||
          (Boolean(session.displayName) &&
            member.name.toLowerCase() === session.displayName.toLowerCase()),
      ) ?? null
    )
  }, [directoryQuery.data, session])

  return (
    <WorkspacePage
      title="My Information"
      description="Your official faculty profile as recorded in the GRC system."
      unauthorized={!isFaculty}
      lastUpdated={directoryQuery.dataUpdatedAt}
    >
      <AsyncBoundary query={directoryQuery} loadingLabel="Loading your profile...">
        {() => (
          <div className="grid gap-5 lg:grid-cols-2">
            <Card>
              <CardHeader>
                <CardTitle level={2}>Identity</CardTitle>
              </CardHeader>
              <CardContent className="grid gap-3">
                <dl className="grid gap-2 text-sm">
                  <div className="grid grid-cols-[10rem_minmax(0,1fr)] gap-2">
                    <dt className="text-muted-foreground">Full name</dt>
                    <dd className="font-medium">
                      {session?.displayName ?? "—"}
                    </dd>
                  </div>
                  <div className="grid grid-cols-[10rem_minmax(0,1fr)] gap-2">
                    <dt className="text-muted-foreground">College</dt>
                    <dd className="font-medium">
                      {ownRecord?.college
                        ? (collegeLabels[ownRecord.college] ?? ownRecord.college.toUpperCase())
                        : session?.college
                          ? (collegeLabels[session.college] ?? session.college.toUpperCase())
                          : "—"}
                    </dd>
                  </div>
                  <div className="grid grid-cols-[10rem_minmax(0,1fr)] gap-2">
                    <dt className="text-muted-foreground">Status</dt>
                    <dd>
                      {ownRecord ? (
                        <Badge
                          variant={
                            ownRecord.status === "active"
                              ? "secondary"
                              : "outline"
                          }
                        >
                          {ownRecord.status_label}
                        </Badge>
                      ) : (
                        "—"
                      )}
                    </dd>
                  </div>
                  <div className="grid grid-cols-[10rem_minmax(0,1fr)] gap-2">
                    <dt className="text-muted-foreground">Employment type</dt>
                    <dd className="font-medium">
                      {ownRecord?.employment_type
                        ? (employmentTypeLabels[ownRecord.employment_type] ??
                          ownRecord.employment_type_label ??
                          "—")
                        : (ownRecord?.employment_type_label ?? "—")}
                    </dd>
                  </div>
                  {ownRecord?.planning_unit_reference !== undefined &&
                    ownRecord.planning_unit_reference !== null && (
                      <div className="grid grid-cols-[10rem_minmax(0,1fr)] gap-2">
                        <dt className="text-muted-foreground">
                          Planning unit ref.
                        </dt>
                        <dd className="font-medium">
                          {ownRecord.planning_unit_reference}
                        </dd>
                      </div>
                    )}
                </dl>
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle level={2}>Assignability</CardTitle>
              </CardHeader>
              <CardContent>
                <p className="text-sm text-muted-foreground">
                  {ownRecord === null
                    ? "Your faculty record was not found in the directory. Contact your Program Head."
                    : ownRecord.is_assignable
                      ? "You are currently marked as assignable and may be assigned to sections this term."
                      : "You are currently marked as non-assignable. Contact your Program Head to update your workforce status."}
                </p>
                {ownRecord !== null && (
                  <div className="mt-3">
                    <Badge
                      variant={ownRecord.is_assignable ? "secondary" : "destructive"}
                    >
                      {ownRecord.is_assignable ? "Assignable" : "Not assignable"}
                    </Badge>
                  </div>
                )}
              </CardContent>
            </Card>

            <Card className="lg:col-span-2">
              <CardHeader>
                <CardTitle level={2}>Account</CardTitle>
              </CardHeader>
              <CardContent>
                <p className="text-sm text-muted-foreground">
                  For corrections to your profile information, contact your
                  Program Head or the Registrar. Teaching schedule assignments
                  and grade submissions are managed through their respective
                  modules.
                </p>
              </CardContent>
            </Card>
          </div>
        )}
      </AsyncBoundary>
    </WorkspacePage>
  )
}
