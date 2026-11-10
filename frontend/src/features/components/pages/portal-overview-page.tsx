"use client"

import {
  ArrowUpRight,
  CalendarDays,
  CircleCheck,
  CircleDashed,
  ServerCrash,
} from "lucide-react"
import Link from "next/link"

import { useAuth } from "@/features/auth/use-auth"
import { GrcBrand } from "@/features/components/common/grc-brand"
import { EnrollmentAvailabilityBanner } from "@/features/components/portal/enrollment-availability-banner"
import { StudentQueueLivePanel } from "@/features/components/queue/student-queue-live-panel"
import {
  Alert,
  AlertDescription,
  AlertTitle,
} from "@/features/components/ui/alert"
import { Badge } from "@/features/components/ui/badge"
import { Button } from "@/features/components/ui/button"
import {
  Card,
  CardAction,
  CardContent,
  CardDescription,
  CardFooter,
  CardHeader,
  CardTitle,
} from "@/features/components/ui/card"
import { useEnrollmentScheduleQuery } from "@/features/hooks/use-enrollment-windows"
import { useHealthQuery } from "@/features/hooks/use-health-query"
import { useAcademicTermsQuery } from "@/features/hooks/use-reference-data"
import { useStudentQueueQuery } from "@/features/hooks/use-student-queue"
import { cn } from "@/features/lib/utils"
import { isConnectedModuleId } from "@/features/portal/module-registry"
import { rolePortalDefinitions } from "@/features/portal/role-capabilities"
import {
  formatAcademicTerm,
  getActiveAcademicTerm,
} from "@/features/services/reference-data-service"

export function GrcConnectPage() {
  const { session } = useAuth()
  const healthQuery = useHealthQuery()
  const academicTermsQuery = useAcademicTermsQuery({
    enabled: session !== null,
  })
  const activeAcademicTerm = getActiveAcademicTerm(academicTermsQuery.data)
  const isStudent = session?.role === "student"
  const scheduleQuery = useEnrollmentScheduleQuery(
    activeAcademicTerm?.id ?? null,
    isStudent,
  )
  const studentQueueQuery = useStudentQueueQuery({
    viewerId: session?.userId ?? null,
    enabled: isStudent,
  })

  if (!session) {
    return null
  }

  const definition = rolePortalDefinitions[session.role]
  // A nested module (the Program Head's Irregular Advising and Credit Mappings) is reached from its parent's page.
  const topLevelModules = definition.modules.filter(
    (module) => module.nestedUnder === undefined,
  )
  const availableModules = topLevelModules.filter((module) =>
    isConnectedModuleId(module.id),
  )
  const plannedModules = topLevelModules.filter(
    (module) => !isConnectedModuleId(module.id),
  )
  const primaryModule = availableModules[0]

  return (
    <main className="grc-connect">
      <section className="grc-connect-hero" aria-labelledby="grc-connect-title">
        <div className="grc-connect-hero__identity">
          <GrcBrand compact />
          <p>TOUCHING HEARTS, RENEWING MINDS, TRANSFORMING LIVES</p>
        </div>
        <div className="grc-connect-hero__copy">
          <h1 id="grc-connect-title">GRC Connect</h1>
          <span>{definition.welcomeHeading}</span>
        </div>
        <div className="grc-connect-hero__action">
          <p>Start here</p>
          {primaryModule ? (
            <>
              <strong>{primaryModule.label}</strong>
              <Button asChild variant="secondary">
                <Link href={`/portal/${primaryModule.id}`}>
                  Open {primaryModule.label} workspace
                  <ArrowUpRight data-icon="inline-end" aria-hidden="true" />
                </Link>
              </Button>
            </>
          ) : (
            <span>Your role has no live workspace at the moment.</span>
          )}
        </div>
      </section>

      <section aria-label={`${definition.roleLabel} GRC Connect modules`}>
        <div className="portal-section-heading">
          <div>
            <p className="eyebrow">Your GRC workspace</p>
            <h2>Available workspaces</h2>
          </div>
          <p>
            Live tools assigned to your role for the current enrollment process.
          </p>
        </div>

        <div
          className={cn(
            "portal-module-grid",
            availableModules.length === 2 && "portal-module-grid--pair",
          )}
        >
          {availableModules.map((module, index) => {
            const Icon = module.icon
            const isPrimary = index === 0
            const actionLabel = isPrimary
              ? `Continue to ${module.label}`
              : `Open ${module.label}`

            return (
              <Card
                key={module.id}
                className={
                  isPrimary
                    ? "portal-module-card portal-module-card--primary"
                    : "portal-module-card"
                }
              >
                <CardHeader>
                  <span className="portal-module-card__index">
                    {String(index + 1).padStart(2, "0")}
                  </span>
                  <CardAction>
                    <Icon aria-hidden="true" />
                  </CardAction>
                  <CardTitle level={3}>{module.label}</CardTitle>
                  <CardDescription>{module.description}</CardDescription>
                </CardHeader>
                <CardFooter>
                  <Button
                    asChild
                    variant={isPrimary ? "default" : "ghost"}
                    size="sm"
                  >
                    <Link href={`/portal/${module.id}`}>
                      {actionLabel}
                      <ArrowUpRight data-icon="inline-end" aria-hidden="true" />
                    </Link>
                  </Button>
                </CardFooter>
              </Card>
            )
          })}
        </div>
      </section>

      {plannedModules.length > 0 && (
        <section
          className="portal-planned-capabilities"
          aria-label={`${definition.roleLabel} planned capabilities`}
        >
          <div className="portal-section-heading">
            <div>
              <p className="eyebrow">In development</p>
              <h2>Planned capabilities</h2>
            </div>
            <p>
              These role-relevant tools are being prepared and are not yet
              available for day-to-day enrollment work.
            </p>
          </div>

          <div className="portal-module-grid">
            {plannedModules.map((module) => {
              const Icon = module.icon

              return (
                <Card
                  key={module.id}
                  className="portal-module-card portal-module-card--planned"
                >
                  <CardHeader>
                    <CardAction>
                      <Icon aria-hidden="true" />
                    </CardAction>
                    <CardTitle level={3}>{module.label}</CardTitle>
                    <CardDescription>{module.description}</CardDescription>
                    <Badge variant="outline" className="mt-3 w-fit">
                      Planned
                    </Badge>
                  </CardHeader>
                </Card>
              )
            })}
          </div>
        </section>
      )}

      {isStudent && (
        <>
          {studentQueueQuery.isPending ? (
            <Alert>
              <AlertTitle>Loading queue status</AlertTitle>
              <AlertDescription>Loading your Cashier queue…</AlertDescription>
            </Alert>
          ) : studentQueueQuery.isError ? (
            <Alert variant="destructive">
              <AlertTitle>Queue status unavailable</AlertTitle>
              <AlertDescription>
                Your live Cashier queue could not be loaded. Try again to
                refresh it.
              </AlertDescription>
              <Button
                type="button"
                variant="outline"
                onClick={() => void studentQueueQuery.refetch()}
              >
                Retry queue status
              </Button>
            </Alert>
          ) : studentQueueQuery.data &&
            studentQueueQuery.data.stage !== "enrolled" ? (
            <StudentQueueLivePanel
              queue={studentQueueQuery.data}
              mode="compact"
            />
          ) : null}
        </>
      )}

      <section className="portal-status-grid" aria-label="GRC Connect status">
        <Card>
          <CardHeader>
            <CardAction>
              <CalendarDays aria-hidden="true" />
            </CardAction>
            <CardTitle>Academic term</CardTitle>
            <CardDescription>
              Your enrollment work is organized around the active GRC term.
            </CardDescription>
          </CardHeader>
          <CardContent>
            {academicTermsQuery.isPending && <p>Loading academic term…</p>}
            {academicTermsQuery.isError && <p>Academic term unavailable</p>}
            {activeAcademicTerm && (
              <Badge variant="outline">
                {formatAcademicTerm(activeAcademicTerm)}
              </Badge>
            )}
            {academicTermsQuery.data && !activeAcademicTerm && (
              <Badge variant="outline">No active academic term</Badge>
            )}
            {isStudent && (
              <div className="mt-3">
                <EnrollmentAvailabilityBanner
                  viewer={scheduleQuery.data?.viewer}
                />
              </div>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardAction>
              {healthQuery.isPending ? (
                <CircleDashed aria-hidden="true" />
              ) : healthQuery.isError ? (
                <ServerCrash aria-hidden="true" />
              ) : (
                <CircleCheck aria-hidden="true" />
              )}
            </CardAction>
            <CardTitle>System availability</CardTitle>
            <CardDescription>
              A quick connection check for the enrollment portal.
            </CardDescription>
          </CardHeader>
          <CardContent aria-live="polite">
            {healthQuery.isPending && <p>Checking system availability…</p>}
            {healthQuery.isError && <p>System availability unavailable</p>}
            {healthQuery.data && <p>Enrollment portal available</p>}
          </CardContent>
        </Card>
      </section>
    </main>
  )
}

/** @deprecated Use GrcConnectPage. Kept for existing callers during the UI transition. */
export const PortalOverviewPage = GrcConnectPage
