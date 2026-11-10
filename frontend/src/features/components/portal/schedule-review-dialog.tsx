"use client"

import { useMemo, useState } from "react"
import { CalendarDays, ClipboardListIcon } from "lucide-react"

import type { UserRole } from "@/features/auth/roles"
import { SectionScheduleCalendarDialog } from "@/features/components/portal/section-schedule-calendar-dialog"
import { Alert, AlertDescription } from "@/features/components/ui/alert"
import { Badge } from "@/features/components/ui/badge"
import { Button } from "@/features/components/ui/button"
import { Card } from "@/features/components/ui/card"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/features/components/ui/dialog"
import {
  Empty,
  EmptyContent,
  EmptyDescription,
  EmptyHeader,
  EmptyMedia,
  EmptyTitle,
} from "@/features/components/ui/empty"
import { Skeleton } from "@/features/components/ui/skeleton"
import {
  Tabs,
  TabsContent,
  TabsList,
  TabsTrigger,
} from "@/features/components/ui/tabs"
import { useScheduleReviewSectionsQuery } from "@/features/hooks/use-scheduling"
import { scheduleProposalPresentation } from "@/features/lib/schedule-status"
import { programCodeFromSection } from "@/features/lib/section-program-code"
import type {
  ScheduleAction,
  ScheduleProposal,
  ScheduleReviewSection,
} from "@/features/schemas/scheduling-schema"
import { availableScheduleActions } from "@/features/services/scheduling-service"

interface ScheduleReviewDialogProps {
  actorRole: UserRole
  proposal: ScheduleProposal | null
  decisionPending: boolean
  /** Hides every decision button, for roles that only look at a schedule. */
  readOnly?: boolean
  onOpenChange: (open: boolean) => void
  onDecision: (proposal: ScheduleProposal, action: ScheduleAction) => void
}

const collator = new Intl.Collator(undefined, {
  numeric: true,
  sensitivity: "base",
})

const reviewActionLabels: Partial<Record<ScheduleAction, string>> = {
  dean_approve: "Approve schedule",
  dean_return: "Return with notes",
  executive_return: "Return with notes",
  publish: "Publish schedule",
  close: "Close proposal",
}

function isReturnAction(action: ScheduleAction) {
  return action === "dean_return" || action === "executive_return"
}

function ScheduleReviewLoading() {
  return (
    <div className="grid gap-3" role="status" aria-label="Loading submitted schedule">
      <Skeleton className="h-8 w-72 max-w-full" />
      <div className="grid gap-2">
        <Skeleton className="h-10" />
        <Skeleton className="h-10" />
        <Skeleton className="h-10" />
        <Skeleton className="h-10" />
      </div>
    </div>
  )
}

function ScheduleSectionTabs({
  proposalId,
  sections,
  collegeLabel,
}: {
  proposalId: number
  sections: readonly ScheduleReviewSection[]
  collegeLabel?: string
}) {
  const groupedPrograms = useMemo(() => {
    const bySection = new Map<string, ScheduleReviewSection[]>()

    for (const section of sections)
      bySection.set(section.section_code, [
        ...(bySection.get(section.section_code) ?? []),
        section,
      ])

    const sectionGroups = [...bySection.entries()]
      .sort(([left], [right]) => collator.compare(left, right))
      .map(([sectionCode, subjects]) => ({ sectionCode, subjects }))

    const byProgram = new Map<string, typeof sectionGroups>()
    for (const group of sectionGroups)
      byProgram.set(programCodeFromSection(group.sectionCode), [
        ...(byProgram.get(programCodeFromSection(group.sectionCode)) ?? []),
        group,
      ])

    return [...byProgram.entries()]
      .sort(([left], [right]) => collator.compare(left, right))
      .map(([program, sectionGroups]) => ({ program, sectionGroups }))
  }, [sections])

  const [activeProgram, setActiveProgram] = useState(
    groupedPrograms[0]?.program ?? "",
  )
  const [viewingSection, setViewingSection] = useState<{
    sectionCode: string
    subjects: readonly ScheduleReviewSection[]
  } | null>(null)

  if (groupedPrograms.length === 0)
    return (
      <Empty>
        <EmptyHeader>
          <EmptyMedia variant="icon">
            <ClipboardListIcon aria-hidden="true" />
          </EmptyMedia>
          <EmptyTitle>No schedule rows submitted</EmptyTitle>
          <EmptyDescription>
            This proposal does not contain any subject schedules to review.
          </EmptyDescription>
        </EmptyHeader>
        <EmptyContent>
          <p>Return to the review queue and confirm the submitted proposal.</p>
        </EmptyContent>
      </Empty>
    )

  return (
    <>
      <Tabs
        key={proposalId}
        value={activeProgram}
        onValueChange={setActiveProgram}
        className="min-w-0 gap-4"
      >
        <div className="pb-1">
          <TabsList aria-label="Programs">
            {groupedPrograms.map(({ program, sectionGroups }) => (
              <TabsTrigger key={program} value={program}>
                {program}
                <Badge variant="outline" aria-hidden="true">
                  {sectionGroups.length}
                </Badge>
              </TabsTrigger>
            ))}
          </TabsList>
        </div>
        {groupedPrograms.map(({ program, sectionGroups }) => (
          <TabsContent key={program} value={program} className="grid gap-4 pt-1">
            <div className="grid gap-4 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
              {sectionGroups.map(({ sectionCode, subjects }) => {
                const totalUnits = subjects.reduce((sum, s) => sum + (s.units ?? 0), 0)

                return (
                  <Card
                    key={sectionCode}
                    role="article"
                    aria-label={`${sectionCode} section`}
                    className="group flex flex-col justify-between rounded-xl border p-4 text-center transition-all duration-200 hover:border-primary/80 hover:shadow-md hover:bg-muted/10 cursor-pointer active:scale-[0.99]"
                    onClick={() => setViewingSection({ sectionCode, subjects })}
                  >
                    <div className="flex flex-col items-center gap-2">
                      <div className="text-2xl font-bold tracking-tight text-foreground group-hover:text-primary transition-colors">
                        {sectionCode}
                      </div>
                      <div className="flex flex-wrap items-center justify-center gap-1.5">
                        <Badge variant="secondary">
                          {subjects.length} {subjects.length === 1 ? "subject" : "subjects"}
                        </Badge>
                        {totalUnits > 0 && (
                          <Badge variant="outline">{totalUnits} units</Badge>
                        )}
                      </div>
                      <span className="text-xs text-muted-foreground">
                        {subjects.length} subject{subjects.length === 1 ? "" : "s"}
                      </span>
                    </div>

                    <div className="mt-4 border-t pt-3">
                      <Button
                        type="button"
                        variant="default"
                        className="w-full gap-1.5"
                        onClick={(e) => {
                          e.stopPropagation()
                          setViewingSection({ sectionCode, subjects })
                        }}
                      >
                        <CalendarDays data-icon="inline-start" aria-hidden="true" />
                        View schedule
                      </Button>
                    </div>
                  </Card>
                )
              })}
            </div>
          </TabsContent>
        ))}
      </Tabs>

      <SectionScheduleCalendarDialog
        open={viewingSection !== null}
        onOpenChange={(open) => {
          if (!open) setViewingSection(null)
        }}
        title={`${viewingSection?.sectionCode ?? ""} Schedule`}
        subtitle={`${collegeLabel ?? "Department"} · Proposal Review`}
        items={viewingSection?.subjects ?? []}
        disabled={true}
        defaultView="table"
      />
    </>
  )
}

export function ScheduleReviewDialog({
  actorRole,
  proposal,
  decisionPending,
  readOnly = false,
  onOpenChange,
  onDecision,
}: ScheduleReviewDialogProps) {
  const sectionsQuery = useScheduleReviewSectionsQuery(proposal?.id ?? null)
  const sections = sectionsQuery.data ?? []
  const sectionCount = new Set(sections.map((section) => section.section_code))
    .size
  const actions =
    proposal && !readOnly ? availableScheduleActions(actorRole, proposal) : []
  const presentation = proposal ? scheduleProposalPresentation(proposal) : null
  const priorReturn = proposal
    ? [...(proposal.decision_history ?? [])]
        .reverse()
        .find((decision) => decision.action === "dean_return" || decision.action === "executive_return")
    : undefined

  return (
    <Dialog open={proposal !== null} onOpenChange={onOpenChange}>
      <DialogContent className="grid max-h-[100dvh] grid-rows-[auto_minmax(0,1fr)_auto] gap-0 overflow-hidden rounded-none p-0 sm:max-h-[90dvh] sm:max-w-6xl sm:rounded-xl">
        <DialogHeader className="px-4 pt-4 pr-12 sm:px-6 sm:pt-6 sm:pr-14">
          <div className="flex flex-wrap items-start justify-between gap-3">
            <div className="grid gap-1">
              <DialogTitle>
                Review schedule · {proposal?.college_label ?? proposal?.college?.toUpperCase() ?? "Department"}
              </DialogTitle>
              <DialogDescription>
                {proposal?.academic_term_label ?? "Academic term"} · Submitted by {proposal?.submitted_by_name ?? "Program Head"}
              </DialogDescription>
            </div>
            {proposal && presentation && (
              <Badge variant={presentation.badgeVariant}>{presentation.label}</Badge>
            )}
          </div>
          {!sectionsQuery.isPending && !sectionsQuery.isError && (
            <div className="flex flex-wrap gap-2 pt-2">
              <Badge variant="outline">
                {sectionCount} block section{sectionCount === 1 ? "" : "s"}
              </Badge>
              <Badge variant="outline">
                {sections.length} subject schedule{sections.length === 1 ? "" : "s"}
              </Badge>
            </div>
          )}
          {priorReturn && (
            <Alert className="mt-2">
              <AlertDescription>
                <span className="font-medium text-foreground">Previously returned</span> by {priorReturn.actor_name}
                {priorReturn.notes ? `: ${priorReturn.notes}` : "."}
              </AlertDescription>
            </Alert>
          )}
        </DialogHeader>

        <div className="min-h-0 overflow-y-auto px-4 py-4 sm:px-6">
          {sectionsQuery.isPending ? (
            <ScheduleReviewLoading />
          ) : sectionsQuery.isError ? (
            <Alert variant="destructive">
              <AlertDescription className="flex flex-wrap items-center justify-between gap-3">
                <span>The submitted schedule could not be loaded.</span>
                <Button
                  type="button"
                  size="sm"
                  variant="outline"
                  onClick={() => void sectionsQuery.refetch()}
                >
                  Try again
                </Button>
              </AlertDescription>
            </Alert>
          ) : proposal ? (
            <ScheduleSectionTabs
              key={proposal.id}
              proposalId={proposal.id}
              sections={sections}
              collegeLabel={proposal.college_label ?? proposal.college?.toUpperCase()}
            />
          ) : null}
        </div>

        <DialogFooter
          showCloseButton
          className="m-0 rounded-none px-4 pb-4 [&_button]:w-full sm:px-6 sm:pb-6 sm:[&_button]:w-auto"
        >
          {proposal &&
            actions.map((action) => (
              <Button
                key={action}
                type="button"
                variant={isReturnAction(action) ? "outline" : "default"}
                disabled={decisionPending}
                onClick={() => onDecision(proposal, action)}
              >
                {reviewActionLabels[action] ?? "Continue"}
              </Button>
            ))}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
