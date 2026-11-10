"use client"

import { ArrowRight } from "lucide-react"

import { formatAuditValue } from "@/features/lib/audit-presentation"
import type { AuditChange } from "@/features/schemas/audit-schema"

/**
 * One audit record's fields compared old against new: what changed first, the
 * old value struck through in red and the new one in green, and the fields
 * that kept their value tucked away, muted, so a long record stays readable.
 */
export function AuditChangeCompare({
  changes,
}: {
  changes: readonly AuditChange[]
}) {
  const changed = changes.filter((change) => change.changed)
  const unchanged = changes.filter((change) => !change.changed)

  if (changes.length === 0) {
    return (
      <p className="text-sm text-muted-foreground">
        No values were recorded for this action.
      </p>
    )
  }

  return (
    <div className="grid gap-2">
      {changed.length > 0 ? (
        <ul
          aria-label="Changed fields"
          className="grid gap-1.5 rounded-md border p-2 text-sm"
        >
          {changed.map((change) => (
            <li
              key={change.field}
              className="flex flex-wrap items-center gap-x-2 gap-y-0.5"
            >
              <span className="w-32 shrink-0 font-medium">{change.label}</span>
              <span className="rounded bg-destructive/10 px-1.5 text-destructive line-through">
                {formatAuditValue(change.old)}
              </span>
              <ArrowRight
                className="size-3.5 text-muted-foreground"
                aria-label="changed to"
              />
              <span className="rounded bg-emerald-500/10 px-1.5 font-medium text-emerald-700 dark:text-emerald-400">
                {formatAuditValue(change.new)}
              </span>
            </li>
          ))}
        </ul>
      ) : (
        <p className="text-sm text-muted-foreground">
          No field changed value in this record.
        </p>
      )}

      {unchanged.length > 0 && (
        <details className="text-sm text-muted-foreground">
          <summary className="cursor-pointer">
            Show {unchanged.length} unchanged{" "}
            {unchanged.length === 1 ? "field" : "fields"}
          </summary>
          <ul
            aria-label="Unchanged fields"
            className="mt-1.5 grid gap-1 rounded-md bg-muted/40 p-2"
          >
            {unchanged.map((change) => (
              <li key={change.field} className="flex flex-wrap gap-x-2">
                <span className="w-32 shrink-0">{change.label}</span>
                <span>{formatAuditValue(change.new)}</span>
              </li>
            ))}
          </ul>
        </details>
      )}
    </div>
  )
}
