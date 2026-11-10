"use client"

import {
  CircleCheck,
  LockKeyhole,
  Monitor,
  RefreshCw,
  Server,
  ShieldCheck,
  WifiOff,
} from "lucide-react"

import { ServiceBoundaryCard } from "@/features/components/common/service-boundary-card"
import {
  Alert,
  AlertDescription,
  AlertTitle,
} from "@/features/components/ui/alert"
import { Button } from "@/features/components/ui/button"
import { Skeleton } from "@/features/components/ui/skeleton"
import { useHealthQuery } from "@/features/hooks/use-health-query"
import { getStatePresentation } from "@/features/lib/api-error-presentation"
import { cn } from "@/features/lib/utils"
import { PUBLIC_API_HEALTH_PATH } from "@/features/services/health-service"
import type { ServiceHealth } from "@/features/types/health"

function formatGeneratedAt(value: string): string {
  return new Intl.DateTimeFormat("en-PH", {
    dateStyle: "medium",
    timeStyle: "medium",
    timeZone: "Asia/Manila",
  }).format(new Date(value))
}

function ApiHealthContent({
  error,
  health,
  isPending,
}: {
  error: unknown
  health?: ServiceHealth
  isPending: boolean
}) {
  if (isPending) {
    return (
      <div className="flex flex-col gap-3" role="status" aria-live="polite">
        <span>Contacting the public gateway…</span>
        <div className="flex flex-col gap-2" aria-hidden="true">
          <Skeleton className="h-4 w-2/3" />
          <Skeleton className="h-4 w-full" />
          <Skeleton className="h-4 w-1/2" />
        </div>
      </div>
    )
  }

  if (!health) {
    const presentation = getStatePresentation(error)

    return (
      <Alert variant="destructive">
        <WifiOff aria-hidden="true" />
        <AlertTitle>{presentation.title}</AlertTitle>
        <AlertDescription>{presentation.message}</AlertDescription>
      </Alert>
    )
  }

  return (
    <div className="flex flex-col gap-4">
      <div className="health-confirmation" role="status" aria-live="polite">
        <CircleCheck aria-hidden="true" />
        <span>Public gateway responding</span>
      </div>
      <dl className="health-ledger">
        <div>
          <dt>Service</dt>
          <dd>{health.service}</dd>
        </div>
        <div>
          <dt>Contract</dt>
          <dd>{health.api_version}</dd>
        </div>
        <div>
          <dt>API report</dt>
          <dd>
            <time dateTime={health.generated_at}>
              {formatGeneratedAt(health.generated_at)}
            </time>
          </dd>
        </div>
      </dl>
    </div>
  )
}

export function PublicApiReadiness() {
  const {
    data: health,
    error,
    isError,
    isFetching,
    isPending,
    refetch,
  } = useHealthQuery()

  const apiBadge = isPending
    ? "Checking"
    : isError
      ? "Unavailable"
      : "API online"
  const apiBadgeVariant = isPending
    ? "secondary"
    : isError
      ? "destructive"
      : "default"
  const statusLine = isPending
    ? "Checking the versioned public API."
    : isError
      ? "The public API needs attention."
      : isFetching
        ? "Refreshing the latest API report."
        : "The public API contract is responding."

  return (
    <section
      id="system-readiness"
      className="boundary-section public-readiness"
      aria-labelledby="system-readiness-title"
    >
      <div className="section-heading">
        <div>
          <p className="eyebrow">
            <ShieldCheck aria-hidden="true" />
            Public service boundary
          </p>
          <h2 id="system-readiness-title">System readiness</h2>
        </div>
        <div className="readiness-intro">
          <p>
            This check verifies one public Laravel route. It does not infer
            database, authentication, or prediction readiness.
          </p>
          <Button
            type="button"
            variant={isError ? "default" : "outline"}
            disabled={isFetching}
            onClick={() => void refetch()}
          >
            <RefreshCw
              data-icon="inline-start"
              className={cn(isFetching && "animate-spin")}
              aria-hidden="true"
            />
            {isFetching
              ? "Checking…"
              : isError
                ? "Retry API check"
                : "Check again"}
          </Button>
          <p className="hero-status" aria-live="polite">
            {statusLine}
          </p>
        </div>
      </div>

      <div className="boundary-grid">
        <ServiceBoundaryCard
          index="01"
          icon={Monitor}
          title="Web interface"
          description="A strict-TypeScript shell for role-guided enrollment journeys."
          badge="Interface ready"
          badgeVariant="secondary"
          footer={<span>No private student data loaded</span>}
        >
          <p className="boundary-card__copy">
            The browser is independently runnable and contacts only the public
            Laravel origin.
          </p>
        </ServiceBoundaryCard>

        <ServiceBoundaryCard
          index="02"
          icon={Server}
          title="Public API"
          description="The versioned Laravel boundary for browser-facing data."
          badge={apiBadge}
          badgeVariant={apiBadgeVariant}
          footer={<code>GET {PUBLIC_API_HEALTH_PATH}</code>}
        >
          <ApiHealthContent
            error={error}
            health={health}
            isPending={isPending}
          />
        </ServiceBoundaryCard>

        <ServiceBoundaryCard
          index="03"
          icon={LockKeyhole}
          title="Predictive service"
          description="A private advisory boundary reached by Laravel—not by this browser."
          badge="Server only"
          badgeVariant="outline"
          footer={<span>No browser request is made</span>}
        >
          <p className="boundary-card__copy">
            Demand and attrition outputs remain decision support. They never
            become automatic enrollment denials.
          </p>
        </ServiceBoundaryCard>
      </div>
    </section>
  )
}
