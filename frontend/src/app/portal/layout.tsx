import { Suspense, type ReactNode } from "react"

import { RequireSession } from "@/features/auth/auth-route-guards"
import { PortalShell } from "@/features/components/layouts/portal-shell"
import { GrcLoadingLogo } from "@/features/components/portal/grc-loading-logo"

// `RequireSession` reads `useSearchParams()` to build the `?returnTo=` value,
// which Next requires be wrapped in a Suspense boundary. The fallback is the
// branded full-page loader (ADR 0029), not a blank screen, so a slow first load
// never looks like a dead page.
export default function PortalLayout({ children }: { children: ReactNode }) {
  return (
    <Suspense
      fallback={<GrcLoadingLogo fullPage label="Loading GRC Connect…" />}
    >
      <RequireSession>
        <PortalShell>{children}</PortalShell>
      </RequireSession>
    </Suspense>
  )
}
