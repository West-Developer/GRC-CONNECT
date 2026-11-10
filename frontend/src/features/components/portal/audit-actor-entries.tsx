"use client"

import { useState } from "react"

import { AsyncBoundary } from "@/features/components/portal/async-boundary"
import { AuditChangeCompare } from "@/features/components/portal/audit-change-compare"
import { Paginator } from "@/features/components/portal/paginator"
import { Badge } from "@/features/components/ui/badge"
import { useAuditLogsQuery } from "@/features/hooks/use-audit-logs"
import {
  formatAuditTimestamp,
  humanizeAuditAction,
  humanizeAuditTarget,
} from "@/features/lib/audit-presentation"
import type {
  AuditActorFilters,
  AuditLog,
} from "@/features/schemas/audit-schema"

const PAGE_SIZE = 10

function changeSummary(log: AuditLog): string {
  const changed = log.changes.filter((change) => change.changed).length
  if (log.changes.length === 0) return "No values recorded"
  if (changed === 0) return "No field changed value"
  return `${changed} ${changed === 1 ? "change" : "changes"}`
}

/**
 * One person's audit records, newest first, opened from their card on the
 * audit screen. Mounted only when the card is open, so the records are not
 * fetched until someone asks for them.
 */
export function AuditActorEntries({
  actorUserId,
  filters,
}: {
  actorUserId: number
  filters: AuditActorFilters
}) {
  const [page, setPage] = useState(1)
  const entriesQuery = useAuditLogsQuery({
    ...filters,
    actor_user_id: actorUserId,
    page,
    per_page: PAGE_SIZE,
  })

  return (
    <AsyncBoundary
      query={entriesQuery}
      isEmpty={(audit) => audit.data.length === 0}
      emptyMessage="No records match these filters for this person."
      loadingLabel="Loading this person's activity…"
    >
      {(audit) => (
        <div className="grid gap-3">
          <ul className="grid gap-3">
            {audit.data.map((log) => (
              <li key={log.id} className="grid gap-2 rounded-md border p-3">
                <div className="flex flex-wrap items-center gap-2">
                  <Badge variant="secondary">
                    {humanizeAuditAction(log.action)}
                  </Badge>
                  <span className="text-sm font-medium">
                    {humanizeAuditTarget(log.auditable_type, log.auditable_id)}
                  </span>
                  {log.acting_role_label && (
                    <Badge variant="outline">
                      Acting as {log.acting_role_label}
                      {log.acting_college
                        ? ` · ${log.acting_college.toUpperCase()}`
                        : ""}
                    </Badge>
                  )}
                  <span className="ml-auto text-xs text-muted-foreground">
                    {formatAuditTimestamp(log.created_at)}
                  </span>
                </div>
                {log.reason && (
                  <p className="text-sm">
                    <span className="font-medium">Reason:</span> {log.reason}
                  </p>
                )}
                <details>
                  <summary className="cursor-pointer text-sm text-muted-foreground">
                    {changeSummary(log)} — compare old and new
                  </summary>
                  <div className="mt-2">
                    <AuditChangeCompare changes={log.changes} />
                  </div>
                </details>
              </li>
            ))}
          </ul>
          <Paginator
            currentPage={audit.meta.current_page}
            lastPage={audit.meta.last_page}
            onPageChange={setPage}
          />
        </div>
      )}
    </AsyncBoundary>
  )
}
