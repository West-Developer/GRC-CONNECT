"use client"

import { useState, type FormEvent } from "react"

import { useAuth } from "@/features/auth/use-auth"
import { AccordionCard } from "@/features/components/portal/accordion-card"
import { AsyncBoundary } from "@/features/components/portal/async-boundary"
import { AuditActorEntries } from "@/features/components/portal/audit-actor-entries"
import { Paginator } from "@/features/components/portal/paginator"
import { ScheduleDecisionControls } from "@/features/components/portal/schedule-decision-workspace"
import { WorkspacePage } from "@/features/components/portal/workspace-page"
import { Badge } from "@/features/components/ui/badge"
import { Button } from "@/features/components/ui/button"
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
} from "@/features/components/ui/card"
import { Field, FieldGroup, FieldLabel } from "@/features/components/ui/field"
import { Input } from "@/features/components/ui/input"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/features/components/ui/select"
import { useAuditActorsQuery } from "@/features/hooks/use-audit-logs"
import { useScheduleProposalsQuery } from "@/features/hooks/use-scheduling"
import { formatAuditTimestamp } from "@/features/lib/audit-presentation"
import {
  auditActions,
  auditableTypes,
  type AuditActorFilters,
} from "@/features/schemas/audit-schema"

const defaults: AuditActorFilters = { page: 1, per_page: 20 }
// Radix `Select.Item` reserves an empty-string value to mean "no selection";
// "all" is a safe sentinel since neither enum contains it (see ADR 0015).
const ALL_FILTER_VALUE = "all"

/**
 * The Registrar Head's audit screen (stakeholder Doc 14): one card per person
 * who has done something, each opening to their own records, and each record
 * opening to an old-versus-new comparison of what they changed.
 */
export function AuditLogsWorkspace() {
  const { session } = useAuth()
  const authorized =
    session?.role === "registrar_head" || session?.role === "super_admin"
  const [filters, setFilters] = useState<AuditActorFilters>(defaults)
  const [actionFilter, setActionFilter] = useState(ALL_FILTER_VALUE)
  const [entityTypeFilter, setEntityTypeFilter] = useState(ALL_FILTER_VALUE)
  const [fromDate, setFromDate] = useState("")
  const [toDate, setToDate] = useState("")
  const actorsQuery = useAuditActorsQuery(filters, authorized)
  const proposalsQuery = useScheduleProposalsQuery({ enabled: authorized })
  const combinedQuery = {
    isPending: actorsQuery.isPending || proposalsQuery.isPending,
    isError: actorsQuery.isError || proposalsQuery.isError,
    error: actorsQuery.error ?? proposalsQuery.error,
    data: actorsQuery.data,
    refetch: () => {
      void actorsQuery.refetch()
      void proposalsQuery.refetch()
    },
  }
  const apply = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    const action = auditActions.find((value) => value === actionFilter)
    const auditableType = auditableTypes.find(
      (value) => value === entityTypeFilter,
    )
    setFilters({
      action,
      auditable_type: auditableType,
      from: fromDate || undefined,
      to: toDate || undefined,
      page: 1,
      per_page: 20,
    })
  }
  // The same filters narrow each person's records once their card is open.
  const entryFilters: AuditActorFilters = {
    action: filters.action,
    auditable_type: filters.auditable_type,
    from: filters.from,
    to: filters.to,
  }

  return (
    <WorkspacePage
      title="Audit logs"
      description="See who changed what. Open a person to read their activity, then a record to compare the old and new values."
      unauthorized={!authorized}
      lastUpdated={actorsQuery.dataUpdatedAt}
    >
      <form onSubmit={apply}>
        <FieldGroup className="grid gap-3 md:grid-cols-3">
          <Field>
            <FieldLabel htmlFor="audit-filter-action">Action</FieldLabel>
            <Select value={actionFilter} onValueChange={setActionFilter}>
              <SelectTrigger id="audit-filter-action" className="w-full">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value={ALL_FILTER_VALUE}>All actions</SelectItem>
                {auditActions.map((action) => (
                  <SelectItem key={action} value={action}>
                    {action}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </Field>
          <Field>
            <FieldLabel htmlFor="audit-filter-entity-type">
              Entity type
            </FieldLabel>
            <Select
              value={entityTypeFilter}
              onValueChange={setEntityTypeFilter}
            >
              <SelectTrigger id="audit-filter-entity-type" className="w-full">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value={ALL_FILTER_VALUE}>
                  All entity types
                </SelectItem>
                {auditableTypes.map((auditableType) => (
                  <SelectItem key={auditableType} value={auditableType}>
                    {auditableType}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </Field>
          <div className="hidden md:block" aria-hidden="true" />
          <Field>
            <FieldLabel htmlFor="audit-filter-from">From</FieldLabel>
            <Input
              id="audit-filter-from"
              type="date"
              value={fromDate}
              onChange={(event) => setFromDate(event.target.value)}
            />
          </Field>
          <Field>
            <FieldLabel htmlFor="audit-filter-to">To</FieldLabel>
            <Input
              id="audit-filter-to"
              type="date"
              value={toDate}
              onChange={(event) => setToDate(event.target.value)}
            />
          </Field>
          <Button type="submit" className="self-end">
            Apply audit filters
          </Button>
        </FieldGroup>
      </form>
      <AsyncBoundary
        query={combinedQuery}
        isEmpty={(actors) => actors.data.length === 0}
        emptyMessage="No audit records match these filters."
        loadingLabel="Loading audit logs…"
      >
        {(actors) => (
          <>
            <div className="grid gap-3">
              {actors.data.map((person) => (
                <AccordionCard
                  key={person.actor_user_id}
                  id={`audit-actor-${person.actor_user_id}`}
                  title={person.actor_name}
                  defaultOpen={false}
                  description={`Last activity ${formatAuditTimestamp(person.last_activity_at)}`}
                  badges={
                    <>
                      <Badge variant="outline">{person.actor_role_label}</Badge>
                      <Badge variant="secondary">
                        {person.entries_count}{" "}
                        {person.entries_count === 1 ? "record" : "records"}
                      </Badge>
                    </>
                  }
                >
                  <AuditActorEntries
                    actorUserId={person.actor_user_id}
                    filters={entryFilters}
                  />
                </AccordionCard>
              ))}
            </div>
            <Paginator
              currentPage={actors.meta.current_page}
              lastPage={actors.meta.last_page}
              onPageChange={(page) =>
                setFilters((value) => ({ ...value, page }))
              }
            />
            <Card>
              <CardHeader>
                <CardTitle level={2}>Published proposal closure</CardTitle>
              </CardHeader>
              <CardContent>
                <ScheduleDecisionControls
                  actorRole="registrar_head"
                  proposals={proposalsQuery.data ?? []}
                />
              </CardContent>
            </Card>
          </>
        )}
      </AsyncBoundary>
    </WorkspacePage>
  )
}
