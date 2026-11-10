"use client"

import { zodResolver } from "@hookform/resolvers/zod"
import { useState } from "react"
import { useForm } from "react-hook-form"
import { z } from "zod"

import { Alert, AlertDescription } from "@/features/components/ui/alert"
import { Button } from "@/features/components/ui/button"
import { Checkbox } from "@/features/components/ui/checkbox"
import { Field, FieldError, FieldLabel } from "@/features/components/ui/field"
import { Input } from "@/features/components/ui/input"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/features/components/ui/select"
import {
  useApplyCurriculumMigrationMutation,
  useCurriculumMigrationPreviewMutation,
} from "@/features/hooks/use-curriculum-authoring"
import type { Curriculum } from "@/features/schemas/reference-data-schema"
import { isApiClientError } from "@/features/services/api-client"

const inputSchema = z.object({
  student_number: z.string().trim().min(1, "Enter the student number."),
})
type InputValues = z.infer<typeof inputSchema>

function messageFor(error: unknown): string {
  if (isApiClientError(error)) return error.message
  return "The migration request could not be completed. Please try again."
}

/**
 * A deliberately small, chair-only decision surface: it does not alter the
 * student's historic grades, and defaults every qualified mapping to selected
 * while letting the Chair remove any credit before confirmation.
 */
export function CurriculumMigrationPanel({
  curricula,
}: {
  curricula: readonly Curriculum[]
}) {
  const targets = curricula.filter(
    (curriculum) =>
      curriculum.status === "active" &&
      curriculum.equivalency_source_curriculum_id !== null &&
      curriculum.equivalency_source_curriculum_id !== undefined,
  )
  // `targets` depends on a query that can still be loading when this panel
  // first mounts, so picking targets[0] once via useState can freeze at 0 (or
  // a since-removed id) forever. Track only the Chair's explicit choice here
  // and fall back to the current list's first entry at render time instead —
  // that follows a list that arrives late without a synchronizing effect.
  const [selectedTargetId, setSelectedTargetId] = useState<number | null>(null)
  const targetId =
    selectedTargetId !== null &&
    targets.some((target) => target.id === selectedTargetId)
      ? selectedTargetId
      : (targets[0]?.id ?? 0)
  const [selectedIds, setSelectedIds] = useState<readonly number[]>([])
  const form = useForm<InputValues>({
    resolver: zodResolver(inputSchema),
    defaultValues: { student_number: "" },
  })
  const preview = useCurriculumMigrationPreviewMutation()
  const apply = useApplyCurriculumMigrationMutation()
  const previewData = preview.data

  // Re-select every candidate whenever a new preview arrives — adjusted
  // during render rather than in an effect, which cost an extra render.
  const [selectedForPreview, setSelectedForPreview] = useState(previewData)
  if (selectedForPreview !== previewData) {
    setSelectedForPreview(previewData)
    setSelectedIds(
      previewData?.credit_candidates.map((item) => item.equivalency_id) ?? [],
    )
  }

  if (targets.length === 0) return null

  const requestPreview = form.handleSubmit(async ({ student_number }) => {
    preview.reset()
    try {
      await preview.mutateAsync({
        curriculumId: targetId,
        studentNumber: student_number,
      })
    } catch {
      // Surfaced via preview.isError below.
    }
  })
  const toggle = (id: number, checked: boolean) => {
    setSelectedIds((current) =>
      checked
        ? [...new Set([...current, id])]
        : current.filter((selectedId) => selectedId !== id),
    )
  }
  const confirm = async () => {
    if (!previewData) return
    try {
      await apply.mutateAsync({
        curriculumId: targetId,
        studentId: previewData.student.id,
        equivalencyIds: selectedIds,
      })
      form.reset()
      preview.reset()
      setSelectedIds([])
    } catch {
      // Surfaced via apply.isError below.
    }
  }

  return (
    <section
      aria-label="Curriculum migration"
      className="grid gap-4 rounded-xl border bg-muted/20 p-4"
    >
      <div>
        <h2 className="font-heading text-lg font-medium">
          Curriculum transition
        </h2>
        <p className="text-sm text-muted-foreground">
          Move an eligible student to an active curriculum and choose only the
          old subjects to credit.
        </p>
      </div>
      <Field>
        <FieldLabel htmlFor="migration-target-curriculum">
          New active curriculum
        </FieldLabel>
        <Select
          value={String(targetId)}
          onValueChange={(value) => {
            setSelectedTargetId(Number(value))
            preview.reset()
            setSelectedIds([])
          }}
        >
          <SelectTrigger id="migration-target-curriculum" className="w-full">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {targets.map((target) => (
              <SelectItem key={target.id} value={String(target.id)}>
                {target.name} · source:{" "}
                {target.equivalency_source_curriculum_name ??
                  "Configured old curriculum"}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </Field>
      <form
        className="flex flex-wrap items-end gap-3"
        onSubmit={requestPreview}
      >
        <Field className="min-w-56 flex-1">
          <FieldLabel htmlFor="migration-student-number">
            Student number
          </FieldLabel>
          <Input
            id="migration-student-number"
            {...form.register("student_number")}
          />
          <FieldError>
            {form.formState.errors.student_number?.message}
          </FieldError>
        </Field>
        <Button type="submit" disabled={preview.isPending}>
          {preview.isPending ? "Checking…" : "Preview credits"}
        </Button>
      </form>
      {preview.isError && (
        <Alert variant="destructive">
          <AlertDescription>{messageFor(preview.error)}</AlertDescription>
        </Alert>
      )}
      {previewData && (
        <div className="grid gap-3 border-t pt-4">
          <p className="text-sm">
            Student <strong>{previewData.student.student_number}</strong> ·
            select the credits to apply.
          </p>
          {previewData.credit_candidates.length === 0 ? (
            <p className="text-sm text-muted-foreground">
              No mapped, passing old subjects are available to credit.
            </p>
          ) : (
            <div className="grid gap-2">
              {previewData.credit_candidates.map((candidate) => {
                const checked = selectedIds.includes(candidate.equivalency_id)
                return (
                  <label
                    key={candidate.equivalency_id}
                    className="flex cursor-pointer items-start gap-3 rounded-lg border bg-background p-3 text-sm"
                  >
                    <Checkbox
                      checked={checked}
                      onCheckedChange={(value) =>
                        toggle(candidate.equivalency_id, value === true)
                      }
                      aria-label={`Credit ${candidate.target_subject.code}`}
                    />
                    <span>
                      <strong>{candidate.source_subject.code}</strong> →{" "}
                      <strong>{candidate.target_subject.code}</strong>
                      <span className="block text-muted-foreground">
                        {candidate.source_subject.title} →{" "}
                        {candidate.target_subject.title} · locked grade{" "}
                        {candidate.source_completion.final_grade ?? "recorded"}
                      </span>
                    </span>
                  </label>
                )
              })}
            </div>
          )}
          {apply.isError && (
            <Alert variant="destructive">
              <AlertDescription>{messageFor(apply.error)}</AlertDescription>
            </Alert>
          )}
          {apply.isSuccess && (
            <Alert>
              <AlertDescription>
                Curriculum transition saved. Selected target subjects are now
                credited.
              </AlertDescription>
            </Alert>
          )}
          <div>
            <Button
              type="button"
              onClick={() => void confirm()}
              disabled={apply.isPending}
            >
              {apply.isPending
                ? "Migrating…"
                : "Confirm selected credits and migrate"}
            </Button>
          </div>
        </div>
      )}
    </section>
  )
}
