"use client"

import { useState } from "react"

import { Badge } from "@/features/components/ui/badge"
import { Button } from "@/features/components/ui/button"
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from "@/features/components/ui/collapsible"
import type { ScheduleGenerationWarning } from "@/features/schemas/schedule-generation-schema"

const CHIP_MESSAGE_TRUNCATE_LENGTH = 48

/** Snake-case warning types -> readable labels, no lookup table to keep in sync. */
function humanize(type: string): string {
  return type
    .split("_")
    .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
    .join(" ")
}

/** Copy per known warning type. Unrecognized types fall back to `humanize()`. */
const WARNING_GROUP_LABELS: Record<string, (count: number) => string> = {
  room_metadata_incomplete: (n) =>
    `${n} section${n === 1 ? "" : "s"} have incomplete room metadata`,
}

function groupLabel(type: string, count: number): string {
  return WARNING_GROUP_LABELS[type]?.(count) ?? `${humanize(type)} (${count})`
}

function chipLabel(warning: ScheduleGenerationWarning): string {
  if (warning.entity_id !== null) {
    return `#${warning.entity_id}`
  }
  return warning.message.length > CHIP_MESSAGE_TRUNCATE_LENGTH
    ? `${warning.message.slice(0, CHIP_MESSAGE_TRUNCATE_LENGTH)}…`
    : warning.message
}

export function GroupedWarningsList({
  warnings,
}: {
  warnings: ScheduleGenerationWarning[]
}) {
  const groups = new Map<string, ScheduleGenerationWarning[]>()
  for (const warning of warnings) {
    const bucket = groups.get(warning.type)
    if (bucket) {
      bucket.push(warning)
    } else {
      groups.set(warning.type, [warning])
    }
  }

  return (
    <div className="grid gap-2 rounded-lg border bg-muted/30 p-4 text-sm text-muted-foreground">
      {Array.from(groups.entries()).map(([type, group]) =>
        group.length === 1 && group[0].entity_id === null ? (
          <p key={type}>{group[0].message}</p>
        ) : (
          <WarningGroup key={type} type={type} warnings={group} />
        ),
      )}
    </div>
  )
}

function WarningGroup({
  type,
  warnings,
}: {
  type: string
  warnings: ScheduleGenerationWarning[]
}) {
  const [open, setOpen] = useState(false)

  return (
    <Collapsible open={open} onOpenChange={setOpen}>
      <div className="flex items-center justify-between gap-2">
        <span>{groupLabel(type, warnings.length)}</span>
        <CollapsibleTrigger asChild>
          <Button type="button" variant="ghost" size="sm">
            {open ? "Hide" : `Show ${warnings.length}`}
          </Button>
        </CollapsibleTrigger>
      </div>
      <CollapsibleContent className="flex flex-wrap gap-1.5 pt-2">
        {warnings.map((warning, index) => (
          <Badge key={`${type}-${index}`} variant="outline">
            {chipLabel(warning)}
          </Badge>
        ))}
      </CollapsibleContent>
    </Collapsible>
  )
}
