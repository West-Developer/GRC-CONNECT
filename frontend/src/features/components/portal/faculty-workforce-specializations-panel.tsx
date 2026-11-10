"use client"

import { useState } from "react"
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"

import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/features/components/ui/alert-dialog"
import { Badge } from "@/features/components/ui/badge"
import { Button } from "@/features/components/ui/button"
import { Input } from "@/features/components/ui/input"
import { SearchableCombobox } from "@/features/components/ui/searchable-combobox"
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/features/components/ui/table"
import { useSubjectsQuery } from "@/features/hooks/use-reference-data"
import {
  createFacultySpecialization,
  decideFacultySpecialization,
  getFacultySpecializations,
} from "@/features/services/faculty-service"

const STATUS_BADGE_VARIANT: Record<string, "secondary" | "warning" | "destructive"> = {
  approved: "secondary",
  pending: "warning",
  rejected: "destructive",
}

interface FacultyWorkforceSpecializationsPanelProps {
  professorId: number
  college: string | null
  canManage: boolean
}

export function FacultyWorkforceSpecializationsPanel({
  professorId,
  college,
  canManage,
}: FacultyWorkforceSpecializationsPanelProps) {
  const queryClient = useQueryClient()
  const specializationsQuery = useQuery({
    queryKey: ["faculty-workforce-specializations", professorId],
    queryFn: ({ signal }) => getFacultySpecializations(signal, professorId),
    enabled: professorId > 0,
  })
  const subjectsQuery = useSubjectsQuery()
  const [newSubjectId, setNewSubjectId] = useState("")
  const [newProficiency, setNewProficiency] = useState<"primary" | "secondary">("secondary")
  const [rejectingId, setRejectingId] = useState<number | null>(null)
  const [rejectReason, setRejectReason] = useState("")

  const invalidate = () =>
    void queryClient.invalidateQueries({
      queryKey: ["faculty-workforce-specializations", professorId],
    })

  const addSpecialization = useMutation({
    mutationFn: () =>
      createFacultySpecialization({
        professor_id: professorId,
        subject_id: Number(newSubjectId),
        proficiency: newProficiency,
      }),
    onSuccess: () => {
      setNewSubjectId("")
      invalidate()
    },
  })

  const decide = useMutation({
    mutationFn: ({ id, action, reason }: { id: number; action: "approve" | "reject"; reason?: string }) =>
      decideFacultySpecialization(id, { action, reason }),
    onSuccess: () => {
      setRejectingId(null)
      setRejectReason("")
      invalidate()
    },
  })

  const subjectsById = new Map(
    (subjectsQuery.data ?? []).map((subject) => [subject.id, subject]),
  )
  const existingSubjectIds = new Set(
    (specializationsQuery.data ?? []).map((row) => row.subject_id),
  )
  const subjectOptions = (subjectsQuery.data ?? [])
    .filter((subject) => subject.college === college && !existingSubjectIds.has(subject.id))
    .map((subject) => ({ value: String(subject.id), label: `${subject.code} — ${subject.title}` }))

  return (
    <div className="grid min-w-0 gap-3">
      <h3 className="text-sm font-semibold">Subjects this professor can teach</h3>
      <div className="overflow-hidden rounded-md border">
        <Table aria-label="Subject specializations">
          <TableHeader>
            <TableRow>
              <TableHead className="w-full">Subject</TableHead>
              <TableHead className="whitespace-nowrap">Proficiency</TableHead>
              <TableHead className="whitespace-nowrap">Status</TableHead>
              {canManage && <TableHead className="whitespace-nowrap text-right">Actions</TableHead>}
            </TableRow>
          </TableHeader>
          <TableBody>
            {(specializationsQuery.data ?? []).map((row) => {
              const subject = subjectsById.get(row.subject_id)
              return (
                <TableRow key={row.id}>
                  <TableCell className="max-w-0 truncate">
                    {subject ? `${subject.code} — ${subject.title}` : `Subject #${row.subject_id}`}
                  </TableCell>
                  <TableCell className="whitespace-nowrap">{row.proficiency_label}</TableCell>
                  <TableCell className="whitespace-nowrap">
                    <Badge variant={STATUS_BADGE_VARIANT[row.status]}>{row.status_label}</Badge>
                  </TableCell>
                  {canManage && (
                    <TableCell className="whitespace-nowrap text-right">
                      {row.status === "pending" && (
                        <>
                          <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={() => decide.mutate({ id: row.id, action: "approve" })}
                          >
                            Approve
                          </Button>
                          <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            className="ml-2"
                            onClick={() => setRejectingId(row.id)}
                          >
                            Reject
                          </Button>
                        </>
                      )}
                    </TableCell>
                  )}
                </TableRow>
              )
            })}
            {(specializationsQuery.data ?? []).length === 0 && (
              <TableRow>
                <TableCell colSpan={canManage ? 4 : 3} className="text-center text-muted-foreground">
                  No subjects declared yet.
                </TableCell>
              </TableRow>
            )}
          </TableBody>
        </Table>
      </div>

      {canManage && (
        <div className="grid gap-2">
          <div className="grid gap-2 sm:grid-cols-[1fr_auto_auto] sm:items-end">
            <label
              className="grid gap-2 text-sm font-medium"
              htmlFor="workforce-add-subject"
            >
              Add subject
              <SearchableCombobox
                id="workforce-add-subject"
                label="Add subject"
                options={subjectOptions}
                value={newSubjectId}
                onValueChange={setNewSubjectId}
                placeholder="Search code or title"
                emptyMessage="No matching subject."
              />
            </label>
            <select
              aria-label="Proficiency"
              value={newProficiency}
              onChange={(event) => setNewProficiency(event.target.value as "primary" | "secondary")}
              className="h-9 rounded-md border bg-background px-2"
            >
              <option value="primary">Primary</option>
              <option value="secondary">Secondary</option>
            </select>
            <Button
              type="button"
              className="w-full sm:w-auto"
              onClick={() => void addSpecialization.mutateAsync()}
              disabled={!newSubjectId || addSpecialization.isPending}
            >
              {addSpecialization.isPending ? "Adding…" : "Add subject"}
            </Button>
          </div>
          {addSpecialization.error instanceof Error && (
            <p className="text-sm text-destructive">{addSpecialization.error.message}</p>
          )}
        </div>
      )}

      {decide.error instanceof Error && (
        <p className="text-sm text-destructive">{decide.error.message}</p>
      )}

      <AlertDialog open={rejectingId !== null} onOpenChange={(open) => !open && setRejectingId(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Reject this subject</AlertDialogTitle>
            <AlertDialogDescription>
              <label
                className="grid gap-2 text-sm font-medium"
                htmlFor="workforce-reject-reason"
              >
                Reason for rejection
                <Input
                  id="workforce-reject-reason"
                  value={rejectReason}
                  onChange={(event) => setRejectReason(event.target.value)}
                  placeholder="Explain why this is not approved"
                />
              </label>
            </AlertDialogDescription>
          </AlertDialogHeader>
          {decide.error instanceof Error && (
            <p className="px-6 text-sm text-destructive">{decide.error.message}</p>
          )}
          <AlertDialogFooter>
            <AlertDialogCancel disabled={decide.isPending}>Cancel</AlertDialogCancel>
            <AlertDialogAction
              disabled={decide.isPending || !rejectReason.trim()}
              onClick={() =>
                rejectingId !== null &&
                decide.mutate({ id: rejectingId, action: "reject", reason: rejectReason })
              }
            >
              Confirm rejection
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}
