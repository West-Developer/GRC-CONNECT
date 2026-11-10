"use client"

import { Alert, AlertDescription } from "@/features/components/ui/alert"
import { Button } from "@/features/components/ui/button"
import { Checkbox } from "@/features/components/ui/checkbox"
import type { AdmissionRequirementSelection } from "@/features/schemas/admission-requirements-schema"

/**
 * The Admission requirements Admission ticks off while creating a student account (stakeholder
 * Doc 20): the list for the student's type, one checkbox each. It replaces the single
 * "Requirements submitted and verified" confirmation. Some may still be missing: the account is
 * created anyway and the rest stay on the student's checklist for Admission to tick later.
 */
export function AdmissionIntakeRequirements({
  state,
  selection,
  checkedIds,
  onChange,
  disabled = false,
}: {
  state: "idle" | "loading" | "error" | "ready"
  selection: AdmissionRequirementSelection | undefined
  checkedIds: readonly number[]
  onChange: (ids: number[]) => void
  disabled?: boolean
}) {
  if (state === "idle") {
    return (
      <p role="status" className="rounded-md border border-dashed p-4 text-sm text-muted-foreground">
        Choose the student type to see the Admission requirements that apply.
      </p>
    )
  }
  if (state === "loading") {
    return (
      <p role="status" className="text-sm text-muted-foreground">
        Loading the Admission requirements…
      </p>
    )
  }
  if (state === "error" || !selection) {
    return (
      <Alert variant="destructive">
        <AlertDescription>
          The Admission requirements could not be loaded. Reload the page and
          try again.
        </AlertDescription>
      </Alert>
    )
  }

  const allIds = selection.categories.flatMap((group) =>
    group.items.map((item) => item.requirement_type_id),
  )
  const checked = new Set(checkedIds)
  const checkedCount = allIds.filter((id) => checked.has(id)).length

  const toggle = (id: number, value: boolean) => {
    const next = new Set(checked)
    if (value) {
      next.add(id)
    } else {
      next.delete(id)
    }
    onChange([...next])
  }

  return (
    <div className="grid gap-4 rounded-md border p-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <p className="font-medium">Admission requirements</p>
          <p className="text-sm text-muted-foreground">
            Check each requirement the student handed in. You can still create
            the account if some are missing; they stay on the student&apos;s
            checklist.
          </p>
        </div>
        <div className="flex items-center gap-3">
          <p className="text-sm font-medium" aria-live="polite">
            {checkedCount} of {allIds.length} checked
          </p>
          <Button
            type="button"
            variant="outline"
            size="sm"
            disabled={disabled}
            onClick={() =>
              onChange(checkedCount === allIds.length ? [] : allIds)
            }
          >
            {checkedCount === allIds.length ? "Clear all" : "Check all"}
          </Button>
        </div>
      </div>
      {selection.categories.map((group) => (
        <section
          key={group.category}
          aria-label={group.label}
          className="grid gap-2"
        >
          <h3 className="border-b pb-1.5 text-sm font-semibold">
            {group.label}
          </h3>
          <ul className="grid gap-2 sm:grid-cols-2">
            {group.items.map((item) => {
              const inputId = `intake-requirement-${item.requirement_type_id}`
              return (
                <li key={item.requirement_type_id}>
                  <label
                    htmlFor={inputId}
                    className="flex cursor-pointer items-start gap-3 rounded-md border p-3 text-sm has-[:checked]:border-primary/40 has-[[data-state=checked]]:border-primary/40"
                  >
                    <Checkbox
                      id={inputId}
                      checked={checked.has(item.requirement_type_id)}
                      disabled={disabled}
                      onCheckedChange={(value) =>
                        toggle(item.requirement_type_id, value === true)
                      }
                    />
                    <span>{item.name}</span>
                  </label>
                </li>
              )
            })}
          </ul>
        </section>
      ))}
    </div>
  )
}
