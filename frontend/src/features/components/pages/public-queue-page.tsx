"use client"

import { useSearchParams } from "next/navigation"

import { StudentQueueLivePanel } from "@/features/components/queue/student-queue-live-panel"
import {
  Alert,
  AlertDescription,
  AlertTitle,
} from "@/features/components/ui/alert"
import { Skeleton } from "@/features/components/ui/skeleton"
import {
  isUnknownLinkError,
  usePublicQueueQuery,
} from "@/features/hooks/use-public-queue"

/**
 * The page behind a freshman's emailed "view the live queue" link (ADR 0044):
 * `/queue/live?t=<token>`. The freshman has no account yet, so nothing here is
 * signed in — the token in the address is the credential, and the page shows only
 * their own queue number and the ticket numbers the Cashier is calling. It is
 * deliberately outside the portal's auth provider (see `providers.tsx`).
 */
export function PublicQueuePage() {
  const token = useSearchParams().get("t")
  const query = usePublicQueueQuery(token)

  return (
    <main className="mx-auto flex min-h-svh w-full max-w-2xl flex-col gap-4 px-4 py-8">
      <header className="grid gap-1">
        <p className="text-sm font-medium text-muted-foreground">
          Global Reciprocal Colleges
        </p>
        <h1 className="text-2xl font-bold tracking-tight">Cashier queue</h1>
        <p className="text-sm text-muted-foreground">
          Your queue number and the number being served right now. This page
          refreshes by itself; keep it open while you wait. You do not need to
          sign in.
        </p>
      </header>

      <PublicQueueBody token={token} query={query} />
    </main>
  )
}

function PublicQueueBody({
  token,
  query,
}: {
  token: string | null
  query: ReturnType<typeof usePublicQueueQuery>
}) {
  if (token === null || token === "") {
    return (
      <Alert variant="destructive">
        <AlertTitle>This link is incomplete</AlertTitle>
        <AlertDescription>
          Open the link from your queue-number email again. If you cannot find
          it, ask Admission to send it to you.
        </AlertDescription>
      </Alert>
    )
  }

  if (query.data) {
    return <StudentQueueLivePanel queue={query.data} mode="default" />
  }

  if (isUnknownLinkError(query.error)) {
    return (
      <Alert variant="destructive">
        <AlertTitle>This link is no longer valid</AlertTitle>
        <AlertDescription>
          It may have been replaced by a newer email, or it was typed
          incorrectly. Use the link in your most recent queue-number email, or
          ask Admission to send it again.
        </AlertDescription>
      </Alert>
    )
  }

  if (query.isError) {
    return (
      <Alert>
        <AlertTitle>The live queue is temporarily unavailable</AlertTitle>
        <AlertDescription>
          We could not reach the server just now. This page keeps trying by
          itself. Your place in the line is not affected.
        </AlertDescription>
      </Alert>
    )
  }

  return <Skeleton className="h-64" aria-label="Loading the live queue" />
}
