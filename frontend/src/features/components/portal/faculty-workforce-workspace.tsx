"use client"

import { useState } from "react"
import { useMutation, useQueryClient } from "@tanstack/react-query"

import { useAuth } from "@/features/auth/use-auth"
import { AsyncBoundary } from "@/features/components/portal/async-boundary"
import { FacultyProfilePanel } from "@/features/components/portal/faculty-profile-panel"
import { FacultyWorkforceSpecializationsPanel } from "@/features/components/portal/faculty-workforce-specializations-panel"
import { WorkspaceField } from "@/features/components/portal/workspace-field"
import { WorkspacePage } from "@/features/components/portal/workspace-page"
import { Badge } from "@/features/components/ui/badge"
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
} from "@/features/components/ui/card"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/features/components/ui/dialog"
import { Button } from "@/features/components/ui/button"
import { Input } from "@/features/components/ui/input"
import { useFacultyDirectoryQuery } from "@/features/hooks/use-faculty-directory"
import { updateFacultyWorkforceProfile } from "@/features/services/faculty-directory-service"
import type { FacultyMember } from "@/features/schemas/scheduling-schema"

const COLLEGE_OPTIONS: readonly { value: string; label: string }[] = [
  { value: "", label: "All colleges" },
  { value: "ccs", label: "College of Computer Studies" },
  { value: "coe", label: "College of Education" },
  { value: "coa", label: "College of Accountancy" },
  {
    value: "cbae",
    label: "College of Business Administration and Entrepreneurship",
  },
]

export function FacultyWorkforceWorkspace() {
  const { session } = useAuth()
  const queryClient = useQueryClient()
  const canManage = session?.role === "program_chair"
  const [search, setSearch] = useState("")
  const [collegeFilter, setCollegeFilter] = useState("")
  const facultyQuery = useFacultyDirectoryQuery(
    true,
    canManage ? undefined : collegeFilter || undefined,
  )
  const [selected, setSelected] = useState<FacultyMember | null>(null)
  const [draft, setDraft] = useState({
    status: "active" as "active" | "disabled",
    employment_type: "part_time" as "full_time" | "part_time",
    reason: "",
  })

  const saveWorkforceProfile = useMutation({
    mutationFn: async () => {
      if (!selected) throw new Error("Choose a faculty member to edit.")
      return updateFacultyWorkforceProfile(selected.id, {
        status: draft.status,
        employment_type: draft.employment_type,
        reason: draft.reason || undefined,
      })
    },
    onSuccess: () => {
      setSelected(null)
      void queryClient.invalidateQueries({ queryKey: ["faculty-directory"] })
    },
  })

  const openProfessor = (member: FacultyMember) => {
    setSelected(member)
    setDraft({
      status: member.status,
      employment_type: member.employment_type ?? "part_time",
      reason: "",
    })
  }

  const visibleFaculty = (facultyQuery.data ?? []).filter((member) =>
    member.name.toLowerCase().includes(search.toLowerCase()),
  )

  return (
    <WorkspacePage
      title="Faculty Workforce"
      description="Search professors, review their profile, and manage which subjects they can teach."
      lastUpdated={facultyQuery.dataUpdatedAt}
    >
      <AsyncBoundary query={facultyQuery} loadingLabel="Loading faculty…">
        {() => (
          <div className="grid gap-5">
            <Card>
              <CardHeader>
                <CardTitle level={2}>Faculty roster</CardTitle>
              </CardHeader>
              <CardContent className="grid gap-3">
                <div className="grid gap-3 sm:grid-cols-[1fr_auto]">
                  <label
                    className="grid gap-2 text-sm font-medium"
                    htmlFor="faculty-workforce-search"
                  >
                    Search
                    <Input
                      id="faculty-workforce-search"
                      aria-label="Search faculty by name"
                      value={search}
                      onChange={(event) => setSearch(event.target.value)}
                      placeholder="Search by name"
                    />
                  </label>
                  {!canManage && (
                    <label className="grid gap-2 text-sm font-medium">
                      College
                      <select
                        aria-label="College"
                        value={collegeFilter}
                        onChange={(event) =>
                          setCollegeFilter(event.target.value)
                        }
                        className="h-9 rounded-md border bg-background px-2"
                      >
                        {COLLEGE_OPTIONS.map((option) => (
                          <option key={option.value} value={option.value}>
                            {option.label}
                          </option>
                        ))}
                      </select>
                    </label>
                  )}
                </div>
                <div className="grid gap-2">
                  {visibleFaculty.map((member) => (
                    <button
                      type="button"
                      key={member.id}
                      onClick={() => openProfessor(member)}
                      className="flex items-center justify-between rounded-lg border p-3 text-left hover:bg-accent"
                    >
                      <div>
                        <span>{member.name}</span>
                        {member.employment_type_label && (
                          <span className="ml-2 text-xs text-muted-foreground">
                            · {member.employment_type_label}
                          </span>
                        )}
                      </div>
                      <div className="flex items-center gap-2">
                        {!member.is_assignable &&
                          member.deactivation_reason && (
                            <Badge
                              variant="outline"
                              className="capitalize text-xs font-normal"
                            >
                              {member.deactivation_reason.replace(/_/g, " ")}
                            </Badge>
                          )}
                        <Badge
                          variant={
                            member.is_assignable ? "secondary" : "destructive"
                          }
                        >
                          {member.status_label}
                        </Badge>
                      </div>
                    </button>
                  ))}
                  {visibleFaculty.length === 0 && (
                    <p className="rounded-lg border border-dashed p-5 text-sm text-muted-foreground">
                      No faculty match your search.
                    </p>
                  )}
                </div>
              </CardContent>
            </Card>
          </div>
        )}
      </AsyncBoundary>

      <Dialog
        open={selected !== null}
        onOpenChange={(open) => !open && setSelected(null)}
      >
        <DialogContent className="flex max-h-dvh flex-col p-0 sm:max-h-[90dvh] sm:max-w-5xl sm:rounded-xl">
          {/* Scrollable body */}
          <div className="flex-1 overflow-y-auto p-4 sm:p-6">
            <DialogHeader className="mb-4">
              <DialogTitle>{selected?.name ?? "Faculty member"}</DialogTitle>
              <DialogDescription>
                {canManage
                  ? "Review this professor's profile and the subjects they may teach."
                  : "Review this professor's teaching load, timetable, grade submission, and the subjects they may teach."}
              </DialogDescription>
            </DialogHeader>

            {!canManage && selected && (
              <div className="mb-6">
                <p className="mb-3 text-sm text-muted-foreground">
                  You have read-only access.
                </p>
                <FacultyProfilePanel professorId={selected.id} />
              </div>
            )}

            <div
              className={
                canManage
                  ? "grid min-w-0 gap-6 sm:grid-cols-[280px_1fr]"
                  : "block"
              }
            >
              {canManage && selected && (
                <div className="grid content-start gap-4">
                  <WorkspaceField label="Account status">
                    <select
                      aria-label="Account status"
                      value={draft.status}
                      onChange={(event) =>
                        setDraft({
                          ...draft,
                          status: event.target.value as "active" | "disabled",
                        })
                      }
                      className="h-9 w-full rounded-md border bg-background px-2"
                    >
                      <option value="active">Active</option>
                      <option value="disabled">Inactive</option>
                    </select>
                  </WorkspaceField>
                  <WorkspaceField label="Employment type">
                    <select
                      aria-label="Employment type"
                      value={draft.employment_type}
                      onChange={(event) =>
                        setDraft({
                          ...draft,
                          employment_type: event.target.value as
                            "full_time" | "part_time",
                        })
                      }
                      className="h-9 w-full rounded-md border bg-background px-2"
                    >
                      <option value="full_time">
                        Full-time (33-unit reference)
                      </option>
                      <option value="part_time">Part-time</option>
                    </select>
                  </WorkspaceField>
                  <WorkspaceField
                    label={
                      selected.status === "active" &&
                      draft.status === "disabled"
                        ? "Reason for making this account inactive"
                        : "Change note (optional)"
                    }
                  >
                    <Input
                      value={draft.reason}
                      onChange={(event) =>
                        setDraft({ ...draft, reason: event.target.value })
                      }
                      placeholder="Record the reason for this change"
                    />
                  </WorkspaceField>
                  {saveWorkforceProfile.error instanceof Error && (
                    <p className="text-sm text-destructive">
                      {saveWorkforceProfile.error.message}
                    </p>
                  )}
                  <Button
                    type="button"
                    onClick={() => void saveWorkforceProfile.mutateAsync()}
                    disabled={
                      saveWorkforceProfile.isPending ||
                      (selected.status === "active" &&
                        draft.status === "disabled" &&
                        !draft.reason.trim())
                    }
                  >
                    {saveWorkforceProfile.isPending
                      ? "Saving…"
                      : "Save workforce profile"}
                  </Button>
                </div>
              )}

              {selected && (
                <div className="min-w-0">
                  <FacultyWorkforceSpecializationsPanel
                    professorId={selected.id}
                    college={selected.college}
                    canManage={canManage}
                  />
                </div>
              )}
            </div>
          </div>
        </DialogContent>
      </Dialog>
    </WorkspacePage>
  )
}
