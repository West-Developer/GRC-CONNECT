"use client"

import { ArrowLeft, Construction, ShieldX } from "lucide-react"
import Link from "next/link"
import type { ReactNode } from "react"

import { useAuth } from "@/features/auth/use-auth"
import { AsyncBoundary } from "@/features/components/portal/async-boundary"
import { WorkspacePage } from "@/features/components/portal/workspace-page"
import {
  Alert,
  AlertDescription,
  AlertTitle,
} from "@/features/components/ui/alert"
import { Badge } from "@/features/components/ui/badge"
import { Button } from "@/features/components/ui/button"
import {
  Empty,
  EmptyContent,
  EmptyDescription,
  EmptyHeader,
  EmptyMedia,
  EmptyTitle,
} from "@/features/components/ui/empty"
import {
  getRoleModule,
  rolePortalDefinitions,
} from "@/features/portal/role-capabilities"
import {
  isConnectedModuleId,
  connectedModuleRegistry,
} from "@/features/portal/module-registry"
import { useAcademicTermWorkflowsQuery } from "@/features/hooks/use-academic-term-workflows"
import { useAcademicTermsQuery } from "@/features/hooks/use-reference-data"
import { getActiveAcademicTerm } from "@/features/services/reference-data-service"

const programChairGatedModules = new Set([
  "sections-schedules",
  "faculty-assignment",
  "schedule-proposals",
])

function ProgramChairModuleGate({ children }: { children: ReactNode }) {
  const { session } = useAuth()
  const termsQuery = useAcademicTermsQuery()
  const currentTerm = getActiveAcademicTerm(termsQuery.data)
  const workflowsQuery = useAcademicTermWorkflowsQuery(
    currentTerm?.id ?? 0,
    session?.college != null && currentTerm !== null,
  )

  const workflow = workflowsQuery.data?.find(
    (item) => item.college === session?.college,
  )
  const query = {
    isPending:
      termsQuery.isPending ||
      (currentTerm !== null && workflowsQuery.isPending),
    isError: termsQuery.isError || workflowsQuery.isError,
    error: termsQuery.error ?? workflowsQuery.error,
    data: workflow ?? null,
    refetch: () => {
      void termsQuery.refetch()
      void workflowsQuery.refetch()
    },
  }

  if (
    workflow &&
    (workflow.stage === "schedule_preparation" ||
      workflow.stage === "for_dean_approval")
  ) {
    return <>{children}</>
  }

  return (
    <WorkspacePage
      title="Enrollment step in progress"
      description="Supporting pages open after the Program Head completes the manual Enrollment steps."
    >
      <AsyncBoundary query={query} loadingLabel="Checking Enrollment progress…">
        {(currentWorkflow) => (
          <Alert>
            <AlertDescription>
              {currentWorkflow
                ? `This page is locked while Enrollment is in ${currentWorkflow.stage_label}.`
                : "Waiting for Registrar to create the next school year and semester."}{" "}
              Return to Enrollment to continue the next step.
            </AlertDescription>
          </Alert>
        )}
      </AsyncBoundary>
    </WorkspacePage>
  )
}

export function PortalModulePage({ moduleId }: { moduleId: string }) {
  const { session } = useAuth()

  if (!session) {
    return null
  }

  const definition = rolePortalDefinitions[session.role]
  const module = getRoleModule(session.role, moduleId)

  if (!module) {
    return (
      <main className="portal-module-page">
        <Empty
          className="portal-module-empty"
          role="region"
          aria-label="Unavailable portal module"
        >
          <EmptyHeader>
            <EmptyMedia variant="icon">
              <ShieldX aria-hidden="true" />
            </EmptyMedia>
            <Badge variant="outline">{definition.roleLabel}</Badge>
            <EmptyTitle role="heading" aria-level={1}>
              Portal module not found
            </EmptyTitle>
            <EmptyDescription>
              This destination is not assigned to your signed-in role.
            </EmptyDescription>
          </EmptyHeader>
          <EmptyContent>
            <p>
              Use the role navigation or return to the overview to continue
              within your assigned workspace.
            </p>
            <Button asChild>
              <Link href="/portal">
                <ArrowLeft data-icon="inline-start" aria-hidden="true" />
                Return to GRC Connect
              </Link>
            </Button>
          </EmptyContent>
        </Empty>
      </main>
    )
  }

  const Icon = module.icon
  const ModuleComponent = isConnectedModuleId(module.id)
    ? connectedModuleRegistry[module.id]
    : null

  if (ModuleComponent) {
    // The workspace's own WorkspacePage renders the page's sole header (a
    // real <h1> since Phase 8b) and labelled region — this shell no longer
    // duplicates it with a second header sourced from the module registry.
    // Module label/description remain in use for navigation (sidebar,
    // breadcrumb) only. See ADR 0015.
    const moduleContent = <ModuleComponent />
    const gatedContent =
      session.role === "program_chair" &&
      session.college != null &&
      programChairGatedModules.has(module.id) ? (
        <ProgramChairModuleGate>{moduleContent}</ProgramChairModuleGate>
      ) : (
        moduleContent
      )

    return (
      <main className="portal-module-page portal-module-page--connected">
        {gatedContent}
        <Button
          asChild
          variant="outline"
          className="portal-module-page__return"
        >
          <Link href="/portal">
            <ArrowLeft data-icon="inline-start" aria-hidden="true" />
            Return to GRC Connect
          </Link>
        </Button>
      </main>
    )
  }

  return (
    <main className="portal-module-page">
      <Empty
        className="portal-module-empty"
        role="region"
        aria-label={`${module.label} planned capability`}
      >
        <EmptyHeader>
          <EmptyMedia variant="icon">
            <Icon aria-hidden="true" />
          </EmptyMedia>
          <Badge variant="outline">{definition.roleLabel}</Badge>
          <EmptyTitle role="heading" aria-level={1}>
            {module.label}
          </EmptyTitle>
          <EmptyDescription>{module.description}</EmptyDescription>
        </EmptyHeader>
        <EmptyContent>
          <Alert className="portal-module-warning">
            <Construction aria-hidden="true" />
            <AlertTitle role="heading" aria-level={2}>
              Planned capability
            </AlertTitle>
            <AlertDescription>
              This workspace is being prepared for a future GRC Connect release.
            </AlertDescription>
          </Alert>
          <Button asChild variant="outline">
            <Link href="/portal">
              <ArrowLeft data-icon="inline-start" aria-hidden="true" />
              Return to GRC Connect
            </Link>
          </Button>
        </EmptyContent>
      </Empty>
    </main>
  )
}
